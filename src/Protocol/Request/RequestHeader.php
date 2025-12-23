<?php

declare(strict_types=1);

namespace Swoole\Zookeeper\Protocol\Request;

use Swoole\Zookeeper\Protocol\Buffer;

/**
 * 请求头
 */
class RequestHeader
{
    public function __construct(
        public int $xid,
        public int $opCode
    ) {}
    
    public function serialize(Buffer $buffer): void
    {
        $buffer->writeInt($this->xid);
        $buffer->writeInt($this->opCode);
    }
}

