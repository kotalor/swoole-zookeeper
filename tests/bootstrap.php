<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

if (!extension_loaded('swoole')) {
    fwrite(STDERR, "The Swoole extension is required to run tests.\n");
    exit(1);
}
