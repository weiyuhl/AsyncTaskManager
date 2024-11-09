
<p align="center">
  <a href="README.md">English Version</a>
</p>

# 异步任务管理器使用文档

## 概述

AsyncTaskManager 是一个用于管理和执行异步任务的框架，支持并发任务控制、任务重试、任务超时处理、任务依赖管理、日志记录及任务取消功能。它通过事件循环和信号量机制来高效地执行任务，并在任务失败时提供自动重试机制，适用于需要高并发和任务调度的场景。

## 特性

*  任务队列管理：任务按优先级插入队列，支持设置任务依赖。

*  并发控制：通过信号量确保系统不会超负荷运行，限制最大并发任务数。

* 任务重试：失败的任务支持重试，重试时采用指数回退策略。

* 任务超时管理：任务超时后会自动标记为失败。

* 任务依赖：可以设置任务之间的依赖关系，确保依赖任务完成后再执行。

* 优雅关闭：提供优雅关闭功能，确保所有任务完成后再退出。

* 日志记录：提供详细的任务执行日志，包括任务执行状态、错误信息、重试次数等。


# 安装

## 安装依赖

确保您的开发环境中安装了以下依赖：

* PHP >= 7.4

* ReactPHP：用于事件循环管理。

* Monolog：用于日志记录。


# 使用 Composer 安装：

``` bash
composer require react/event-loop monolog/monolog
```

# 配置

AsyncTaskManager 可以通过配置数组进行自定义设置，以下是支持的配置项：

* maxConcurrentTasks：最大并发任务数，默认值为 5。

* maxRetries：任务最大重试次数，默认值为 3。

* enableRetry：是否启用重试机制，默认值为 true。

* logLevel：日志级别，支持 DEBUG、INFO、ERROR，默认值为 INFO。


# 配置示例：

``` php
$config = [
    'maxConcurrentTasks' => 10,
    'maxRetries' => 5,
    'enableRetry' => true,
    'logLevel' => 'DEBUG',
];

$taskManager = new AsyncTaskManager($config);
```

# 使用方法

## 1. 添加任务

使用 addTask() 方法可以向任务队列中添加任务。该方法支持以下参数：

* task：任务的回调函数。

* priority：任务优先级，默认值为 0，数值越大优先级越高。

* timeout：任务的超时时间（秒），可选。

* taskType：任务类型，支持 normal 和 critical，critical 类型任务具有更高优先级。

* dependsOn：任务依赖的其他任务 ID，只有依赖的任务执行完毕后，当前任务才能执行。


### 示例：

``` php
$taskManager->addTask(function () {
    echo "Task 1 executed\n";
}, 1);

$taskManager->addTask(function () {
    echo "Task 2 executed\n";
}, 0, null, 'critical');
```

## 2. 启动任务管理器

通过调用 run() 方法启动任务管理器，开始执行任务队列中的任务。

``` php
$taskManager->run();
```

## 3. 获取任务状态

使用 getTaskStatus() 方法可以查看任务的当前状态。任务的状态包括：Pending、Running、Completed、Failed、Cancelled。

``` php
$status = $taskManager->getTaskStatus('task_12345');
echo "任务状态: " . $status['status'];
```

## 4. 取消任务

通过 cancelTask() 方法可以取消一个任务。取消后任务将不会再执行，并会标记为 Cancelled。

``` php
$taskManager->cancelTask('task_12345');
```

## 5. 优雅关闭

调用 shutDown() 方法将开始优雅关闭任务管理器。该方法会等待所有正在运行的任务完成后再停止事件循环。

``` php
$taskManager->shutDown();
```

## 6. 动态调整并发数

任务管理器支持动态调整并发任务数。通过 adjustSemaphore() 方法可以调整当前的并发任务数。

``` php
$taskManager->adjustSemaphore();
```

## 7. 错误处理与重试

如果任务执行失败，且启用了重试功能，任务会根据设定的重试次数进行自动重试。重试过程中会增加回退时间，避免任务集中重试。失败类型包括：

* TimeoutException：任务超时。

* NetworkException：网络异常。

* DatabaseException：数据库异常。

* GeneralException：其他类型的异常。


当任务最终失败时，错误信息会被记录到日志中。

## #错误与日志

任务执行过程中，如果发生错误或任务超时，系统会自动进行错误处理，并根据配置进行重试。每次失败的信息会详细记录在日志中。日志会包含以下信息：

* 错误类型

* 错误信息

* 当前任务的重试次数

* 超时任务会被记录为失败


此外，失败的任务也会被保存在 /tmp/failed_tasks.json 文件中，方便后续查看和处理。

## 配置日志

日志功能是由 Monolog 提供的，可以通过配置不同的日志级别、日志文件路径来进行调整。例如：

``` php
$this->logger->pushHandler(new RotatingFileHandler('/path/to/log/async_task.log', 10, Logger::DEBUG));
```

* RotatingFileHandler：用于日志轮转，最大文件数为 10。

* 日志级别：支持 DEBUG、INFO、ERROR 等级。


# 高级功能

## 任务依赖

AsyncTaskManager 支持任务依赖管理。任务可以依赖其他任务，只有依赖的任务完成后，当前任务才能执行。这对于一些具有顺序执行要求的任务非常有用。

## 动态并发数调整

AsyncTaskManager 会根据当前任务的数量和系统负载动态调整最大并发数。可以通过 adjustSemaphore() 方法来手动触发并发数调整。

##  清理过期任务

每 30 秒，任务管理器会检查并清理超时且处于 Pending 或 Failed 状态的任务。超时的任务会被删除，避免浪费系统资源。

## 信号量与并发控制

信号量机制确保了不会超过系统设置的最大并发任务数。在任务执行过程中，信号量会被用来控制并发数，避免任务过多导致系统资源耗尽。

# 总结

AsyncTaskManager 提供了一个灵活的异步任务管理解决方案，能够帮助开发者高效地管理任务的执行、处理失败重试、任务依赖、超时等情况，适用于高并发和复杂任务的调度。通过本框架，您可以轻松地管理大规模的异步任务，提高系统的可维护性和可靠性。

### 希望本使用文档能帮助您快速上手并充分利用 AsyncTaskManager 的功能。
