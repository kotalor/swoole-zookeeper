<?php

declare(strict_types=1);

namespace Swoole\Zookeeper\Protocol\Response;

use Swoole\Zookeeper\Protocol\Buffer;

/**
 * 检查节点存在响应
 */
class ExistsResponse
{
    public function __construct(
        public ?Stat $stat
    ) {}
    
    public static function parse(Buffer $buffer): self
    {
        // 如果有数据，则解析stat
        if ($buffer->remaining() > 0) {
            return new self(Stat::parse($buffer));
        }
        return new self(null);
    }
}

