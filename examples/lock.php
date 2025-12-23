<?php

/**
 * 分布式锁示例
 */

require __DIR__ . '/../vendor/autoload.php';

use Swoole\Zookeeper\Zookeeper;
use Swoole\Zookeeper\Exception\NodeExistsException;
use Swoole\Zookeeper\Exception\NoNodeException;
use function Swoole\Coroutine\run;

/**
 * 简单的分布式锁实现
 */
class DistributedLock
{
    private Zookeeper $zk;
    private string $lockPath;
    private ?string $currentLock = null;
    
    public function __construct(Zookeeper $zk, string $lockPath)
    {
        $this->zk = $zk;
        $this->lockPath = $lockPath;
        
        // 确保锁目录存在
        $this->ensurePath($lockPath);
    }
    
    private function ensurePath(string $path): void
    {
        if ($this->zk->exists($path) !== null) {
            return;
        }
        
        $parts = explode('/', trim($path, '/'));
        $current = '';
        
        foreach ($parts as $part) {
            $current .= '/' . $part;
            if ($this->zk->exists($current) === null) {
                try {
                    $this->zk->create($current, '', Zookeeper::createOpenAclUnsafe());
                } catch (NodeExistsException $e) {
                    // 并发创建，忽略
                }
            }
        }
    }
    
    /**
     * 尝试获取锁
     */
    public function tryLock(int $timeout = 10): bool
    {
        // 创建临时顺序节点
        $lockNode = $this->zk->create(
            "{$this->lockPath}/lock-",
            '',
            Zookeeper::createOpenAclUnsafe(),
            Zookeeper::EPHEMERAL | Zookeeper::SEQUENCE
        );
        
        $this->currentLock = basename($lockNode);
        
        $deadline = time() + $timeout;
        
        while (time() < $deadline) {
            $children = $this->zk->getChildren($this->lockPath);
            sort($children);
            
            // 如果当前节点是最小的，则获取到锁
            if ($children[0] === $this->currentLock) {
                return true;
            }
            
            // 找到比当前节点小的前一个节点
            $index = array_search($this->currentLock, $children);
            if ($index > 0) {
                $prevNode = $children[$index - 1];
                $prevPath = "{$this->lockPath}/{$prevNode}";
                
                // 监听前一个节点的删除
                $channel = new \Swoole\Coroutine\Channel(1);
                
                $exists = $this->zk->exists($prevPath, function () use ($channel) {
                    $channel->push(true);
                });
                
                if ($exists !== null) {
                    // 等待前一个节点被删除或超时
                    $remaining = $deadline - time();
                    if ($remaining > 0) {
                        $channel->pop((float)$remaining);
                    }
                }
            }
        }
        
        // 超时，删除创建的节点
        $this->unlock();
        return false;
    }
    
    /**
     * 释放锁
     */
    public function unlock(): void
    {
        if ($this->currentLock !== null) {
            try {
                $this->zk->delete("{$this->lockPath}/{$this->currentLock}");
            } catch (NoNodeException $e) {
                // 节点已不存在
            }
            $this->currentLock = null;
        }
    }
}

run(function () {
    echo "=== 分布式锁示例 ===\n\n";
    
    $zk = new Zookeeper('127.0.0.1:2181');
    
    $lock = new DistributedLock($zk, '/locks/test');
    
    echo "尝试获取锁...\n";
    
    if ($lock->tryLock(5)) {
        echo "获取锁成功!\n";
        
        // 模拟业务处理
        echo "执行业务逻辑...\n";
        for ($i = 1; $i <= 5; $i++) {
            echo "  处理中... {$i}/5\n";
            \Swoole\Coroutine::sleep(1);
        }
        
        echo "释放锁...\n";
        $lock->unlock();
        echo "锁已释放\n";
    } else {
        echo "获取锁超时\n";
    }
    
    $zk->close();
    
    echo "\n=== 示例完成 ===\n";
});

