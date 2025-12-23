<?php

/**
 * Watcher 监听示例
 */

require __DIR__ . '/../vendor/autoload.php';

use Swoole\Zookeeper\Zookeeper;
use function Swoole\Coroutine\run;

run(function () {
    echo "=== Watcher 监听示例 ===\n\n";
    
    $zk = new Zookeeper('127.0.0.1:2181', function ($type, $state, $path) {
        echo "[DefaultWatcher] type={$type}, state={$state}, path={$path}\n";
    });
    
    $testPath = '/watcher-test';
    
    // 确保测试节点存在
    if ($zk->exists($testPath) === null) {
        $zk->create($testPath, 'initial', Zookeeper::createOpenAclUnsafe());
    }
    
    // 递归监听函数 - 数据变化
    $watchData = function () use ($zk, $testPath, &$watchData) {
        $data = $zk->get($testPath, function ($type, $state, $path) use ($watchData) {
            echo "\n[DataWatcher] 检测到数据变化!\n";
            echo "  type={$type}, state={$state}, path={$path}\n";
            // 重新注册 Watcher
            $watchData();
        });
        echo "当前数据: {$data}\n";
    };
    
    // 递归监听函数 - 子节点变化
    $watchChildren = function () use ($zk, $testPath, &$watchChildren) {
        $children = $zk->getChildren($testPath, function ($type, $state, $path) use ($watchChildren) {
            echo "\n[ChildWatcher] 检测到子节点变化!\n";
            echo "  type={$type}, state={$state}, path={$path}\n";
            // 重新注册 Watcher
            $watchChildren();
        });
        echo "当前子节点: " . (empty($children) ? '(无)' : implode(', ', $children)) . "\n";
    };
    
    echo "开始监听...\n";
    echo "--------------------------------\n";
    
    // 初始化监听
    $watchData();
    $watchChildren();
    
    echo "--------------------------------\n";
    echo "请在另一个终端执行以下操作来触发 Watcher:\n";
    echo "  zkCli.sh -server 127.0.0.1:2181\n";
    echo "  set {$testPath} 'new value'\n";
    echo "  create {$testPath}/child1 'data'\n";
    echo "  delete {$testPath}/child1\n";
    echo "--------------------------------\n\n";
    
    echo "按 Ctrl+C 退出\n\n";
    
    // 保持运行
    while (true) {
        \Swoole\Coroutine::sleep(1);
    }
});

