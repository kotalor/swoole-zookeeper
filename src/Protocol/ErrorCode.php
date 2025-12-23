<?php

declare(strict_types=1);

namespace Swoole\Zookeeper\Protocol;

/**
 * Zookeeper错误码定义
 */
class ErrorCode
{
    public const OK = 0;
    
    // 系统和连接错误
    public const SYSTEM_ERROR = -1;
    public const RUNTIME_INCONSISTENCY = -2;
    public const DATA_INCONSISTENCY = -3;
    public const CONNECTION_LOSS = -4;
    public const MARSHALLING_ERROR = -5;
    public const UNIMPLEMENTED = -6;
    public const OPERATION_TIMEOUT = -7;
    public const BAD_ARGUMENTS = -8;
    public const INVALID_STATE = -9;
    public const NEW_CONFIG_NO_QUORUM = -13;
    public const RECONFIG_IN_PROGRESS = -14;
    
    // API错误
    public const API_ERROR = -100;
    public const NO_NODE = -101;
    public const NO_AUTH = -102;
    public const BAD_VERSION = -103;
    public const NO_CHILDREN_FOR_EPHEMERALS = -108;
    public const NODE_EXISTS = -110;
    public const NOT_EMPTY = -111;
    public const SESSION_EXPIRED = -112;
    public const INVALID_CALLBACK = -113;
    public const INVALID_ACL = -114;
    public const AUTH_FAILED = -115;
    public const CLOSING = -116;
    public const NOTHING = -117;
    public const SESSION_MOVED = -118;
    public const NOT_READ_ONLY = -119;
    public const EPHEMERAL_ON_LOCAL_SESSION = -120;
    public const NO_WATCHER = -121;
    public const RECONFIG_DISABLED = -122;
    public const SESSION_CLOSED_REQUIRE_SASL_AUTH = -124;
    
    // 兼容PHP扩展的别名
    public const SYSTEMERROR = self::SYSTEM_ERROR;
    public const RUNTIMEINCONSISTENCY = self::RUNTIME_INCONSISTENCY;
    public const DATAINCONSISTENCY = self::DATA_INCONSISTENCY;
    public const CONNECTIONLOSS = self::CONNECTION_LOSS;
    public const MARSHALLINGERROR = self::MARSHALLING_ERROR;
    public const OPERATIONTIMEOUT = self::OPERATION_TIMEOUT;
    public const BADARGUMENTS = self::BAD_ARGUMENTS;
    public const INVALIDSTATE = self::INVALID_STATE;
    public const NEWCONFIGNOQUORUM = self::NEW_CONFIG_NO_QUORUM;
    public const RECONFIGINPROGRESS = self::RECONFIG_IN_PROGRESS;
    public const APIERROR = self::API_ERROR;
    public const NONODE = self::NO_NODE;
    public const NOAUTH = self::NO_AUTH;
    public const BADVERSION = self::BAD_VERSION;
    public const NOCHILDRENFOREPHEMERALS = self::NO_CHILDREN_FOR_EPHEMERALS;
    public const NODEEXISTS = self::NODE_EXISTS;
    public const NOTEMPTY = self::NOT_EMPTY;
    public const SESSIONEXPIRED = self::SESSION_EXPIRED;
    public const INVALIDCALLBACK = self::INVALID_CALLBACK;
    public const INVALIDACL = self::INVALID_ACL;
    public const AUTHFAILED = self::AUTH_FAILED;
    public const SESSIONMOVED = self::SESSION_MOVED;
    public const NOTREADONLY = self::NOT_READ_ONLY;
    public const EPHEMERALONLOCALSESSION = self::EPHEMERAL_ON_LOCAL_SESSION;
    public const NOWATCHER = self::NO_WATCHER;
    public const RECONFIGDISABLED = self::RECONFIG_DISABLED;
    
    /**
     * 获取错误信息
     */
    public static function getMessage(int $code): string
    {
        return match ($code) {
            self::OK => 'ok',
            self::SYSTEM_ERROR => 'system error',
            self::RUNTIME_INCONSISTENCY => 'runtime inconsistency',
            self::DATA_INCONSISTENCY => 'data inconsistency',
            self::CONNECTION_LOSS => 'connection loss',
            self::MARSHALLING_ERROR => 'marshalling error',
            self::UNIMPLEMENTED => 'unimplemented',
            self::OPERATION_TIMEOUT => 'operation timeout',
            self::BAD_ARGUMENTS => 'bad arguments',
            self::INVALID_STATE => 'invalid state',
            self::API_ERROR => 'api error',
            self::NO_NODE => 'no node',
            self::NO_AUTH => 'not authenticated',
            self::BAD_VERSION => 'bad version',
            self::NO_CHILDREN_FOR_EPHEMERALS => 'no children for ephemerals',
            self::NODE_EXISTS => 'node already exists',
            self::NOT_EMPTY => 'directory not empty',
            self::SESSION_EXPIRED => 'session expired',
            self::INVALID_CALLBACK => 'invalid callback',
            self::INVALID_ACL => 'invalid acl',
            self::AUTH_FAILED => 'authentication failed',
            self::CLOSING => 'zookeeper is closing',
            self::NOTHING => 'no server responses to process',
            self::SESSION_MOVED => 'session moved to another server',
            self::NOT_READ_ONLY => 'state-changing request is passed to read-only server',
            self::NO_WATCHER => 'no watcher',
            default => "unknown error code: {$code}",
        };
    }
}

