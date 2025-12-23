<?php

declare(strict_types=1);

namespace Swoole\Zookeeper\Protocol;

/**
 * Zookeeper操作码定义
 */
class OpCode
{
    // 连接相关
    public const CONNECT = 0;
    public const CLOSE = -11;
    public const PING = 11;
    public const AUTH = 100;
    public const SET_WATCHES = 101;
    
    // 节点操作
    public const CREATE = 1;
    public const DELETE = 2;
    public const EXISTS = 3;
    public const GET_DATA = 4;
    public const SET_DATA = 5;
    public const GET_ACL = 6;
    public const SET_ACL = 7;
    public const GET_CHILDREN = 8;
    public const SYNC = 9;
    public const GET_CHILDREN2 = 12;
    public const CHECK = 13;
    public const MULTI = 14;
    public const CREATE2 = 15;
    public const RECONFIG = 16;
    public const CHECK_WATCHES = 17;
    public const REMOVE_WATCHES = 18;
    public const CREATE_CONTAINER = 19;
    public const DELETE_CONTAINER = 20;
    public const CREATE_TTL = 21;
    
    // Watcher通知
    public const NOTIFICATION = -1;
    
    // 错误码
    public const ERROR = -1;
}

