?php
class AsyncTaskManager
{
    private $loop;
    private $taskQueue;
    private $taskStates = [];
    private $maxConcurrentTasks;
    private $logger;
    private $maxRetries;
    private $enableRetry;
    private $semaphore;
    private $logLevel;
    private $currentTasks = 0;  // Track the number of tasks currently running
    private $failedTaskQueue = [];
    private $taskCancelQueue = [];
    private $isShuttingDown = false; // Whether the system is shutting down gracefully

    public function __construct(array $config = [])
    {
        $this->loop = Factory::create();
        $this->taskQueue = new SplPriorityQueue();
        $this->semaphore = new Semaphore($config['maxConcurrentTasks'] ?? 5);
        $this->maxConcurrentTasks = $config['maxConcurrentTasks'] ?? 5;
        $this->maxRetries = $config['maxRetries'] ?? 3;
        $this->enableRetry = $config['enableRetry'] ?? true;
        $this->logLevel = $config['logLevel'] ?? 'INFO';

        // Configure the logger
        $this->logger = new Logger('AsyncTaskManager');
        $this->logger->pushHandler(new RotatingFileHandler('/tmp/async_task_log.txt', 10, Logger::DEBUG));
    }

    public function addTask(callable $task, int $priority = 0, ?int $timeout = null, string $taskType = 'normal', array $dependsOn = [])
    {
        $taskId = uniqid('task_', true);
        $taskData = [
            'id' => $taskId,
            'task' => $task,
            'priority' => $priority,
            'timeout' => $timeout,
            'status' => 'Pending',
            'retries' => 0,
            'backoff' => 1,
            'taskType' => $taskType,
            'startTime' => microtime(true),
            'cancel' => false,
            'dependsOn' => $dependsOn,  // Record dependent tasks
        ];

        if ($taskType === 'critical') {
            $taskData['priority'] += 100;  // Prioritize critical tasks
        }

        $this->taskQueue->insert($taskData, $taskData['priority']);
    }

    public function run()
    {
        $this->log("Task manager started", 'INFO');

        // Run the task queue every 0.1 second
        $this->loop->addPeriodicTimer(0.1, function () {
            if ($this->isShuttingDown) return;

            while (!$this->taskQueue->isEmpty() && $this->currentTasks < $this->maxConcurrentTasks) {
                // Get the next task
                $taskData = $this->taskQueue->extract();

                // Check if the task has dependencies that are not completed
                if ($taskData['dependsOn']) {
                    // If the task has dependencies, check if all dependent tasks are completed
                    $allDependenciesCompleted = true;
                    foreach ($taskData['dependsOn'] as $depId) {
                        if (!isset($this->taskStates[$depId]) || $this->taskStates[$depId]['status'] !== 'Completed') {
                            $allDependenciesCompleted = false;
                            break;
                        }
                    }

                    if (!$allDependenciesCompleted) {
                        // If dependencies are not completed, re-insert the task into the queue
                        $this->taskQueue->insert($taskData, $taskData['priority']);
                        continue;  // Skip this task, wait for dependencies to finish
                    }
                }

                // Check if the task is canceled
                if ($taskData['cancel']) {
                    $taskData['status'] = 'Cancelled';
                    $this->log("Task cancelled: {$taskData['id']}", 'INFO');
                    continue; // Skip this task
                }

                // Acquire semaphore (ensure the maximum concurrent tasks limit is not exceeded)
                $this->semaphore->acquire()->then(function () use ($taskData) {
                    $this->executeTask($taskData);
                });
            }
        });

        // Clean up expired tasks every 30 seconds
        $this->loop->addPeriodicTimer(30, function () {
            $this->cleanExpiredTasks();
        });

        // Dynamically adjust the semaphore every 60 seconds
        $this->loop->addPeriodicTimer(60, function () {
            $this->adjustSemaphore();
        });

        // Monitor shutdown tasks
        $this->loop->addPeriodicTimer(1, function () {
            if ($this->isShuttingDown && $this->currentTasks === 0) {
                $this->log("All tasks completed, system has shut down gracefully", 'INFO');
                $this->loop->stop();
            }
        });

        $this->loop->run();
    }

    private function executeTask(array &$taskData)
    {
        $deferred = new Deferred();
        $this->currentTasks++;
        $taskData['status'] = 'Running';

        // Start task execution
        $this->loop->futureTick(function () use (&$taskData, $deferred) {
            try {
                // Check if the task is canceled
                if ($taskData['cancel']) {
                    $taskData['status'] = 'Cancelled';
                    $this->log("Task cancelled: {$taskData['id']}", 'INFO');
                    $deferred->reject('Task cancelled');
                    return;
                }

                // Check for task timeout
                if ($taskData['timeout'] && (microtime(true) - $taskData['startTime']) > $taskData['timeout']) {
                    throw new TimeoutException("Task timeout");
                }

                // Execute the task
                $result = $taskData['task']();
                $taskData['status'] = 'Completed';
                $this->log("Task completed successfully, result: {$result}", 'INFO');
                $deferred->resolve($result);
            } catch (TimeoutException $e) {
                $taskData['status'] = 'Failed';
                $this->handleTaskError($taskData, $deferred, $e, 'Timeout');
            } catch (NetworkException $e) {
                $taskData['status'] = 'Failed';
                $this->handleTaskError($taskData, $deferred, $e, 'Network Error');
            } catch (DatabaseException $e) {
                $taskData['status'] = 'Failed';
                $this->handleTaskError($taskData, $deferred, $e, 'Database Error');
            } catch (\Exception $e) {
                $taskData['status'] = 'Failed';
                $this->handleTaskError($taskData, $deferred, $e, 'General Error');
            } finally {
                // Release semaphore after task is complete
                $this->semaphore->release();
            }
        });

        $deferred->promise()->then(
            function ($result) use (&$taskData) {
                $this->currentTasks--;
                $this->taskStates[$taskData['id']] = $taskData;
            },
            function ($error) use (&$taskData) {
                $this->currentTasks--;
                $this->taskStates[$taskData['id']] = $taskData;
            }
        );
    }

    private function handleTaskError(array &$taskData, Deferred $deferred, \Exception $e, string $errorType)
    {
        if ($this->enableRetry && $taskData['retries'] < $this->maxRetries) {
            $taskData['retries']++;
            $taskData['status'] = 'Retrying';
            $taskData['backoff'] *= 2;  // Exponential backoff
            $this->log("Task failed, retry attempt {$taskData['retries']} of {$errorType}, error: {$e->getMessage()}", 'ERROR');

            // Randomize backoff time to avoid retry storms
            $randomBackoff = mt_rand($taskData['backoff'], $taskData['backoff'] * 2);

            // Set retry interval based on backoff
            $this->loop->addTimer($randomBackoff, function () use ($taskData) {
                $this->taskQueue->insert($taskData, $taskData['priority']);
            });
        } else {
            $taskData['status'] = 'Failed';
            $deferred->reject($e->getMessage());
            $this->log("Task failed after retries, error: {$errorType}, message: {$e->getMessage()}", 'ERROR');
            $this->storeFailedTask($taskData);
        }
    }

    private function storeFailedTask(array $taskData)
    {
        // Store failed task to a file
        file_put_contents('/tmp/failed_tasks.json', json_encode($taskData) . PHP_EOL, FILE_APPEND);
    }

    private function cleanExpiredTasks()
    {
        foreach ($this->taskStates as $taskId => $taskData) {
            // Clean up expired tasks, including Pending and Failed tasks
            if ((microtime(true) - $taskData['startTime'] > 3600) && ($taskData['status'] === 'Pending' || $taskData['status'] === 'Failed')) {
                $this->log("Task expired and cleaned up: {$taskData['id']}", 'ERROR');
                unset($this->taskStates[$taskId]);
            }
        }
    }

    private function adjustSemaphore()
    {
        // Dynamically adjust concurrency based on current task count and system load
        if ($this->currentTasks < $this->maxConcurrentTasks) {
            $this->semaphore->setMaxConcurrency($this->maxConcurrentTasks + 1);  // Example: dynamically increase concurrency
            $this->log("Semaphore concurrency has been dynamically adjusted", 'INFO');
        }
    }

    private function log(string $message, string $level = 'INFO')
    {
        if ($this->logLevel === 'DEBUG' || $level === 'ERROR' || $this->logLevel === $level) {
            $formattedMessage = "[" . date('Y-m-d H:i:s') . "] [$level] $message";
            $this->logger->log($level, $formattedMessage);
        }
    }

    public function getTaskStatus(string $taskId)
    {
        return $this->taskStates[$taskId] ?? null;
    }

    // Provide an interface to cancel tasks
    public function cancelTask(string $taskId)
    {
        if (isset($this->taskStates[$taskId])) {
            $this->taskStates[$taskId]['cancel'] = true;
            $this->log("Task cancelled: {$taskId}", 'INFO');
        } else {
            $this->log("Attempt to cancel non-existing task: {$taskId}", 'ERROR');
        }
    }

    // Provide an interface for graceful shutdown
    public function shutDown()
    {
        $this->isShuttingDown = true;
        $this->log("Task manager is shutting down...", 'INFO');
        // Wait until all tasks are finished before shutting down
        $this->loop->addPeriodicTimer(1, function () {
            if ($this->currentTasks === 0) {
                $this->log("All tasks completed, task manager has shut down", 'INFO');
                $this->loop->stop();
            }
        });
    }
          }
