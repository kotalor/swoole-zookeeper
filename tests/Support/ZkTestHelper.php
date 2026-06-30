<?php

declare(strict_types=1);

namespace Swoole\Zookeeper\Tests\Support;

use Swoole\Zookeeper\Connection;
use Swoole\Zookeeper\Zookeeper;

final class ZkTestHelper
{
    public static function getTestHost(): string
    {
        return getenv('ZK_TEST_HOST') ?: '127.0.0.1:2181';
    }

    public static function isZkAvailable(?string $host = null): bool
    {
        $host = $host ?? self::getTestHost();
        $parts = explode(':', $host);
        $hostname = $parts[0];
        $port = isset($parts[1]) ? (int) $parts[1] : 2181;

        $socket = @fsockopen($hostname, $port, $errno, $errstr, 1.0);
        if ($socket === false) {
            return false;
        }
        fclose($socket);
        return true;
    }

    public static function getConnection(Zookeeper $zk): Connection
    {
        return ReflectionHelper::getProperty($zk, 'connection');
    }

    /**
     * 强制关闭底层 TCP 连接以模拟断线
     */
    public static function forceDisconnect(Zookeeper $zk): void
    {
        $connection = self::getConnection($zk);
        $client = ReflectionHelper::getProperty($connection, 'client');
        if ($client !== null) {
            $client->close();
        }
    }
}
