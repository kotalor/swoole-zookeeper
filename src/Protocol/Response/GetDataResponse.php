<?php

declare(strict_types=1);

namespace Swoole\Zookeeper\Protocol\Response;

use Swoole\Zookeeper\Protocol\Buffer;

/**
 * 获取节点数据响应
 */
class GetDataResponse
{
    public function __construct(
        public ?string $data,
        public Stat $stat
    ) {}
    
    public static function parse(Buffer $buffer): self
    {
        $data = $buffer->readBuffer();
        $stat = Stat::parse($buffer);
        
        return new self($data, $stat);
    }
}

