<?php

declare(strict_types=1);

require dirname(__DIR__) . '/ShygoHomeCache/Cache.php';

use TypechoPlugin\ShygoHomeCache\Cache;

if (!isset($argv[1]) || !is_string($argv[1]) || $argv[1] === '') {
    fwrite(STDERR, "missing dir\n");
    exit(2);
}

$cache = Cache::openDisk($argv[1]);
echo $cache->acquireLock(5) ? "locked\n" : "busy\n";