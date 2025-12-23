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
    private ?Client $client = null;
    private Buffer $recvBuffer;
    private int $state = State::NOT_CONNECTED;
    
    // 会话信息
    private int $sessionId = 0;
    private string $sessionPassword = '';
    private int $sessionTimeout = 30000;
    private int $negotiatedTimeout = 0;
    
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
    private bool $running = false;
    
    // 服务器列表
    private array $servers = [];
    private int $serverIndex = 0;
    
    public function __construct(
        private string $hosts,
        private int $timeout = 30000
    ) {
        $this->recvBuffer = new Buffer();
        $this->parseHosts($hosts);
        $this->sessionTimeout = $timeout;
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
        
        $connected = false;
        $lastError = null;
        
        // 尝试连接所有服务器
        for ($i = 0; $i < count($this->servers); $i++) {
            $server = $this->getNextServer();
            
            try {
                $this->client = new Client(SWOOLE_SOCK_TCP);
                $this->client->set([
                    'open_length_check' => true,
                    'package_length_type' => 'N',
                    'package_length_offset' => 0,
                    'package_body_offset' => 4,
                    'package_max_length' => 1024 * 1024,
                ]);
                
                if (!$this->client->connect($server['host'], $server['port'], $this->timeout / 1000)) {
                    throw new ConnectionLossException("Failed to connect to {$server['host']}:{$server['port']}");
                }
                
                // 发送连接请求
                $this->sendConnectRequest();
                $connected = true;
                break;
            } catch (\Throwable $e) {
                $lastError = $e;
                $this->client = null;
            }
        }
        
        if (!$connected) {
            $this->state = State::NOT_CONNECTED;
            throw $lastError ?? new ConnectionLossException('Failed to connect to any server');
        }
        
        // 启动接收协程
        $this->startRecvCoroutine();
        
        // 启动心跳协程
        $this->startPingCoroutine();
    }
    
    /**
     * 发送连接请求
     */
    private function sendConnectRequest(): void
    {
        $request = new ConnectRequest(
            protocolVersion: 0,
            lastZxidSeen: 0,
            timeout: $this->sessionTimeout,
            sessionId: $this->sessionId,
            password: $this->sessionPassword ?: null,
            readOnly: false
        );
        
        $data = $request->pack();
        $this->client->send($data);
        
        // 等待连接响应
        $response = $this->client->recv($this->timeout / 1000);
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
        
        $this->recvCoroutineId = Coroutine::create(function () {
            while ($this->running && $this->client && $this->client->connected) {
                $data = $this->client->recv(1.0);
                
                if ($data === false) {
                    $errCode = $this->client->errCode;
                    // 110 = ETIMEDOUT, -2 = SWOOLE_ERROR_SOCKET_POLL_TIMEOUT
                    if ($errCode !== 110 && $errCode !== -2) {
                        $this->handleDisconnect();
                        break;
                    }
                    continue;
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
     * 启动心跳协程
     */
    private function startPingCoroutine(): void
    {
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
        $data = $request->pack(-2); // xid = -2 表示ping
        $this->client->send($data);
    }
    
    /**
     * 处理响应数据
     */
    private function handleResponse(string $data): void
    {
        $buffer = new Buffer($data);
        $header = ReplyHeader::parse($buffer);
        
        // 处理Watcher通知
        if ($header->xid === -1) {
            $event = WatcherEvent::parse($buffer);
            $this->handleWatcherEvent($event);
            return;
        }
        
        // 处理Ping响应
        if ($header->xid === -2) {
            return;
        }
        
        // 处理普通响应
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
     */
    private function handleDisconnect(): void
    {
        $this->state = State::NOT_CONNECTED;
        $this->running = false;
        
        // 唤醒所有等待的请求
        foreach ($this->pendingRequests as $xid => $request) {
            $request['channel']->push([
                'error' => new ConnectionLossException('Connection lost'),
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
        $result = $channel->pop($this->timeout / 1000);
        
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
        
        // 清理待处理的请求
        foreach ($this->pendingRequests as $xid => $request) {
            $request['channel']->push([
                'error' => new ConnectionLossException('Connection closed'),
            ]);
        }
        $this->pendingRequests = [];
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
     * 获取超时时间
     */
    public function getTimeout(): int
    {
        return $this->negotiatedTimeout ?: $this->sessionTimeout;
    }
    
    /**
     * 是否已连接
     */
    public function isConnected(): bool
    {
        return $this->state === State::CONNECTED;
    }
}

