<?php

declare(strict_types=1);

namespace Swoole\Zookeeper\Protocol\Request;

use Swoole\Zookeeper\Protocol\Buffer;
use Swoole\Zookeeper\Protocol\OpCode;

/**
 * 设置节点数据请求
 */
class SetDataRequest
{
    public function __construct(
        public string $path,
        public string $data,
        public int $version = -1
    ) {}
    
    public function serialize(Buffer $buffer, int $xid): void
    {
        $header = new RequestHeader($xid, OpCode::SET_DATA);
        $header->serialize($buffer);
        
        $buffer->writeString($this->path);
        $buffer->writeBuffer($this->data);
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

