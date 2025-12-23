<?php

declare(strict_types=1);

namespace Swoole\Zookeeper\Protocol\Response;

use Swoole\Zookeeper\Protocol\Buffer;

/**
 * Watcher事件
 */
class WatcherEvent
{
    public function __construct(
        public int $type,
        public int $state,
        public string $path
    ) {}
    
    public static function parse(Buffer $buffer): self
    {
        return new self(
            $buffer->readInt(),
            $buffer->readInt(),
            $buffer->readString() ?? ''
        );
    }
}

