<?php

declare(strict_types=1);

namespace Swoole\Zookeeper\Tests\Unit;

use Swoole\Coroutine\Channel;
use Swoole\Zookeeper\Connection;
use Swoole\Zookeeper\Exception\ConnectionLossException;
use Swoole\Zookeeper\Protocol\Buffer;
use Swoole\Zookeeper\Protocol\State;
use Swoole\Zookeeper\Tests\Support\CoroutineTestCase;
use Swoole\Zookeeper\Tests\Support\ReflectionHelper;

class ConnectionResponseTest extends CoroutineTestCase
{
    public function testHandleResponseMatchesAuthXidToPendingRequest(): void
    {
        $this->runInCoroutine(function () {
            $connection = new Connection('127.0.0.1:2181', 5000, 10000);
            $channel = new Channel(1);
            ReflectionHelper::setProperty($connection, 'pendingRequests', [
                Connection::AUTH_XID => [
                    'opCode' => 100,
                    'channel' => $channel,
                    'time' => time(),
                ],
            ]);

            $responseBody = $this->buildReplyHeader(Connection::AUTH_XID, 100, 0);
            ReflectionHelper::invoke($connection, 'handleResponse', $responseBody);

            $result = $channel->pop(0.1);
            $this->assertIsArray($result);
            $this->assertSame(Connection::AUTH_XID, $result['header']->xid);
            $this->assertTrue($result['header']->isSuccess());
            $this->assertSame([], ReflectionHelper::getProperty($connection, 'pendingRequests'));
        });
    }

    public function testHandleResponseUpdatesLastZxid(): void
    {
        $this->runInCoroutine(function () {
            $connection = new Connection('127.0.0.1:2181', 5000, 10000);
            $this->assertSame(0, ReflectionHelper::getProperty($connection, 'lastZxid'));

            $responseBody = $this->buildReplyHeader(1, 999, 0);
            ReflectionHelper::invoke($connection, 'handleResponse', $responseBody);

            $this->assertSame(999, ReflectionHelper::getProperty($connection, 'lastZxid'));
        });
    }

    public function testHandleResponseIgnoresSetWatchesXid(): void
    {
        $this->runInCoroutine(function () {
            $connection = new Connection('127.0.0.1:2181', 5000, 10000);
            $channel = new Channel(1);
            ReflectionHelper::setProperty($connection, 'pendingRequests', []);

            $responseBody = $this->buildReplyHeader(Connection::SET_WATCHES_XID, 50, 0);
            ReflectionHelper::invoke($connection, 'handleResponse', $responseBody);

            $this->assertFalse($channel->pop(0.01));
            $this->assertSame(50, ReflectionHelper::getProperty($connection, 'lastZxid'));
        });
    }

    public function testHandleResponseIgnoresPingXid(): void
    {
        $this->runInCoroutine(function () {
            $connection = new Connection('127.0.0.1:2181', 5000, 10000);
            ReflectionHelper::setProperty($connection, 'lastZxid', 0);

            $responseBody = $this->buildReplyHeader(Connection::PING_XID, 77, 0);
            ReflectionHelper::invoke($connection, 'handleResponse', $responseBody);

            $this->assertSame(77, ReflectionHelper::getProperty($connection, 'lastZxid'));
        });
    }

    public function testFailPendingRequestsPushesConnectionLoss(): void
    {
        $this->runInCoroutine(function () {
            $connection = new Connection('127.0.0.1:2181', 5000, 10000);
            $channel = new Channel(1);
            ReflectionHelper::setProperty($connection, 'pendingRequests', [
                3 => [
                    'opCode' => 1,
                    'channel' => $channel,
                    'time' => time(),
                ],
            ]);

            ReflectionHelper::invoke(
                $connection,
                'failPendingRequests',
                new ConnectionLossException('Connection lost')
            );

            $result = $channel->pop(0.1);
            $this->assertInstanceOf(ConnectionLossException::class, $result['error']);
            $this->assertSame([], ReflectionHelper::getProperty($connection, 'pendingRequests'));
        });
    }

    public function testNotifyStateEventUsesSessionEventType(): void
    {
        $this->runInCoroutine(function () {
            $connection = new Connection('127.0.0.1:2181', 5000, 10000);
            $events = [];
            $connection->setDefaultWatcher(function (int $type, int $state, string $path) use (&$events) {
                $events[] = compact('type', 'state', 'path');
            });

            ReflectionHelper::invoke($connection, 'notifyStateEvent', State::CONNECTING);

            $this->waitUntil(fn () => count($events) === 1, timeoutSeconds: 2.0);

            $this->assertSame(Connection::NOTIFICATION_XID, $events[0]['type']);
            $this->assertSame(State::CONNECTING, $events[0]['state']);
            $this->assertSame('', $events[0]['path']);
        });
    }

    private function buildReplyHeader(int $xid, int $zxid, int $err): string
    {
        $buffer = new Buffer();
        $buffer->writeInt($xid);
        $buffer->writeLong($zxid);
        $buffer->writeInt($err);
        return $buffer->getBuffer();
    }
}
