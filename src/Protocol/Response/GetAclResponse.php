<?php

declare(strict_types=1);

namespace Swoole\Zookeeper\Protocol\Response;

use Swoole\Zookeeper\Protocol\Buffer;

/**
 * 获取ACL响应
 */
class GetAclResponse
{
    public function __construct(
        public array $acl,
        public Stat $stat
    ) {}
    
    public static function parse(Buffer $buffer): self
    {
        $count = $buffer->readInt();
        $acl = [];
        for ($i = 0; $i < $count; $i++) {
            $perms = $buffer->readInt();
            $scheme = $buffer->readString();
            $id = $buffer->readString();
            $acl[] = [
                'perms' => $perms,
                'scheme' => $scheme,
                'id' => $id,
            ];
        }
        
        $stat = Stat::parse($buffer);
        
        return new self($acl, $stat);
    }
}

