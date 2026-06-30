<?php

declare(strict_types=1);

namespace Swoole\Zookeeper\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Swoole\Zookeeper\Connection;
use Swoole\Zookeeper\Protocol\Buffer;
use Swoole\Zookeeper\Protocol\OpCode;
use Swoole\Zookeeper\Protocol\Request\AuthRequest;

class AuthXidTest extends TestCase
{
    public function testAuthXidConstantIsMinusFour(): void
    {
        $this->assertSame(-4, Connection::AUTH_XID);
    }

    public function testAuthRequestPackUsesFixedAuthXid(): void
    {
        $request = new AuthRequest(0, 'digest', 'user:pass');
        $packed = $request->pack(Connection::AUTH_XID);

        // 跳过 4 字节包长度
        $body = new Buffer(substr($packed, 4));
        $xid = $body->readInt();
        $opCode = $body->readInt();

        $this->assertSame(Connection::AUTH_XID, $xid);
        $this->assertSame(OpCode::AUTH, $opCode);
    }

    public function testAuthXidDoesNotCollideWithNotificationOrPing(): void
    {
        $this->assertSame(-1, Connection::NOTIFICATION_XID);
        $this->assertSame(-2, Connection::PING_XID);
        $this->assertSame(-4, Connection::AUTH_XID);
        $this->assertSame(-8, Connection::SET_WATCHES_XID);
    }
}
