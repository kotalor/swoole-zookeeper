<?php

declare(strict_types=1);

namespace Swoole\Zookeeper\Protocol\Response;

use Swoole\Zookeeper\Protocol\Buffer;

/**
 * 创建节点响应
 */
class CreateResponse
{
    public function __construct(
        public string $path
    ) {}
    
    public static function parse(Buffer $buffer): self
    {
        return new self(
            $buffer->readString() ?? ''
        );
    }
}

