<?php

declare(strict_types=1);

namespace Swoole\Zookeeper\Tests\Integration;

use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Zookeeper\Exception\ConnectionLossException;
use Swoole\Zookeeper\Protocol\State;
use Swoole\Zookeeper\Tests\Support\CoroutineTestCase;
use Swoole\Zookeeper\Tests\Support\ReflectionHelper;
use Swoole\Zookeeper\Tests\Support\ZkTestHelper;
use Swoole\Zookeeper\Zookeeper;

class ReconnectIntegrationTest extends CoroutineTestCase
{
    private static bool $zkAvailable;

    public static function setUpBeforeClass(): void
    {
        self::$zkAvailable = ZkTestHelper::isZkAvailable();
    }

    protected function setUp(): void
    {
        if (!self::$zkAvailable) {
            $this->markTestSkipped('Zookeeper server not available at ' . ZkTestHelper::getTestHost());
        }
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('Swoole extension is required');
        }
    }

    public function testAddAuthDoesNotTimeout(): void
    {
        $this->runInCoroutine(function () {
            $zk = new Zookeeper(ZkTestHelper::getTestHost(), null, 15000, 10000);
            $this->assertTrue($zk->addAuth('digest', 'testuser:testpass'));
            $zk->close();
        });
    }

    public function testAddAuthDoesNotConsumeIncrementalXid(): void
    {
        $this->runInCoroutine(function () {
            $zk = new Zookeeper(ZkTestHelper::getTestHost(), null, 15000, 10000);
            $zk->addAuth('digest', 'user:pass');

            $connection = ZkTestHelper::getConnection($zk);
            $xidBefore = ReflectionHelper::getProperty($connection, 'xid');
            $path = '/zk-php-test-xid-' . uniqid('', true);
            $zk->create($path, 'data', Zookeeper::createOpenAclUnsafe());
            $xidAfter = ReflectionHelper::getProperty($connection, 'xid');

            $this->assertSame($xidBefore + 1, $xidAfter);
            $zk->delete($path);
            $zk->close();
        });
    }

    public function testReconnectPreservesSessionAndReplaysWatch(): void
    {
        $this->runInCoroutine(function () {
            $sessionEvents = [];
            $watchEvents = new Channel(2);

            $zk = new Zookeeper(ZkTestHelper::getTestHost(), function (int $type, int $state, string $path) use (&$sessionEvents, $watchEvents) {
                if ($type === Zookeeper::SESSION_EVENT) {
                    $sessionEvents[] = $state;
                }
            }, 30000, 10000);

            $sessionId = $zk->getClientId();
            $this->assertGreaterThan(0, $sessionId);

            $path = '/zk-php-reconnect-' . uniqid('', true);
            $zk->create($path, 'initial', Zookeeper::createOpenAclUnsafe());

            // 注册 data watch（完成后本地 watch 仍保留，断线后由 SET_WATCHES 重放）
            $zk->get($path, function (int $type, int $state, string $watchPath) use ($watchEvents) {
                $watchEvents->push(['type' => $type, 'state' => $state, 'path' => $watchPath]);
            });

            ZkTestHelper::forceDisconnect($zk);
            // recv 协程可能仍在 1s 读超时内，显式触发断线处理以稳定测试
            ReflectionHelper::invoke(ZkTestHelper::getConnection($zk), 'handleDisconnect');

            $this->waitUntil(
                fn () => in_array(Zookeeper::CONNECTING_STATE, $sessionEvents, true),
                timeoutSeconds: 5.0
            );

            $this->waitUntil(
                fn () => $zk->getState() === Zookeeper::CONNECTED_STATE,
                timeoutSeconds: 20.0
            );

            $this->waitUntil(
                fn () => in_array(Zookeeper::CONNECTED_STATE, $sessionEvents, true),
                timeoutSeconds: 5.0
            );

            $this->assertSame($sessionId, $zk->getClientId(), 'Session ID should be preserved after reconnect');

            // 触发 watch：重放后服务端应再次推送事件
            $zk->set($path, 'after-reconnect');

            $event = $watchEvents->pop(5.0);
            $this->assertIsArray($event);
            $this->assertSame($path, $event['path']);
            $this->assertSame(Zookeeper::CHANGED_EVENT, $event['type']);

            $zk->delete($path);
            $zk->close();
        });
    }

    public function testDisconnectFailsInFlightRequestWithConnectionLoss(): void
    {
        $this->runInCoroutine(function () {
            $zk = new Zookeeper(ZkTestHelper::getTestHost(), null, 30000, 10000);
            $connection = ZkTestHelper::getConnection($zk);

            $channel = new Channel(1);
            ReflectionHelper::setProperty($connection, 'pendingRequests', [
                99 => [
                    'opCode' => 4,
                    'channel' => $channel,
                    'time' => time(),
                ],
            ]);

            ReflectionHelper::setProperty($connection, 'running', true);
            ReflectionHelper::setProperty($connection, 'state', State::CONNECTED);
            ReflectionHelper::setProperty($connection, 'reconnecting', false);

            ReflectionHelper::invoke($connection, 'handleDisconnect');

            $result = $channel->pop(2.0);
            $this->assertIsArray($result);
            $this->assertInstanceOf(ConnectionLossException::class, $result['error']);

            // 等待重连完成以便清理
            $this->waitUntil(fn () => $zk->getState() === Zookeeper::CONNECTED_STATE, timeoutSeconds: 20.0);
            $zk->close();
        });
    }

    public function testCloseCancelsReconnectAndClearsWatches(): void
    {
        $this->runInCoroutine(function () {
            $zk = new Zookeeper(ZkTestHelper::getTestHost(), null, 30000, 10000);
            $path = '/zk-php-close-' . uniqid('', true);
            $zk->create($path, 'data', Zookeeper::createOpenAclUnsafe());
            $zk->get($path, fn () => null);

            $connection = ZkTestHelper::getConnection($zk);
            ZkTestHelper::forceDisconnect($zk);

            // 在重连过程中主动 close
            Coroutine::sleep(0.2);
            $zk->close();

            $this->assertSame(Zookeeper::NOTCONNECTED_STATE, $zk->getState());
            $this->assertSame([], ReflectionHelper::getProperty($connection, 'dataWatchers'));
            $this->assertSame([], ReflectionHelper::getProperty($connection, 'existsWatchers'));
            $this->assertSame([], ReflectionHelper::getProperty($connection, 'childWatchers'));
            $this->assertFalse(ReflectionHelper::getProperty($connection, 'reconnecting'));

            // 节点可能未删除（连接已断），尽力清理
            try {
                $zk2 = new Zookeeper(ZkTestHelper::getTestHost(), null, 10000, 5000);
                if ($zk2->exists($path) !== null) {
                    $zk2->delete($path);
                }
                $zk2->close();
            } catch (\Throwable) {
                // 忽略清理失败
            }
        });
    }
}
