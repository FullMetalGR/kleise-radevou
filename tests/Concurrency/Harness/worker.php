<?php

declare(strict_types=1);

/*
 * The script a harness worker process runs. Everything happens in WorkerRuntime; this file
 * exists because a process needs a file to start from.
 */

use Tests\Concurrency\Harness\WorkerRuntime;

$basePath = dirname(__DIR__, 3);

require $basePath.'/vendor/autoload.php';

exit(WorkerRuntime::main($basePath));
