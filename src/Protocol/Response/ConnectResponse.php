<?php

declare(strict_types=1);

namespace Swoole\Zookeeper\Protocol\Response;

use Swoole\Zookeeper\Protocol\Buffer;

/**
 * 连接响应
 */
class ConnectResponse
{
    public function __construct(
        public int $protocolVersion,
        public int $timeout,
        public int $sessionId,
        public string $password,
        public bool $readOnly = false
    ) {}
    
    public static function parse(Buffer $buffer): self
    {
        $protocolVersion = $buffer->readInt();
        $timeout = $buffer->readInt();
        $sessionId = $buffer->readLong();
        $password = $buffer->readBuffer() ?? '';
        
        $readOnly = false;
        if ($buffer->remaining() > 0) {
            $readOnly = $buffer->readBool();
        }
        
        return new self(
            $protocolVersion,
            $timeout,
            $sessionId,
            $password,
            $readOnly
        );
    }
}

