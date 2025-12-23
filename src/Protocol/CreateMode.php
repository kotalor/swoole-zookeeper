<?php

declare(strict_types=1);

namespace Swoole\Zookeeper\Protocol;

/**
 * 节点创建模式
 */
class CreateMode
{
    /** 持久节点 */
    public const PERSISTENT = 0;
    
    /** 临时节点 */
    public const EPHEMERAL = 1;
    
    /** 持久顺序节点 */
    public const PERSISTENT_SEQUENTIAL = 2;
    
    /** 临时顺序节点 */
    public const EPHEMERAL_SEQUENTIAL = 3;
    
    /** 容器节点 */
    public const CONTAINER = 4;
    
    /** 持久TTL节点 */
    public const PERSISTENT_WITH_TTL = 5;
    
    /** 持久顺序TTL节点 */
    public const PERSISTENT_SEQUENTIAL_WITH_TTL = 6;
}

