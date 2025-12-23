<?php

declare(strict_types=1);

namespace Swoole\Zookeeper\Protocol\Request;

use Swoole\Zookeeper\Protocol\Buffer;
use Swoole\Zookeeper\Protocol\OpCode;

/**
 * 认证请求
 */
class AuthRequest
{
    public function __construct(
        public int $type = 0,
        public string $scheme = '',
        public string $auth = ''
    ) {}
    
    public function serialize(Buffer $buffer, int $xid): void
    {
        $header = new RequestHeader($xid, OpCode::AUTH);
        $header->serialize($buffer);
        
        $buffer->writeInt($this->type);
        $buffer->writeString($this->scheme);
        $buffer->writeBuffer($this->auth);
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

