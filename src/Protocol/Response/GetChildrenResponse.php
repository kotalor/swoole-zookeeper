<?php

declare(strict_types=1);

namespace Swoole\Zookeeper\Protocol\Response;

use Swoole\Zookeeper\Protocol\Buffer;

/**
 * 获取子节点列表响应
 */
class GetChildrenResponse
{
    public function __construct(
        public array $children
    ) {}
    
    public static function parse(Buffer $buffer): self
    {
        return new self($buffer->readStringArray());
    }
}

