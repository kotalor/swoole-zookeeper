<?php

declare(strict_types=1);

namespace Swoole\Zookeeper\Protocol;

/**
 * Zookeeper连接状态定义
 */
class State
{
    public const CLOSED = 0;
    public const CONNECTING = 1;
    public const ASSOCIATING = 2;
    public const CONNECTED = 3;
    public const CONNECTED_READ_ONLY = 5;
    public const EXPIRED_SESSION = -112;
    public const AUTH_FAILED = -113;
    public const NOT_CONNECTED = 999;
    
    /**
     * 获取状态描述
     */
    public static function getName(int $state): string
    {
        return match ($state) {
            self::CLOSED => 'closed',
            self::CONNECTING => 'connecting',
            self::ASSOCIATING => 'associating',
            self::CONNECTED => 'connected',
            self::CONNECTED_READ_ONLY => 'connected_read_only',
            self::EXPIRED_SESSION => 'expired_session',
            self::AUTH_FAILED => 'auth_failed',
            self::NOT_CONNECTED => 'not_connected',
            default => "unknown state: {$state}",
        };
    }
}

