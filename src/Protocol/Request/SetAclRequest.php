<?php

declare(strict_types=1);

namespace Swoole\Zookeeper\Protocol\Request;

use Swoole\Zookeeper\Protocol\Buffer;
use Swoole\Zookeeper\Protocol\OpCode;

/**
 * 设置ACL请求
 */
class SetAclRequest
{
    public function __construct(
        public string $path,
        public array $acl,
        public int $version = -1
    ) {}
    
    public function serialize(Buffer $buffer, int $xid): void
    {
        $header = new RequestHeader($xid, OpCode::SET_ACL);
        $header->serialize($buffer);
        
        $buffer->writeString($this->path);
        
        // ACL列表
        $buffer->writeInt(count($this->acl));
        foreach ($this->acl as $aclItem) {
            $buffer->writeInt($aclItem['perms'] ?? $aclItem['permissions'] ?? 31);
            $buffer->writeString($aclItem['scheme'] ?? 'world');
            $buffer->writeString($aclItem['id'] ?? 'anyone');
        }
        
        $buffer->writeInt($this->version);
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

