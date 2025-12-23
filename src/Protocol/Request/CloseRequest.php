<?php

declare(strict_types=1);

namespace Swoole\Zookeeper\Protocol\Request;

use Swoole\Zookeeper\Protocol\Buffer;
use Swoole\Zookeeper\Protocol\OpCode;

/**
 * 关闭连接请求
 */
class CloseRequest
{
    public function serialize(Buffer $buffer, int $xid): void
    {
        $header = new RequestHeader($xid, OpCode::CLOSE);
        $header->serialize($buffer);
    }
    
    public function pack(int $xid): string
    {
        $body = new Buffer();
        $this->serialize($body, $xid);
        
        $packet = new Buffer();
        $packet->writeInt($body->length());
        $packet->writeRaw($body->getBuffer());
        
        return $packet->getBuffer();
    }
}

