<?php

declare(strict_types=1);

use Docuccino\Core\Pipeline\BuildWorkers;

// Loaded by Composer's autoloader before anything else runs, so this is the first shutdown function a process
// has, and a forked worker ends here however it dies, before any shutdown work it inherited (BuildWorkers).
// Only on the command line, where a worker can be forked at all, and it never loads the class to find out.
// Nor does it call into it from the process that loaded this, which is no worker: a method's first call takes
// memory, a process that ran out has none, and that second fatal would stop every shutdown function after this.
if (PHP_SAPI === 'cli' && function_exists('pcntl_fork')) {
    $loadedBy = getmypid();
    register_shutdown_function(static function () use ($loadedBy): void {
        if (getmypid() !== $loadedBy && class_exists(BuildWorkers::class, false)) {
            BuildWorkers::shutdown();
        }
    });
}
