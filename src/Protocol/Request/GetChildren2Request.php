<?php

declare(strict_types=1);

namespace Swoole\Zookeeper\Protocol\Request;

use Swoole\Zookeeper\Protocol\Buffer;
use Swoole\Zookeeper\Protocol\OpCode;

/**
 * 获取子节点列表请求 (带stat)
 */
class GetChildren2Request
{
    public function __construct(
        public string $path,
        public bool $watch = false
    ) {}
    
    public function serialize(Buffer $buffer, int $xid): void
    {
        $header = new RequestHeader($xid, OpCode::GET_CHILDREN2);
        $header->serialize($buffer);
        
        $buffer->writeString($this->path);
        $buffer->writeBool($this->watch);
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

