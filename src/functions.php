<?php

declare(strict_types=1);

namespace Swoole\Zookeeper;

/**
 * 创建Zookeeper实例的便捷函数
 */
function zookeeper(string $host = '', ?callable $watcher = null, int $timeout = 10000): Zookeeper
{
    return new Zookeeper($host, $watcher, $timeout);
}

