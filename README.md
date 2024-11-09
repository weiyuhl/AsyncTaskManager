
<p align="center">
  <a href="READMEzh-CN.md">中文版</a>
</p>

# Async Task Manager Documentation

## Overview

The AsyncTaskManager is a framework for managing and executing asynchronous tasks. It supports concurrent task control, task retries, task timeout management, task dependency handling, logging, and task cancellation. Using an event loop and semaphore mechanism, it efficiently executes tasks while providing automatic retries on failure and allowing fine-grained control over task execution. This is ideal for scenarios requiring high concurrency and task scheduling.

## Features

* Task Queue Management: Tasks are inserted into the queue with priorities and can have dependencies.

* Concurrency Control: Semaphore ensures the system doesn’t run too many tasks concurrently, enforcing a maximum number of tasks running at the same time.

* Task Retries: Failed tasks can be retried, with exponential backoff.

* Task Timeout Management: Tasks are marked as failed if they exceed the specified timeout.

* Task Dependencies: Tasks can be dependent on the completion of other tasks before they are executed.

* Graceful Shutdown: The system shuts down only once all tasks are completed.

* Logging: Provides detailed logs of task execution, errors, retries, etc.


# Installation

## Dependencies

Ensure your development environment meets the following requirements:

* PHP >= 7.4

* ReactPHP for event loop management.

* Monolog for logging.


# Install the dependencies via Composer:

``` bash
composer require react/event-loop monolog/monolog
```

# Configuration

The AsyncTaskManager can be customized through a configuration array. Below are the available configuration options:

* maxConcurrentTasks: Maximum number of concurrent tasks, default value is 5.

* maxRetries: Maximum retry attempts for a failed task, default value is 3.

* enableRetry: Whether task retries are enabled, default value is true.

* logLevel: Log level, options are DEBUG, INFO, and ERROR, default value is INFO.


# Example Configuration:

``` php
$config = [
    'maxConcurrentTasks' => 10,
    'maxRetries' => 5,
    'enableRetry' => true,
    'logLevel' => 'DEBUG',
];

$taskManager = new AsyncTaskManager($config);
```

# Usage

## 1. Adding Tasks

You can add tasks to the task queue using the addTask() method. The method accepts the following parameters:

* task: A callback function for the task.

* priority: Task priority, default is 0, higher values mean higher priority.

* timeout: The task's timeout (in seconds), optional.

* taskType: The type of the task, either normal or critical. Critical tasks have higher priority.

* dependsOn: An array of task IDs that this task depends on. The task will not execute until all dependent tasks are completed.


### Example:

``` php
$taskManager->addTask(function () {
    echo "Task 1 executed\n";
}, 1);

$taskManager->addTask(function () {
    echo "Task 2 executed\n";
}, 0, null, 'critical');
```

## 2. Running the Task Manager

Start the task manager by calling the run() method. This will begin executing tasks from the queue.

``` php
$taskManager->run();
```

## 3. Get Task Status

You can check the status of a task using the getTaskStatus() method. The task's status can be one of the following: Pending, Running, Completed, Failed, Cancelled.

``` php
$status = $taskManager->getTaskStatus('task_12345');
echo "Task status: " . $status['status'];
```

## 4. Cancel a Task

To cancel a task, use the cancelTask() method. This will prevent the task from running, and its status will be marked as Cancelled.

``` php
$taskManager->cancelTask('task_12345');
```

## 5. Graceful Shutdown

The shutDown() method allows for graceful shutdown of the task manager. It ensures that all tasks are completed before the system stops.

``` php
$taskManager->shutDown();
``` 

## 6. Dynamically Adjust Concurrency

The task manager supports dynamic adjustment of concurrency based on the current task load and system conditions. The adjustSemaphore() method can be used to adjust the concurrency settings.

``` php
$taskManager->adjustSemaphore();
```

## 7. Error Handling and Retries

If a task fails, and retries are enabled, the task will be retried according to the configured retry count. The retry process uses an exponential backoff strategy. The failure types include:

* TimeoutException: Task exceeded its timeout.

* NetworkException: Network error occurred.

* DatabaseException: Database error occurred.

* GeneralException: Any other exceptions.


If the task ultimately fails after the maximum retries, the error will be logged and the task will be marked as failed.

## Error and Logging

If a task fails or times out, the system will handle the error accordingly, either by retrying the task or logging the failure. The errors are logged with the following details:

* Error type

* Error message

* The task's retry count

* Timeout tasks are logged as failed


Failed tasks are also stored in a file (/tmp/failed_tasks.json) for further inspection.

## Configuring Logs

The logging functionality is provided by Monolog. You can configure the log level and log file path as needed. For example:

``` php
$this->logger->pushHandler(new RotatingFileHandler('/path/to/log/async_task.log', 10, Logger::DEBUG));
```

* RotatingFileHandler: Handles log rotation, keeping up to 10 files.

* Log Levels: DEBUG, INFO, ERROR etc.


# Advanced Features

## Task Dependencies

AsyncTaskManager supports task dependencies. A task can depend on the completion of other tasks. It will only execute once all its dependent tasks are completed. This is useful for tasks that need to run in sequence.

## Dynamic Concurrency Adjustment

The task manager dynamically adjusts the maximum concurrency based on the number of active tasks and system load. The adjustSemaphore() method allows you to trigger this adjustment.

## Expired Task Cleanup

The task manager periodically checks for tasks that have exceeded a timeout threshold. Tasks that are in the Pending or Failed states for over an hour will be removed from the task list.

## Semaphore and Concurrency Control

The semaphore ensures that the system does not exceed the maximum allowed concurrent tasks. It releases the task slots as tasks complete, allowing new tasks to start.

# Summary

AsyncTaskManager provides a flexible and powerful solution for managing asynchronous tasks. It enables efficient task scheduling, retry mechanisms, task dependencies, and more. This framework is well-suited for high-concurrency environments and complex task workflows.

### This documentation should help you get started with AsyncTaskManager and leverage its features to effectively manage your asynchronous tasks.
