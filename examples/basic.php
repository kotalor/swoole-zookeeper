<?php

/**
 * 基本使用示例
 */

require __DIR__ . '/../vendor/autoload.php';

use Swoole\Zookeeper\Zookeeper;
use Swoole\Zookeeper\Exception\NodeExistsException;
use Swoole\Zookeeper\Exception\NoNodeException;

use function Swoole\Coroutine\run;

run(function () {
    echo "=== Swoole Zookeeper Client 示例 ===\n\n";
    
    // 创建客户端并连接
    echo "1. 连接到 Zookeeper...\n";
    $zk = new Zookeeper('127.0.0.1:2181', function ($type, $state, $path) {
        echo "[Watcher] type={$type}, state={$state}, path={$path}\n";
    });
    
    echo "   状态: " . ($zk->getState() === Zookeeper::CONNECTED_STATE ? '已连接' : '未连接') . "\n";
    $clientId = $zk->getClientId();
    echo "   会话ID: " . $clientId['client_id'] . "\n";
    echo "   超时时间: " . $zk->getRecvTimeout() . "ms\n\n";
    
    $testPath = '/swoole-zk-test';
    
    // 清理可能存在的测试节点
    try {
        $children = $zk->getChildren($testPath);
        foreach ($children as $child) {
            $zk->delete("{$testPath}/{$child}");
        }
        $zk->delete($testPath);
    } catch (NoNodeException $e) {
        // 节点不存在，忽略
    }
    
    // 创建节点
    echo "2. 创建节点...\n";
    try {
        $path = $zk->create($testPath, 'Hello Swoole Zookeeper', Zookeeper::createOpenAclUnsafe());
        echo "   创建成功: {$path}\n\n";
    } catch (NodeExistsException $e) {
        echo "   节点已存在\n\n";
    }
    
    // 获取数据
    echo "3. 获取节点数据...\n";
    $data = $zk->get($testPath, null, $stat);
    echo "   数据: {$data}\n";
    echo "   版本: {$stat['version']}\n";
    echo "   创建时间: " . date('Y-m-d H:i:s', $stat['ctime'] / 1000) . "\n\n";
    
    // 设置数据
    echo "4. 更新节点数据...\n";
    $zk->set($testPath, 'Updated data', -1, $newStat);
    echo "   新版本: {$newStat['version']}\n\n";
    
    // 检查节点存在性
    echo "5. 检查节点存在性...\n";
    $exists = $zk->exists($testPath);
    echo "   节点存在: " . ($exists !== null ? '是' : '否') . "\n\n";
    
    // 创建子节点
    echo "6. 创建子节点...\n";
    $zk->create("{$testPath}/child1", 'child data 1', Zookeeper::createOpenAclUnsafe());
    $zk->create("{$testPath}/child2", 'child data 2', Zookeeper::createOpenAclUnsafe());
    echo "   创建了 child1 和 child2\n\n";
    
    // 获取子节点
    echo "7. 获取子节点列表...\n";
    $children = $zk->getChildren($testPath);
    echo "   子节点: " . implode(', ', $children) . "\n\n";
    
    // 获取ACL
    echo "8. 获取ACL...\n";
    $acl = $zk->getAcl($testPath);
    foreach ($acl as $item) {
        echo "   scheme: {$item['scheme']}, id: {$item['id']}, perms: {$item['perms']}\n";
    }
    echo "\n";
    
    // 创建临时节点
    echo "9. 创建临时节点...\n";
    $ephemeralPath = $zk->create("{$testPath}/ephemeral", 'temp data', Zookeeper::createOpenAclUnsafe(), Zookeeper::EPHEMERAL);
    echo "   临时节点: {$ephemeralPath}\n\n";
    
    // 创建顺序节点
    echo "10. 创建顺序节点...\n";
    $seqPath1 = $zk->create("{$testPath}/seq-", 'seq1', Zookeeper::createOpenAclUnsafe(), Zookeeper::SEQUENCE);
    $seqPath2 = $zk->create("{$testPath}/seq-", 'seq2', Zookeeper::createOpenAclUnsafe(), Zookeeper::SEQUENCE);
    echo "    顺序节点1: {$seqPath1}\n";
    echo "    顺序节点2: {$seqPath2}\n\n";
    
    // 监听数据变化
    echo "11. 设置 Watcher 监听数据变化...\n";
    $data = $zk->get($testPath, function ($type, $state, $path) {
        echo "[DataWatcher] 数据变化: path={$path}, type={$type}\n";
    });
    echo "    Watcher 已设置\n\n";
    
    // 触发 Watcher
    echo "12. 触发 Watcher (更新数据)...\n";
    $zk->set($testPath, 'trigger watcher');
    
    // 等待 Watcher 触发
    \Swoole\Coroutine::sleep(0.5);
    echo "\n";
    
    // 清理测试节点
    echo "13. 清理测试节点...\n";
    $children = $zk->getChildren($testPath);
    foreach ($children as $child) {
        $zk->delete("{$testPath}/{$child}");
        echo "    删除: {$testPath}/{$child}\n";
    }
    $zk->delete($testPath);
    echo "    删除: {$testPath}\n\n";
    
    // // 关闭连接
    // echo "14. 关闭连接...\n";
    // $zk->close();
    // echo "    已关闭\n\n";
    
    echo "=== 示例完成 ===\n";
    echo "程序将继续运行，按 Ctrl+C 退出\n\n";
    
    // 保持程序运行
    // 注意：即使不调用 $zk->close()，程序也会在协程执行完毕后退出
    // 如果需要保持程序运行（比如等待 Watcher 事件），需要使用循环
    while (true) {
        \Swoole\Coroutine::sleep(1);
    }
});

