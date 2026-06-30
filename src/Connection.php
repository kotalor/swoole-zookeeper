<?php

declare(strict_types=1);

namespace Swoole\Zookeeper;

use Swoole\Coroutine;
use Swoole\Coroutine\Client;
use Swoole\Coroutine\Channel;
use Swoole\Zookeeper\Protocol\Buffer;
use Swoole\Zookeeper\Protocol\OpCode;
use Swoole\Zookeeper\Protocol\State;
use Swoole\Zookeeper\Protocol\Request\ConnectRequest;
use Swoole\Zookeeper\Protocol\Request\PingRequest;
use Swoole\Zookeeper\Protocol\Request\CloseRequest;
use Swoole\Zookeeper\Protocol\Request\SetWatchesRequest;
use Swoole\Zookeeper\Protocol\Response\ConnectResponse;
use Swoole\Zookeeper\Protocol\Response\ReplyHeader;
use Swoole\Zookeeper\Protocol\Response\WatcherEvent;
use Swoole\Zookeeper\Exception\ZookeeperException;
use Swoole\Zookeeper\Exception\ConnectionLossException;
use Swoole\Zookeeper\Exception\SessionExpiredException;

/**
 * Zookeeper连接管理器
 */
class Connection
{
    // 协议中固定含义的特殊xid
    public const NOTIFICATION_XID = -1;
    public const PING_XID = -2;
    public const AUTH_XID = -4;
    public const SET_WATCHES_XID = -8;

    private ?Client $client = null;
    private Buffer $recvBuffer;
    private int $state = State::NOT_CONNECTED;
    
    // 会话信息
    private int $sessionId = 0;
    private string $sessionPassword = '';
    private int $sessionTimeout = 30000;
    private int $negotiatedTimeout = 0;
    private int $lastZxid = 0;
    
    // 请求管理
    private int $xid = 0;
    private array $pendingRequests = [];
    
    // Watcher管理
    private array $dataWatchers = [];
    private array $existsWatchers = [];
    private array $childWatchers = [];
    private ?\Closure $defaultWatcher = null;
    
    // 协程和通道
    private ?int $recvCoroutineId = null;
    private ?int $pingCoroutineId = null;
    private ?int $reconnectCoroutineId = null;
    private bool $running = false;
    private bool $reconnecting = false;
    // 连接代次，用于让旧的接收协程在重连后自动退出
    private int $generation = 0;
    
    // 服务器列表
    private array $servers = [];
    private int $serverIndex = 0;
    
    /**
     * @param string $hosts            服务器列表 host1:port1,host2:port2
     * @param int    $operationTimeout 操作超时(毫秒)：TCP连接、等待单次请求响应
     * @param int    $sessionTimeout   会话超时(毫秒)：与服务端协商的session timeout，决定心跳间隔与重连窗口
     */
    public function __construct(
        private string $hosts,
        private int $operationTimeout = 30000,
        int $sessionTimeout = 30000
    ) {
        $this->recvBuffer = new Buffer();
        $this->parseHosts($hosts);
        $this->sessionTimeout = $sessionTimeout;
    }
    
    /**
     * 解析服务器列表
     */
    private function parseHosts(string $hosts): void
    {
        $serverList = explode(',', $hosts);
        foreach ($serverList as $server) {
            $server = trim($server);
            if (empty($server)) {
                continue;
            }
            
            $parts = explode(':', $server);
            $host = $parts[0];
            $port = isset($parts[1]) ? (int)$parts[1] : 2181;
            
            $this->servers[] = ['host' => $host, 'port' => $port];
        }
        
        if (empty($this->servers)) {
            throw new \InvalidArgumentException('No valid servers specified');
        }
        
        // 随机打乱服务器顺序
        shuffle($this->servers);
    }
    
    /**
     * 获取下一个服务器
     */
    private function getNextServer(): array
    {
        $server = $this->servers[$this->serverIndex];
        $this->serverIndex = ($this->serverIndex + 1) % count($this->servers);
        return $server;
    }
    
    /**
     * 连接到Zookeeper服务器
     */
    public function connect(): void
    {
        if ($this->state === State::CONNECTED) {
            return;
        }
        
        $this->state = State::CONNECTING;
        $this->running = true;
        
        $connected = false;
        $lastError = null;
        
        // 尝试连接所有服务器
        for ($i = 0; $i < count($this->servers); $i++) {
            $server = $this->getNextServer();
            
            try {
                $this->openSocket($server);
                
                // 发送连接请求
                $this->sendConnectRequest();
                $connected = true;
                break;
            } catch (\Throwable $e) {
                $lastError = $e;
                if ($this->client) {
                    $this->client->close();
                    $this->client = null;
                }
            }
        }
        
        if (!$connected) {
            $this->state = State::NOT_CONNECTED;
            $this->running = false;
            throw $lastError ?? new ConnectionLossException('Failed to connect to any server');
        }
        
        // 启动接收协程
        $this->startRecvCoroutine();
        
        // 启动心跳协程
        $this->startPingCoroutine();
    }
    
    /**
     * 建立到指定服务器的TCP连接
     */
    private function openSocket(array $server): void
    {
        $this->client = new Client(SWOOLE_SOCK_TCP);
        $this->client->set([
            'open_length_check' => true,
            'package_length_type' => 'N',
            'package_length_offset' => 0,
            'package_body_offset' => 4,
            'package_max_length' => 1024 * 1024,
        ]);
        
        if (!$this->client->connect($server['host'], $server['port'], $this->operationTimeout / 1000)) {
            throw new ConnectionLossException("Failed to connect to {$server['host']}:{$server['port']}");
        }
    }
    
    /**
     * 发送连接请求
     */
    private function sendConnectRequest(): void
    {
        $request = new ConnectRequest(
            protocolVersion: 0,
            lastZxidSeen: $this->lastZxid,
            timeout: $this->sessionTimeout,
            sessionId: $this->sessionId,
            password: $this->sessionPassword ?: null,
            readOnly: false
        );
        
        $data = $request->pack();
        $this->client->send($data);
        
        // 等待连接响应
        $response = $this->client->recv($this->operationTimeout / 1000);
        if ($response === false || $response === '') {
            throw new ConnectionLossException('Failed to receive connect response');
        }
        
        // 解析连接响应
        $buffer = new Buffer(substr($response, 4)); // 跳过长度字段
        $connectResponse = ConnectResponse::parse($buffer);
        
        if ($connectResponse->sessionId === 0) {
            throw new SessionExpiredException('Session expired or invalid');
        }
        
        $this->sessionId = $connectResponse->sessionId;
        $this->sessionPassword = $connectResponse->password;
        $this->negotiatedTimeout = $connectResponse->timeout;
        $this->state = State::CONNECTED;
    }
    
    /**
     * 启动接收协程
     */
    private function startRecvCoroutine(): void
    {
        $this->running = true;
        
        // 绑定本协程对应的连接代次与client实例，重连后旧协程会自动退出
        $gen = ++$this->generation;
        $client = $this->client;
        
        $this->recvCoroutineId = Coroutine::create(function () use ($gen, $client) {
            while ($this->running && $this->generation === $gen) {
                $data = $client->recv(1.0);
                
                // 已被新的连接代次取代，直接退出，避免对同一socket并发recv
                if ($this->generation !== $gen) {
                    break;
                }
                
                if ($data === false) {
                    // 仅当确实是读超时才继续等待，其余错误一律按断线处理
                    if ($this->isTimeoutErrCode($client->errCode)) {
                        continue;
                    }
                    $this->handleDisconnect();
                    break;
                }
                
                if ($data === '') {
                    $this->handleDisconnect();
                    break;
                }
                
                $this->handleResponse(substr($data, 4)); // 跳过长度字段
            }
        });
    }
    
    /**
     * 判断recv返回的错误码是否为读超时（健康连接的正常空闲）
     */
    private function isTimeoutErrCode(int $errCode): bool
    {
        static $codes = null;
        if ($codes === null) {
            // 110 = ETIMEDOUT(Linux), -2 = SWOOLE_ERROR_SOCKET_POLL_TIMEOUT(旧版)
            $codes = [110, -2];
            if (defined('SWOOLE_ERROR_CO_TIMEOUT')) {
                $codes[] = SWOOLE_ERROR_CO_TIMEOUT;
            }
            if (defined('SOCKET_ETIMEDOUT')) {
                $codes[] = SOCKET_ETIMEDOUT;
            }
        }
        return in_array($errCode, $codes, true);
    }
    
    /**
     * 启动心跳协程
     */
    private function startPingCoroutine(): void
    {
        // 重连时可能存在仍在sleep的旧心跳协程，先取消避免重复
        if ($this->pingCoroutineId !== null) {
            Coroutine::cancel($this->pingCoroutineId);
            $this->pingCoroutineId = null;
        }
        
        $pingInterval = $this->negotiatedTimeout / 3000; // 转换为秒，除以3
        if ($pingInterval < 1) {
            $pingInterval = 1;
        }
        
        $this->pingCoroutineId = Coroutine::create(function () use ($pingInterval) {
            while ($this->running && $this->state === State::CONNECTED) {
                Coroutine::sleep($pingInterval);
                
                if ($this->running && $this->state === State::CONNECTED) {
                    try {
                        $this->sendPing();
                    } catch (\Throwable $e) {
                        // 忽略心跳错误
                    }
                }
            }
        });
    }
    
    /**
     * 发送心跳
     */
    private function sendPing(): void
    {
        if (!$this->client || !$this->client->connected) {
            return;
        }
        
        $request = new PingRequest();
        $data = $request->pack(self::PING_XID); // xid = -2 表示ping
        $this->client->send($data);
    }
    
    /**
     * 处理响应数据
     */
    private function handleResponse(string $data): void
    {
        $buffer = new Buffer($data);
        $header = ReplyHeader::parse($buffer);
        
        // 跟踪已见的最大zxid，用于重连握手与SET_WATCHES重放
        if ($header->zxid > 0) {
            $this->lastZxid = $header->zxid;
        }
        
        // 处理Watcher通知
        if ($header->xid === self::NOTIFICATION_XID) {
            $event = WatcherEvent::parse($buffer);
            $this->handleWatcherEvent($event);
            return;
        }
        
        // 处理Ping响应
        if ($header->xid === self::PING_XID) {
            return;
        }
        
        // 处理SET_WATCHES重放响应（无需业务处理）
        if ($header->xid === self::SET_WATCHES_XID) {
            return;
        }
        
        // 处理普通响应（含认证响应xid=-4）
        if (isset($this->pendingRequests[$header->xid])) {
            $channel = $this->pendingRequests[$header->xid]['channel'];
            unset($this->pendingRequests[$header->xid]);
            
            $channel->push([
                'header' => $header,
                'buffer' => $buffer,
            ]);
        }
    }
    
    /**
     * 处理Watcher事件
     */
    private function handleWatcherEvent(WatcherEvent $event): void
    {
        $path = $event->path;
        
        // 调用默认watcher
        if ($this->defaultWatcher) {
            Coroutine::create(function () use ($event) {
                try {
                    ($this->defaultWatcher)($event->type, $event->state, $event->path);
                } catch (\Throwable $e) {
                    // 忽略watcher错误
                }
            });
        }
        
        // 根据事件类型调用对应的watcher
        switch ($event->type) {
            case 1: // NODE_CREATED
            case 2: // NODE_DELETED
            case 3: // NODE_DATA_CHANGED
                if (isset($this->dataWatchers[$path])) {
                    $watchers = $this->dataWatchers[$path];
                    unset($this->dataWatchers[$path]);
                    foreach ($watchers as $watcher) {
                        Coroutine::create(function () use ($watcher, $event) {
                            try {
                                $watcher($event->type, $event->state, $event->path);
                            } catch (\Throwable $e) {
                                // 忽略watcher错误
                            }
                        });
                    }
                }
                if (isset($this->existsWatchers[$path])) {
                    $watchers = $this->existsWatchers[$path];
                    unset($this->existsWatchers[$path]);
                    foreach ($watchers as $watcher) {
                        Coroutine::create(function () use ($watcher, $event) {
                            try {
                                $watcher($event->type, $event->state, $event->path);
                            } catch (\Throwable $e) {
                                // 忽略watcher错误
                            }
                        });
                    }
                }
                break;
                
            case 4: // NODE_CHILDREN_CHANGED
                if (isset($this->childWatchers[$path])) {
                    $watchers = $this->childWatchers[$path];
                    unset($this->childWatchers[$path]);
                    foreach ($watchers as $watcher) {
                        Coroutine::create(function () use ($watcher, $event) {
                            try {
                                $watcher($event->type, $event->state, $event->path);
                            } catch (\Throwable $e) {
                                // 忽略watcher错误
                            }
                        });
                    }
                }
                break;
        }
    }
    
    /**
     * 处理断开连接
     *
     * 不再直接放弃会话，而是进入重连流程：在会话超时时间内尝试用同一个
     * sessionId/password 重连到任意服务器，成功后通过 SET_WATCHES 重放watch。
     */
    private function handleDisconnect(): void
    {
        // 已主动关闭、已在重连中则忽略
        if (!$this->running || $this->state === State::CLOSED || $this->reconnecting) {
            return;
        }
        
        $this->state = State::CONNECTING;
        
        // 在途请求按ConnectionLoss失败，由调用方自行重试；watch保留以便重放
        $this->failPendingRequests(new ConnectionLossException('Connection lost'));
        
        // 通知默认watcher连接断开（会话事件）
        $this->notifyStateEvent(State::CONNECTING);
        
        $this->startReconnect();
    }
    
    /**
     * 启动重连协程
     */
    private function startReconnect(): void
    {
        if ($this->reconnecting) {
            return;
        }
        $this->reconnecting = true;
        
        $this->reconnectCoroutineId = Coroutine::create(function () {
            try {
                $this->reconnectLoop();
            } finally {
                $this->reconnecting = false;
                $this->reconnectCoroutineId = null;
            }
        });
    }
    
    /**
     * 重连主循环：在会话超时时间内不断尝试重连
     */
    private function reconnectLoop(): void
    {
        $sessionTimeoutMs = $this->negotiatedTimeout ?: $this->sessionTimeout;
        $deadline = microtime(true) + ($sessionTimeoutMs / 1000);
        
        while ($this->running && microtime(true) < $deadline) {
            // 清理旧socket
            if ($this->client) {
                $this->client->close();
                $this->client = null;
            }
            
            $server = $this->getNextServer();
            
            try {
                $this->openSocket($server);
                // 复用现有 sessionId / password 续接会话
                $this->sendConnectRequest();
                
                // 重连成功：启动新的接收/心跳协程并重放watch
                $this->startRecvCoroutine();
                $this->startPingCoroutine();
                $this->replayWatches();
                
                $this->notifyStateEvent(State::CONNECTED);
                return;
            } catch (SessionExpiredException $e) {
                // 服务端明确返回会话已过期
                $this->expireSession();
                return;
            } catch (\Throwable $e) {
                if ($this->client) {
                    $this->client->close();
                    $this->client = null;
                }
                // 短暂退避后换下一个服务器重试
                Coroutine::sleep(1);
            }
        }
        
        // 超过会话超时仍未连上，判定会话过期
        if ($this->running) {
            $this->expireSession();
        }
    }
    
    /**
     * 标记会话过期并清理
     */
    private function expireSession(): void
    {
        $this->state = State::EXPIRED_SESSION;
        $this->sessionId = 0;
        $this->sessionPassword = '';
        $this->lastZxid = 0;
        
        if ($this->client) {
            $this->client->close();
            $this->client = null;
        }
        
        $this->failPendingRequests(new SessionExpiredException('Session expired'));
        $this->notifyStateEvent(State::EXPIRED_SESSION);
    }
    
    /**
     * 重连成功后重放本地仍有效的watch
     */
    private function replayWatches(): void
    {
        $dataPaths = array_keys($this->dataWatchers);
        $existsPaths = array_keys($this->existsWatchers);
        $childPaths = array_keys($this->childWatchers);
        
        if (empty($dataPaths) && empty($existsPaths) && empty($childPaths)) {
            return;
        }
        
        $request = new SetWatchesRequest(
            $this->lastZxid,
            $dataPaths,
            $existsPaths,
            $childPaths
        );
        $data = $request->pack(self::SET_WATCHES_XID);
        
        if ($this->client && $this->client->connected) {
            $this->client->send($data);
        }
    }
    
    /**
     * 触发默认watcher的会话状态事件
     */
    private function notifyStateEvent(int $state): void
    {
        if ($this->defaultWatcher === null) {
            return;
        }
        $cb = $this->defaultWatcher;
        Coroutine::create(function () use ($cb, $state) {
            try {
                $cb(self::NOTIFICATION_XID, $state, '');
            } catch (\Throwable $e) {
                // 忽略watcher错误
            }
        });
    }
    
    /**
     * 让所有在途请求以指定错误失败
     */
    private function failPendingRequests(\Throwable $error): void
    {
        foreach ($this->pendingRequests as $xid => $request) {
            $request['channel']->push([
                'error' => $error,
            ]);
        }
        $this->pendingRequests = [];
    }
    
    /**
     * 发送请求并等待响应
     * 
     * @param string $data 已打包的请求数据
     * @param int $opCode 操作码
     * @param int $xid 请求ID
     */
    public function sendRequest(string $data, int $opCode, int $xid): array
    {
        if ($this->state !== State::CONNECTED) {
            throw new ConnectionLossException('Not connected');
        }
        
        $channel = new Channel(1);
        
        $this->pendingRequests[$xid] = [
            'opCode' => $opCode,
            'channel' => $channel,
            'time' => time(),
        ];
        
        // 发送请求
        $sent = $this->client->send($data);
        if ($sent === false) {
            unset($this->pendingRequests[$xid]);
            throw new ConnectionLossException('Failed to send request');
        }
        
        // 等待响应
        $result = $channel->pop($this->operationTimeout / 1000);
        
        if ($result === false) {
            unset($this->pendingRequests[$xid]);
            throw new ZookeeperException('Request timeout', -7);
        }
        
        if (isset($result['error'])) {
            throw $result['error'];
        }
        
        return $result;
    }
    
    /**
     * 获取下一个xid
     */
    public function getNextXid(): int
    {
        return ++$this->xid;
    }
    
    /**
     * 注册数据watcher
     */
    public function registerDataWatcher(string $path, callable $watcher): void
    {
        if (!isset($this->dataWatchers[$path])) {
            $this->dataWatchers[$path] = [];
        }
        $this->dataWatchers[$path][] = $watcher;
    }
    
    /**
     * 注册exists watcher
     */
    public function registerExistsWatcher(string $path, callable $watcher): void
    {
        if (!isset($this->existsWatchers[$path])) {
            $this->existsWatchers[$path] = [];
        }
        $this->existsWatchers[$path][] = $watcher;
    }
    
    /**
     * 注册子节点watcher
     */
    public function registerChildWatcher(string $path, callable $watcher): void
    {
        if (!isset($this->childWatchers[$path])) {
            $this->childWatchers[$path] = [];
        }
        $this->childWatchers[$path][] = $watcher;
    }
    
    /**
     * 设置默认watcher
     */
    public function setDefaultWatcher(?callable $watcher): void
    {
        $this->defaultWatcher = $watcher ? \Closure::fromCallable($watcher) : null;
    }
    
    /**
     * 关闭连接
     */
    public function close(): void
    {
        $this->running = false;
        $this->reconnecting = false;
        
        // 取消重连协程（如果正在重连中）
        if ($this->reconnectCoroutineId !== null) {
            Coroutine::cancel($this->reconnectCoroutineId);
            $this->reconnectCoroutineId = null;
        }
        
        // 取消心跳协程（如果正在 sleep 中）
        if ($this->pingCoroutineId !== null) {
            Coroutine::cancel($this->pingCoroutineId);
            $this->pingCoroutineId = null;
        }
        
        // 取消接收协程
        if ($this->recvCoroutineId !== null) {
            Coroutine::cancel($this->recvCoroutineId);
            $this->recvCoroutineId = null;
        }
        
        if ($this->state === State::CONNECTED && $this->client && $this->client->connected) {
            try {
                $request = new CloseRequest();
                $data = $request->pack($this->getNextXid());
                $this->client->send($data);
            } catch (\Throwable $e) {
                // 忽略关闭错误
            }
        }
        
        if ($this->client) {
            $this->client->close();
            $this->client = null;
        }
        
        $this->state = State::CLOSED;
        
        // 清理待处理的请求与本地watch
        $this->failPendingRequests(new ConnectionLossException('Connection closed'));
        $this->dataWatchers = [];
        $this->existsWatchers = [];
        $this->childWatchers = [];
    }
    
    /**
     * 获取连接状态
     */
    public function getState(): int
    {
        return $this->state;
    }
    
    /**
     * 获取会话ID
     */
    public function getSessionId(): int
    {
        return $this->sessionId;
    }
    
    /**
     * 获取会话密码
     */
    public function getSessionPassword(): string
    {
        return $this->sessionPassword;
    }
    
    /**
     * 获取会话超时时间(毫秒)
     *
     * 已连接时返回与服务端协商后的值，否则返回请求值。
     */
    public function getTimeout(): int
    {
        return $this->negotiatedTimeout ?: $this->sessionTimeout;
    }
    
    /**
     * 获取操作超时时间(毫秒)
     */
    public function getOperationTimeout(): int
    {
        return $this->operationTimeout;
    }
    
    /**
     * 设置操作超时时间(毫秒)
     */
    public function setOperationTimeout(int $milliseconds): void
    {
        $this->operationTimeout = $milliseconds;
    }
    
    /**
     * 是否已连接
     */
    public function isConnected(): bool
    {
        return $this->state === State::CONNECTED;
    }
}

