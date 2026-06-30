<?php

declare(strict_types=1);

namespace Swoole\Zookeeper\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Swoole\Zookeeper\Connection;
use Swoole\Zookeeper\Tests\Support\ReflectionHelper;

/**
 * replayWatches 的轻量单元测试。
 * SET_WATCHES 包格式由 SetWatchesRequestTest 覆盖；端到端重放由集成测试覆盖。
 */
class ReplayWatchesTest extends TestCase
{
    public function testReplayWatchesSkipsWhenNoLocalWatches(): void
    {
        $connection = new Connection('127.0.0.1:2181', 5000, 10000);

        ReflectionHelper::invoke($connection, 'replayWatches');

        $this->assertNull(ReflectionHelper::getProperty($connection, 'client'));
    }

    public function testReplayWatchesToleratesDisconnectedClient(): void
    {
        $connection = new Connection('127.0.0.1:2181', 5000, 10000);

        ReflectionHelper::setProperty($connection, 'client', null);
        ReflectionHelper::setProperty($connection, 'lastZxid', 456);
        ReflectionHelper::setProperty($connection, 'dataWatchers', [
            '/watched-data' => [static fn () => null],
        ]);
        ReflectionHelper::setProperty($connection, 'existsWatchers', [
            '/watched-exists' => [static fn () => null],
        ]);
        ReflectionHelper::setProperty($connection, 'childWatchers', [
            '/watched-child' => [static fn () => null],
        ]);

        ReflectionHelper::invoke($connection, 'replayWatches');

        $this->assertNull(ReflectionHelper::getProperty($connection, 'client'));
    }
}
