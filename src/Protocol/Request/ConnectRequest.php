<?php

declare(strict_types=1);

namespace Swoole\Zookeeper\Protocol\Request;

use Swoole\Zookeeper\Protocol\Buffer;

/**
 * 连接请求
 */
class ConnectRequest
{
    public function __construct(
        public int $protocolVersion = 0,
        public int $lastZxidSeen = 0,
        public int $timeout = 30000,
        public int $sessionId = 0,
        public ?string $password = null,
        public bool $readOnly = false
    ) {
        if ($this->password === null) {
            $this->password = str_repeat("\x00", 16);
        }
    }
    
    public function serialize(Buffer $buffer): void
    {
        $buffer->writeInt($this->protocolVersion);
        $buffer->writeLong($this->lastZxidSeen);
        $buffer->writeInt($this->timeout);
        $buffer->writeLong($this->sessionId);
        $buffer->writeBuffer($this->password);
        $buffer->writeBool($this->readOnly);
    }
    
    /**
     * 将请求打包为带长度前缀的数据
     */
    public function pack(): string
    {
        $body = new Buffer();
        $this->serialize($body);
        
        $packet = new Buffer();
        $packet->writeInt($body->length());
        $packet->writeRaw($body->getBuffer());
        
        return $packet->getBuffer();
    }
}

