<?php

declare(strict_types=1);

namespace Swoole\Zookeeper\Protocol\Response;

use Swoole\Zookeeper\Protocol\Buffer;

/**
 * 响应头
 */
class ReplyHeader
{
    public function __construct(
        public int $xid,
        public int $zxid,
        public int $err
    ) {}
    
    public static function parse(Buffer $buffer): self
    {
        return new self(
            $buffer->readInt(),
            $buffer->readLong(),
            $buffer->readInt()
        );
    }
    
    public function isSuccess(): bool
    {
        return $this->err === 0;
    }
}

