<?php

declare(strict_types=1);

namespace Swoole\Zookeeper\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Swoole\Zookeeper\Connection;
use Swoole\Zookeeper\Protocol\Buffer;
use Swoole\Zookeeper\Protocol\OpCode;
use Swoole\Zookeeper\Protocol\Request\SetWatchesRequest;

class SetWatchesRequestTest extends TestCase
{
    public function testPackContainsOpcode101AndSetWatchesXid(): void
    {
        $request = new SetWatchesRequest(
            relativeZxid: 42,
            dataWatches: ['/a'],
            existWatches: ['/b'],
            childWatches: ['/c']
        );
        $packed = $request->pack(Connection::SET_WATCHES_XID);

        $body = new Buffer(substr($packed, 4));
        $xid = $body->readInt();
        $opCode = $body->readInt();
        $relativeZxid = $body->readLong();

        $this->assertSame(Connection::SET_WATCHES_XID, $xid);
        $this->assertSame(OpCode::SET_WATCHES, $opCode);
        $this->assertSame(42, $relativeZxid);
    }

    public function testPackSerializesWatchPathArrays(): void
    {
        $request = new SetWatchesRequest(
            relativeZxid: 0,
            dataWatches: ['/data1', '/data2'],
            existWatches: ['/exists'],
            childWatches: []
        );
        $packed = $request->pack(Connection::SET_WATCHES_XID);

        $body = new Buffer(substr($packed, 4));
        $body->readInt(); // xid
        $body->readInt(); // opcode
        $body->readLong(); // relativeZxid

        $this->assertSame(['/data1', '/data2'], $body->readStringArray());
        $this->assertSame(['/exists'], $body->readStringArray());
        $this->assertSame([], $body->readStringArray());
    }
}
