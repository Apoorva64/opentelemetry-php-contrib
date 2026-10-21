<?php

declare(strict_types=1);

/*
 * Test HTTP server used by the reproduction scripts.
 *
 *   PHP_CLI_SERVER_WORKERS=8 php -S 127.0.0.1:8765 server.php
 *
 * Every request is logged with its arrival time, so scripts can tell when bytes
 * actually reached the server (as opposed to when request() was called).
 */

$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
file_put_contents(
    sys_get_temp_dir() . '/symfony-httpclient-repro-server.log',
    sprintf("%.6f arrived %s\n", microtime(true), $path),
    FILE_APPEND | LOCK_EX,
);

switch ($path) {
    case '/fast':
        echo 'ok';

        break;
    case '/redirect':
        header('Location: /fast', true, 302);

        break;
    case '/500':
        http_response_code(500);
        echo 'boom';

        break;
    case '/slow-body':
        // Headers + first half immediately, second half after 1 s.
        header('Content-Length: 10');
        echo 'hello';
        flush();
        sleep(1);
        echo 'world';

        break;
    case '/broken-body':
        // Announces 100 bytes, sends 7, then closes the connection.
        header('Content-Length: 100');
        echo 'partial';
        flush();

        exit;
    default:
        http_response_code(404);
        echo 'not found';
}
