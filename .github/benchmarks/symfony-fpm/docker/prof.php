<?php

declare(strict_types=1);

// Samples every FPM request with Excimer and appends collapsed stacks to /prof/<pid>.collapsed
if (PHP_SAPI === 'fpm-fcgi' && getenv('PROFILE') === '1') {
    $GLOBALS['__bench_profiler'] = new ExcimerProfiler();
    $GLOBALS['__bench_profiler']->setPeriod((float) (getenv('PROFILE_PERIOD') ?: 0.00005));
    $GLOBALS['__bench_profiler']->setEventType(EXCIMER_REAL);
    $GLOBALS['__bench_profiler']->start();

    register_shutdown_function(static function (): void {
        // Nested registration so it runs after shutdown functions registered during the request (e.g. the SDK's)
        register_shutdown_function(static function (): void {
            $profiler = $GLOBALS['__bench_profiler'];
            $profiler->stop();
            file_put_contents('/prof/' . getmypid() . '.collapsed', $profiler->getLog()->formatCollapsed(), FILE_APPEND);
        });
    });
}
