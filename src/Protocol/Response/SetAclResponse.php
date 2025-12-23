<?php

declare(strict_types=1);

namespace Swoole\Zookeeper\Protocol\Response;

use Swoole\Zookeeper\Protocol\Buffer;

/**
 * 设置ACL响应
 */
class SetAclResponse
{
    public function __construct(
        public Stat $stat
    ) {}
    
    public static function parse(Buffer $buffer): self
    {
        return new self(Stat::parse($buffer));
    }
}

