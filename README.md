# Swoole Zookeeper Client

基于 Swoole 协程的纯 PHP Zookeeper 客户端实现，API 与 PHP Zookeeper 扩展完全兼容。

## 特性

- ✅ 基于 Swoole 协程，完全非阻塞
- ✅ API 与 PHP Zookeeper 扩展兼容
- ✅ 支持 Watcher 机制（数据变更、节点变更、子节点变更）
- ✅ 支持多服务器自动切换
- ✅ 支持会话管理和自动心跳
- ✅ 支持 ACL 权限控制
- ✅ 支持认证（digest/auth）

## 环境要求

- PHP >= 8.0
- Swoole >= 4.5

## 安装

```bash
composer require kotalor/swoole-zookeeper
```

## 快速开始

```php
<?php

use Swoole\Zookeeper\Zookeeper;
use function Swoole\Coroutine\run;

run(function () {
    // 创建客户端并连接
    $zk = new Zookeeper('127.0.0.1:2181');
    
    // 创建节点
    $path = $zk->create('/test', 'hello world', Zookeeper::createOpenAclUnsafe());
    echo "Created: {$path}\n";
    
    // 获取数据
    $data = $zk->get('/test', null, $stat);
    echo "Data: {$data}\n";
    print_r($stat);
    
    // 设置数据
    $zk->set('/test', 'new value');
    
    // 检查节点是否存在
    $exists = $zk->exists('/test');
    var_dump($exists !== null);
    
    // 获取子节点
    $children = $zk->getChildren('/');
    print_r($children);
    
    // 删除节点
    $zk->delete('/test');
    
    // 关闭连接
    $zk->close();
});
```

## 使用 Watcher

```php
<?php

use Swoole\Zookeeper\Zookeeper;
use function Swoole\Coroutine\run;

run(function () {
    $zk = new Zookeeper('127.0.0.1:2181', function ($type, $state, $path) {
        echo "Default Watcher: type={$type}, state={$state}, path={$path}\n";
    });
    
    // 创建节点
    $zk->create('/watch-test', 'data', Zookeeper::createOpenAclUnsafe());
    
    // 监听数据变化
    $data = $zk->get('/watch-test', function ($type, $state, $path) {
        echo "Data changed: type={$type}, state={$state}, path={$path}\n";
    });
    
    // 监听子节点变化
    $children = $zk->getChildren('/watch-test', function ($type, $state, $path) {
        echo "Children changed: type={$type}, state={$state}, path={$path}\n";
    });
    
    // 监听节点存在性
    $stat = $zk->exists('/watch-test', function ($type, $state, $path) {
        echo "Node status changed: type={$type}, state={$state}, path={$path}\n";
    });
    
    // 等待一段时间让watcher触发
    \Swoole\Coroutine::sleep(60);
    
    $zk->close();
});
```

## API 参考

### 构造函数

```php
public function __construct(
    string $host = '',
    ?callable $watcher_cb = null,
    int $recv_timeout = 10000
)
```

- `$host`: 服务器地址，格式: `host1:port1,host2:port2`
- `$watcher_cb`: 默认 watcher 回调函数
- `$recv_timeout`: 接收超时时间（毫秒）

### 连接管理

```php
// 连接到服务器
$zk->connect('127.0.0.1:2181');

// 关闭连接
$zk->close();

// 获取状态
$state = $zk->getState();

// 获取超时时间
$timeout = $zk->getRecvTimeout();

// 获取客户端ID
$clientId = $zk->getClientId();

// 检查是否可恢复
$recoverable = $zk->isRecoverable();
```

### 节点操作

```php
// 创建节点
$path = $zk->create('/path', 'data', $acl, $flags);

// 删除节点
$zk->delete('/path', $version);

// 获取数据
$data = $zk->get('/path', $watcher, $stat);

// 设置数据
$zk->set('/path', 'data', $version, $stat);

// 检查节点是否存在
$stat = $zk->exists('/path', $watcher);

// 获取子节点
$children = $zk->getChildren('/path', $watcher);
```

### ACL 操作

```php
// 获取 ACL
$acl = $zk->getAcl('/path');

// 设置 ACL
$zk->setAcl('/path', $version, $acl);

// 添加认证
$zk->addAuth('digest', 'username:password');
```

### 常量

#### 权限常量

- `Zookeeper::PERM_READ` - 读权限
- `Zookeeper::PERM_WRITE` - 写权限
- `Zookeeper::PERM_CREATE` - 创建子节点权限
- `Zookeeper::PERM_DELETE` - 删除子节点权限
- `Zookeeper::PERM_ADMIN` - 管理权限
- `Zookeeper::PERM_ALL` - 所有权限

#### 创建模式

- `Zookeeper::EPHEMERAL` - 临时节点
- `Zookeeper::SEQUENCE` - 顺序节点

#### 状态常量

- `Zookeeper::CONNECTING_STATE` - 正在连接
- `Zookeeper::CONNECTED_STATE` - 已连接
- `Zookeeper::EXPIRED_SESSION_STATE` - 会话过期
- `Zookeeper::AUTH_FAILED_STATE` - 认证失败
- `Zookeeper::NOTCONNECTED_STATE` - 未连接

#### 事件类型

- `Zookeeper::CREATED_EVENT` - 节点创建
- `Zookeeper::DELETED_EVENT` - 节点删除
- `Zookeeper::CHANGED_EVENT` - 数据变更
- `Zookeeper::CHILD_EVENT` - 子节点变更
- `Zookeeper::SESSION_EVENT` - 会话事件

### 辅助方法

```php
// 创建开放权限 ACL
$acl = Zookeeper::createOpenAclUnsafe();

// 创建只读权限 ACL
$acl = Zookeeper::createReadOnlyAcl();

// 创建创建者全权限 ACL
$acl = Zookeeper::createCreatorAllAcl();
```

## 异常处理

```php
use Swoole\Zookeeper\Zookeeper;
use Swoole\Zookeeper\Exception\NoNodeException;
use Swoole\Zookeeper\Exception\NodeExistsException;
use Swoole\Zookeeper\Exception\ConnectionLossException;
use Swoole\Zookeeper\Exception\SessionExpiredException;

try {
    $zk->create('/exists', 'data', Zookeeper::createOpenAclUnsafe());
} catch (NodeExistsException $e) {
    echo "节点已存在\n";
} catch (ConnectionLossException $e) {
    echo "连接丢失\n";
} catch (SessionExpiredException $e) {
    echo "会话过期\n";
}
```

## 完整示例

### 分布式锁

```php
<?php

use Swoole\Zookeeper\Zookeeper;
use Swoole\Zookeeper\Exception\NodeExistsException;
use function Swoole\Coroutine\run;

run(function () {
    $zk = new Zookeeper('127.0.0.1:2181');
    
    $lockPath = '/locks/my-lock';
    
    // 尝试获取锁
    try {
        $zk->create($lockPath, '', Zookeeper::createOpenAclUnsafe(), Zookeeper::EPHEMERAL);
        echo "获取锁成功\n";
        
        // 执行业务逻辑
        \Swoole\Coroutine::sleep(5);
        
        // 释放锁
        $zk->delete($lockPath);
        echo "释放锁成功\n";
    } catch (NodeExistsException $e) {
        echo "获取锁失败，锁已被占用\n";
    }
    
    $zk->close();
});
```

### 服务注册与发现

```php
<?php

use Swoole\Zookeeper\Zookeeper;
use function Swoole\Coroutine\run;

run(function () {
    $zk = new Zookeeper('127.0.0.1:2181');
    
    $servicePath = '/services/my-service';
    
    // 确保服务目录存在
    if ($zk->exists($servicePath) === null) {
        $zk->create($servicePath, '', Zookeeper::createOpenAclUnsafe());
    }
    
    // 注册服务实例（临时顺序节点）
    $instancePath = $zk->create(
        "{$servicePath}/instance-",
        json_encode(['host' => '127.0.0.1', 'port' => 8080]),
        Zookeeper::createOpenAclUnsafe(),
        Zookeeper::EPHEMERAL | Zookeeper::SEQUENCE
    );
    echo "服务注册成功: {$instancePath}\n";
    
    // 发现服务实例
    $instances = $zk->getChildren($servicePath, function ($type, $state, $path) use ($zk, $servicePath) {
        echo "服务列表变更\n";
        $instances = $zk->getChildren($servicePath);
        print_r($instances);
    });
    
    echo "当前服务实例:\n";
    foreach ($instances as $instance) {
        $data = $zk->get("{$servicePath}/{$instance}");
        echo "  - {$instance}: {$data}\n";
    }
    
    // 保持连接
    \Swoole\Coroutine::sleep(60);
    
    $zk->close();
});
```

### 配置中心

```php
<?php

use Swoole\Zookeeper\Zookeeper;
use function Swoole\Coroutine\run;

run(function () {
    $zk = new Zookeeper('127.0.0.1:2181');
    
    $configPath = '/config/app';
    
    // 初始化配置
    if ($zk->exists($configPath) === null) {
        $zk->create('/config', '', Zookeeper::createOpenAclUnsafe());
        $zk->create($configPath, json_encode([
            'database' => ['host' => 'localhost', 'port' => 3306],
            'redis' => ['host' => 'localhost', 'port' => 6379],
        ]), Zookeeper::createOpenAclUnsafe());
    }
    
    // 监听配置变化
    $watchConfig = function () use ($zk, $configPath, &$watchConfig) {
        $config = $zk->get($configPath, function ($type, $state, $path) use ($watchConfig) {
            echo "配置已更新\n";
            $watchConfig();
        });
        
        $decoded = json_decode($config, true);
        echo "当前配置:\n";
        print_r($decoded);
        
        return $decoded;
    };
    
    $config = $watchConfig();
    
    // 保持连接等待配置更新
    \Swoole\Coroutine::sleep(60);
    
    $zk->close();
});
```

## 许可证

MIT License

