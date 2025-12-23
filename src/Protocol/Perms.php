<?php

declare(strict_types=1);

namespace Swoole\Zookeeper\Protocol;

/**
 * ACL权限定义
 */
class Perms
{
    public const READ = 1;
    public const WRITE = 2;
    public const CREATE = 4;
    public const DELETE = 8;
    public const ADMIN = 16;
    public const ALL = 31; // READ | WRITE | CREATE | DELETE | ADMIN
}

