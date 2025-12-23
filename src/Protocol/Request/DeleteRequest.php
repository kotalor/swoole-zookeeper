<?php

declare(strict_types=1);

namespace Swoole\Zookeeper\Protocol\Request;

use Swoole\Zookeeper\Protocol\Buffer;
use Swoole\Zookeeper\Protocol\OpCode;

/**
 * 删除节点请求
 */
class DeleteRequest
{
    public function __construct(
        public string $path,
        public int $version = -1
    ) {}
    
    public function serialize(Buffer $buffer, int $xid): void
    {
        $header = new RequestHeader($xid, OpCode::DELETE);
        $header->serialize($buffer);
        
        $buffer->writeString($this->path);
        $buffer->writeInt($this->version);
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

