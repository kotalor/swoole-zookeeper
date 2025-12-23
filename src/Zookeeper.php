<?php

declare(strict_types=1);

namespace Swoole\Zookeeper;

use Swoole\Zookeeper\Protocol\Buffer;
use Swoole\Zookeeper\Protocol\OpCode;
use Swoole\Zookeeper\Protocol\State;
use Swoole\Zookeeper\Protocol\Perms;
use Swoole\Zookeeper\Protocol\CreateMode;
use Swoole\Zookeeper\Protocol\ErrorCode;
use Swoole\Zookeeper\Protocol\Request\CreateRequest;
use Swoole\Zookeeper\Protocol\Request\DeleteRequest;
use Swoole\Zookeeper\Protocol\Request\ExistsRequest;
use Swoole\Zookeeper\Protocol\Request\GetDataRequest;
use Swoole\Zookeeper\Protocol\Request\SetDataRequest;
use Swoole\Zookeeper\Protocol\Request\GetChildrenRequest;
use Swoole\Zookeeper\Protocol\Request\GetChildren2Request;
use Swoole\Zookeeper\Protocol\Request\GetAclRequest;
use Swoole\Zookeeper\Protocol\Request\SetAclRequest;
use Swoole\Zookeeper\Protocol\Request\AuthRequest;
use Swoole\Zookeeper\Protocol\Response\ReplyHeader;
use Swoole\Zookeeper\Protocol\Response\CreateResponse;
use Swoole\Zookeeper\Protocol\Response\ExistsResponse;
use Swoole\Zookeeper\Protocol\Response\GetDataResponse;
use Swoole\Zookeeper\Protocol\Response\SetDataResponse;
use Swoole\Zookeeper\Protocol\Response\GetChildrenResponse;
use Swoole\Zookeeper\Protocol\Response\GetChildren2Response;
use Swoole\Zookeeper\Protocol\Response\GetAclResponse;
use Swoole\Zookeeper\Protocol\Response\SetAclResponse;
use Swoole\Zookeeper\Protocol\Response\Stat;
use Swoole\Zookeeper\Exception\ZookeeperException;

/**
 * Zookeeper客户端
 * 
 * API与PHP Zookeeper扩展兼容
 */
class Zookeeper
{
    // 兼容PHP Zookeeper扩展的常量
    
    // 权限常量
    public const PERM_READ = Perms::READ;
    public const PERM_WRITE = Perms::WRITE;
    public const PERM_CREATE = Perms::CREATE;
    public const PERM_DELETE = Perms::DELETE;
    public const PERM_ADMIN = Perms::ADMIN;
    public const PERM_ALL = Perms::ALL;
    
    // 创建模式
    public const EPHEMERAL = CreateMode::EPHEMERAL;
    public const SEQUENCE = CreateMode::PERSISTENT_SEQUENTIAL;
    
    // 状态常量
    public const EXPIRED_SESSION_STATE = State::EXPIRED_SESSION;
    public const AUTH_FAILED_STATE = State::AUTH_FAILED;
    public const CONNECTING_STATE = State::CONNECTING;
    public const ASSOCIATING_STATE = State::ASSOCIATING;
    public const CONNECTED_STATE = State::CONNECTED;
    public const READONLY_STATE = State::CONNECTED_READ_ONLY;
    public const NOTCONNECTED_STATE = State::NOT_CONNECTED;
    
    // 事件类型常量
    public const CREATED_EVENT = 1;
    public const DELETED_EVENT = 2;
    public const CHANGED_EVENT = 3;
    public const CHILD_EVENT = 4;
    public const SESSION_EVENT = -1;
    public const NOTWATCHING_EVENT = -2;
    
    // 日志级别常量
    public const LOG_LEVEL_ERROR = 1;
    public const LOG_LEVEL_WARN = 2;
    public const LOG_LEVEL_INFO = 3;
    public const LOG_LEVEL_DEBUG = 4;
    
    // 错误码常量
    public const OK = 0;
    public const SYSTEMERROR = -1;
    public const RUNTIMEINCONSISTENCY = -2;
    public const DATAINCONSISTENCY = -3;
    public const CONNECTIONLOSS = -4;
    public const MARSHALLINGERROR = -5;
    public const UNIMPLEMENTED = -6;
    public const OPERATIONTIMEOUT = -7;
    public const BADARGUMENTS = -8;
    public const INVALIDSTATE = -9;
    public const NEWCONFIGNOQUORUM = -13;
    public const RECONFIGINPROGRESS = -14;
    public const APIERROR = -100;
    public const NONODE = -101;
    public const NOAUTH = -102;
    public const BADVERSION = -103;
    public const NOCHILDRENFOREPHEMERALS = -108;
    public const NODEEXISTS = -110;
    public const NOTEMPTY = -111;
    public const SESSIONEXPIRED = -112;
    public const INVALIDCALLBACK = -113;
    public const INVALIDACL = -114;
    public const AUTHFAILED = -115;
    public const CLOSING = -116;
    public const NOTHING = -117;
    public const SESSIONMOVED = -118;
    public const NOTREADONLY = -119;
    public const EPHEMERALONLOCALSESSION = -120;
    public const NOWATCHER = -121;
    public const RECONFIGDISABLED = -122;
    
    // 兼容扩展中getClientId返回int的行为（部分场景）
    // 注意：原扩展getClientId()返回int，我们返回array以提供更多信息
    
    private ?Connection $connection = null;
    /** @var callable|null */
    private mixed $defaultWatcher = null;
    private int $timeout;
    
    /**
     * 创建Zookeeper客户端
     *
     * @param string $host 服务器地址，格式: host1:port1,host2:port2
     * @param callable|null $watcher_cb 默认watcher回调
     * @param int $recv_timeout 超时时间(毫秒)
     */
    public function __construct(
        string $host = '',
        ?callable $watcher_cb = null,
        int $recv_timeout = 10000
    ) {
        $this->timeout = $recv_timeout;
        $this->defaultWatcher = $watcher_cb;
        
        if ($host !== '') {
            $this->connect($host, $watcher_cb, $recv_timeout);
        }
    }
    
    /**
     * 连接到Zookeeper服务器
     *
     * @param string $host 服务器地址
     * @param callable|null $watcher_cb 默认watcher回调
     * @param int $recv_timeout 超时时间(毫秒)
     */
    public function connect(
        string $host,
        ?callable $watcher_cb = null,
        int $recv_timeout = 10000
    ): void {
        if ($this->connection !== null) {
            $this->close();
        }
        
        $this->timeout = $recv_timeout;
        $this->defaultWatcher = $watcher_cb ?? $this->defaultWatcher;
        
        $this->connection = new Connection($host, $recv_timeout);
        
        if ($this->defaultWatcher) {
            $this->connection->setDefaultWatcher($this->defaultWatcher);
        }
        
        $this->connection->connect();
    }
    
    /**
     * 关闭连接
     */
    public function close(): void
    {
        if ($this->connection !== null) {
            $this->connection->close();
            $this->connection = null;
        }
    }
    
    /**
     * 创建节点
     *
     * @param string $path 节点路径
     * @param string $value 节点数据
     * @param array $acl ACL列表
     * @param int|null $flags 创建标志
     * @return string 创建的节点路径
     */
    public function create(
        string $path,
        string $value,
        array $acl,
        ?int $flags = null
    ): string {
        $this->ensureConnected();
        
        if ($flags === null) {
            $flags = CreateMode::PERSISTENT;
        }
        
        // 转换ACL格式
        $aclList = $this->normalizeAcl($acl);
        
        $request = new CreateRequest($path, $value, $aclList, $flags);
        $xid = $this->connection->getNextXid();
        $data = $request->pack($xid);
        
        $result = $this->connection->sendRequest($data, OpCode::CREATE, $xid);
        
        /** @var ReplyHeader $header */
        $header = $result['header'];
        
        if (!$header->isSuccess()) {
            throw ZookeeperException::fromCode($header->err, "Failed to create node: {$path}");
        }
        
        /** @var Buffer $buffer */
        $buffer = $result['buffer'];
        $response = CreateResponse::parse($buffer);
        
        return $response->path;
    }
    
    /**
     * 删除节点
     *
     * @param string $path 节点路径
     * @param int $version 版本号，-1表示忽略版本检查
     * @return bool 成功返回true
     */
    public function delete(string $path, int $version = -1): bool
    {
        $this->ensureConnected();
        
        $request = new DeleteRequest($path, $version);
        $xid = $this->connection->getNextXid();
        $data = $request->pack($xid);
        
        $result = $this->connection->sendRequest($data, OpCode::DELETE, $xid);
        
        /** @var ReplyHeader $header */
        $header = $result['header'];
        
        if (!$header->isSuccess()) {
            throw ZookeeperException::fromCode($header->err, "Failed to delete node: {$path}");
        }
        
        return true;
    }
    
    /**
     * 检查节点是否存在
     *
     * @param string $path 节点路径
     * @param callable|null $watcher_cb watcher回调
     * @return array|null 存在返回stat数组，不存在返回null
     */
    public function exists(string $path, ?callable $watcher_cb = null): ?array
    {
        $this->ensureConnected();
        
        $watch = $watcher_cb !== null;
        
        if ($watch) {
            $this->connection->registerExistsWatcher($path, $watcher_cb);
        }
        
        $request = new ExistsRequest($path, $watch);
        $xid = $this->connection->getNextXid();
        $data = $request->pack($xid);
        
        $result = $this->connection->sendRequest($data, OpCode::EXISTS, $xid);
        
        /** @var ReplyHeader $header */
        $header = $result['header'];
        
        // 节点不存在时返回null，不抛异常
        if ($header->err === ErrorCode::NO_NODE) {
            return null;
        }
        
        if (!$header->isSuccess()) {
            throw ZookeeperException::fromCode($header->err, "Failed to check exists: {$path}");
        }
        
        /** @var Buffer $buffer */
        $buffer = $result['buffer'];
        $response = ExistsResponse::parse($buffer);
        
        return $response->stat?->toArray();
    }
    
    /**
     * 获取节点数据
     *
     * @param string $path 节点路径
     * @param callable|null $watcher_cb watcher回调
     * @param array|null $stat 输出参数，节点状态信息
     * @param int $max_size 最大数据长度(未使用，保持兼容性)
     * @return string|false 成功返回数据，失败返回false
     */
    public function get(
        string $path,
        ?callable $watcher_cb = null,
        ?array &$stat = null,
        int $max_size = 0
    ): string|false {
        $this->ensureConnected();
        
        $watch = $watcher_cb !== null;
        
        if ($watch) {
            $this->connection->registerDataWatcher($path, $watcher_cb);
        }
        
        $request = new GetDataRequest($path, $watch);
        $xid = $this->connection->getNextXid();
        $data = $request->pack($xid);
        
        $result = $this->connection->sendRequest($data, OpCode::GET_DATA, $xid);
        
        /** @var ReplyHeader $header */
        $header = $result['header'];
        
        if (!$header->isSuccess()) {
            throw ZookeeperException::fromCode($header->err, "Failed to get data: {$path}");
        }
        
        /** @var Buffer $buffer */
        $buffer = $result['buffer'];
        $response = GetDataResponse::parse($buffer);
        
        $stat = $response->stat->toArray();
        
        return $response->data ?? '';
    }
    
    /**
     * 设置节点数据
     *
     * @param string $path 节点路径
     * @param string $data 节点数据
     * @param int $version 版本号，-1表示忽略版本检查
     * @param array|null $stat 输出参数，节点状态信息
     * @return bool 成功返回true
     */
    public function set(
        string $path,
        string $data,
        int $version = -1,
        ?array &$stat = null
    ): bool {
        $this->ensureConnected();
        
        $request = new SetDataRequest($path, $data, $version);
        $xid = $this->connection->getNextXid();
        $requestData = $request->pack($xid);
        
        $result = $this->connection->sendRequest($requestData, OpCode::SET_DATA, $xid);
        
        /** @var ReplyHeader $header */
        $header = $result['header'];
        
        if (!$header->isSuccess()) {
            throw ZookeeperException::fromCode($header->err, "Failed to set data: {$path}");
        }
        
        /** @var Buffer $buffer */
        $buffer = $result['buffer'];
        $response = SetDataResponse::parse($buffer);
        
        $stat = $response->stat->toArray();
        
        return true;
    }
    
    /**
     * 获取子节点列表
     *
     * @param string $path 节点路径
     * @param callable|null $watcher_cb watcher回调
     * @return array 子节点名称数组
     */
    public function getChildren(string $path, ?callable $watcher_cb = null): array
    {
        $this->ensureConnected();
        
        $watch = $watcher_cb !== null;
        
        if ($watch) {
            $this->connection->registerChildWatcher($path, $watcher_cb);
        }
        
        $request = new GetChildrenRequest($path, $watch);
        $xid = $this->connection->getNextXid();
        $data = $request->pack($xid);
        
        $result = $this->connection->sendRequest($data, OpCode::GET_CHILDREN, $xid);
        
        /** @var ReplyHeader $header */
        $header = $result['header'];
        
        if (!$header->isSuccess()) {
            throw ZookeeperException::fromCode($header->err, "Failed to get children: {$path}");
        }
        
        /** @var Buffer $buffer */
        $buffer = $result['buffer'];
        $response = GetChildrenResponse::parse($buffer);
        
        return $response->children;
    }
    
    /**
     * 获取节点ACL
     *
     * @param string $path 节点路径
     * @return array ACL数组
     */
    public function getAcl(string $path): array
    {
        $this->ensureConnected();
        
        $request = new GetAclRequest($path);
        $xid = $this->connection->getNextXid();
        $data = $request->pack($xid);
        
        $result = $this->connection->sendRequest($data, OpCode::GET_ACL, $xid);
        
        /** @var ReplyHeader $header */
        $header = $result['header'];
        
        if (!$header->isSuccess()) {
            throw ZookeeperException::fromCode($header->err, "Failed to get ACL: {$path}");
        }
        
        /** @var Buffer $buffer */
        $buffer = $result['buffer'];
        $response = GetAclResponse::parse($buffer);
        
        return $response->acl;
    }
    
    /**
     * 设置节点ACL
     *
     * @param string $path 节点路径
     * @param int $version ACL版本号
     * @param array $acl ACL数组
     * @return bool 成功返回true
     */
    public function setAcl(string $path, int $version, array $acl): bool
    {
        $this->ensureConnected();
        
        $aclList = $this->normalizeAcl($acl);
        
        $request = new SetAclRequest($path, $aclList, $version);
        $xid = $this->connection->getNextXid();
        $data = $request->pack($xid);
        
        $result = $this->connection->sendRequest($data, OpCode::SET_ACL, $xid);
        
        /** @var ReplyHeader $header */
        $header = $result['header'];
        
        if (!$header->isSuccess()) {
            throw ZookeeperException::fromCode($header->err, "Failed to set ACL: {$path}");
        }
        
        return true;
    }
    
    /**
     * 添加认证信息
     *
     * @param string $scheme 认证方案
     * @param string $cert 认证信息
     * @param callable|null $completion_cb 完成回调(未使用，保持兼容性)
     * @return bool 成功返回true
     */
    public function addAuth(string $scheme, string $cert, ?callable $completion_cb = null): bool
    {
        $this->ensureConnected();
        
        $request = new AuthRequest(0, $scheme, $cert);
        $xid = $this->connection->getNextXid();
        $data = $request->pack($xid);
        
        $result = $this->connection->sendRequest($data, OpCode::AUTH, $xid);
        
        /** @var ReplyHeader $header */
        $header = $result['header'];
        
        if (!$header->isSuccess()) {
            throw ZookeeperException::fromCode($header->err, "Failed to add auth");
        }
        
        return true;
    }
    
    /**
     * 获取客户端会话ID
     *
     * @return int 会话ID
     */
    public function getClientId(): int
    {
        if ($this->connection === null) {
            return 0;
        }
        return $this->connection->getSessionId();
    }
    
    /**
     * 获取客户端会话信息（扩展方法）
     *
     * @return array 包含client_id和passwd的数组
     */
    public function getSessionInfo(): array
    {
        $this->ensureConnected();
        
        return [
            'client_id' => $this->connection->getSessionId(),
            'passwd' => $this->connection->getSessionPassword(),
        ];
    }
    
    /**
     * 获取连接状态
     *
     * @return int 状态常量
     */
    public function getState(): int
    {
        if ($this->connection === null) {
            return State::NOT_CONNECTED;
        }
        return $this->connection->getState();
    }
    
    /**
     * 获取接收超时时间
     *
     * @return int 超时时间(毫秒)
     */
    public function getRecvTimeout(): int
    {
        if ($this->connection === null) {
            return $this->timeout;
        }
        return $this->connection->getTimeout();
    }
    
    /**
     * 检查连接是否可恢复
     *
     * @return bool
     */
    public function isRecoverable(): bool
    {
        if ($this->connection === null) {
            return false;
        }
        
        $state = $this->connection->getState();
        return $state !== State::EXPIRED_SESSION && $state !== State::AUTH_FAILED;
    }
    
    /**
     * 设置调试级别(未实现，保持兼容性)
     *
     * @param int $level 日志级别
     */
    public static function setDebugLevel(int $level): void
    {
        // 暂不实现
    }
    
    /**
     * 设置确定性连接顺序(未实现，保持兼容性)
     *
     * @param bool $yesOrNo
     * @return bool
     */
    public function setDeterministicConnOrder(bool $yesOrNo): bool
    {
        // 暂不实现
        return true;
    }
    
    /**
     * 设置日志流(未实现，保持兼容性)
     *
     * @param resource $stream
     * @return bool
     */
    public function setLogStream($stream): bool
    {
        // 暂不实现
        return true;
    }
    
    /**
     * 设置全局watcher回调
     *
     * @param callable $watcher_cb watcher回调函数
     * @return bool
     */
    public function setWatcher(callable $watcher_cb): bool
    {
        $this->defaultWatcher = $watcher_cb;
        
        if ($this->connection !== null) {
            $this->connection->setDefaultWatcher($watcher_cb);
        }
        
        return true;
    }
    
    /**
     * 获取ZookeeperConfig实例
     * 
     * 注意：此方法仅为API兼容性保留，返回null
     * Zookeeper 3.5+的动态配置功能暂未实现
     *
     * @return null
     */
    public function getConfig()
    {
        // 动态配置功能暂未实现
        return null;
    }
    
    /**
     * 确保已连接
     */
    private function ensureConnected(): void
    {
        if ($this->connection === null || !$this->connection->isConnected()) {
            throw new ZookeeperException('Not connected to Zookeeper', ErrorCode::CONNECTION_LOSS);
        }
    }
    
    /**
     * 规范化ACL格式
     */
    private function normalizeAcl(array $acl): array
    {
        if (empty($acl)) {
            // 默认使用开放权限
            return [
                ['perms' => Perms::ALL, 'scheme' => 'world', 'id' => 'anyone']
            ];
        }
        
        $result = [];
        foreach ($acl as $item) {
            if (is_array($item)) {
                $result[] = [
                    'perms' => $item['perms'] ?? $item['permissions'] ?? Perms::ALL,
                    'scheme' => $item['scheme'] ?? 'world',
                    'id' => $item['id'] ?? 'anyone',
                ];
            }
        }
        
        return $result ?: [['perms' => Perms::ALL, 'scheme' => 'world', 'id' => 'anyone']];
    }
    
    /**
     * 创建开放权限ACL
     *
     * @return array
     */
    public static function createOpenAclUnsafe(): array
    {
        return [
            ['perms' => Perms::ALL, 'scheme' => 'world', 'id' => 'anyone']
        ];
    }
    
    /**
     * 创建只读权限ACL
     *
     * @return array
     */
    public static function createReadOnlyAcl(): array
    {
        return [
            ['perms' => Perms::READ, 'scheme' => 'world', 'id' => 'anyone']
        ];
    }
    
    /**
     * 创建创建者全权限ACL
     *
     * @return array
     */
    public static function createCreatorAllAcl(): array
    {
        return [
            ['perms' => Perms::ALL, 'scheme' => 'auth', 'id' => '']
        ];
    }
    
    /**
     * 析构函数
     */
    public function __destruct()
    {
        $this->close();
    }
}

