<?php

declare(strict_types=1);

namespace Swoole\Zookeeper\Protocol\Response;

use Swoole\Zookeeper\Protocol\Buffer;

/**
 * 获取子节点列表响应 (带stat)
 */
class GetChildren2Response
{
    public function __construct(
        public array $children,
        public Stat $stat
    ) {}
    
    public static function parse(Buffer $buffer): self
    {
        $children = $buffer->readStringArray();
        $stat = Stat::parse($buffer);
        
        return new self($children, $stat);
    }
}

