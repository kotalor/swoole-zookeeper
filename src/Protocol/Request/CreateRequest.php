<?php

declare(strict_types=1);

namespace Swoole\Zookeeper\Protocol\Request;

use Swoole\Zookeeper\Protocol\Buffer;
use Swoole\Zookeeper\Protocol\OpCode;

/**
 * 创建节点请求
 */
class CreateRequest
{
    public function __construct(
        public string $path,
        public string $data,
        public array $acl,
        public int $flags
    ) {}
    
    public function serialize(Buffer $buffer, int $xid): void
    {
        // 请求头
        $header = new RequestHeader($xid, OpCode::CREATE);
        $header->serialize($buffer);
        
        // 请求体
        $buffer->writeString($this->path);
        $buffer->writeBuffer($this->data);
        
        // ACL列表
        $buffer->writeInt(count($this->acl));
        foreach ($this->acl as $aclItem) {
            $buffer->writeInt($aclItem['perms'] ?? $aclItem['permissions'] ?? 31);
            $buffer->writeString($aclItem['scheme'] ?? 'world');
            $buffer->writeString($aclItem['id'] ?? 'anyone');
        }
        
        $buffer->writeInt($this->flags);
    }
    
    /**
     * 打包请求
     */
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

