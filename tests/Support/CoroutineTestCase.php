<?php

declare(strict_types=1);

namespace Swoole\Zookeeper\Tests\Support;

use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;

abstract class CoroutineTestCase extends TestCase
{
    /**
     * 在 Swoole 协程环境中执行测试逻辑
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    protected function runInCoroutine(callable $fn): mixed
    {
        $exception = null;
        $result = null;

        Coroutine\run(function () use ($fn, &$exception, &$result) {
            try {
                $result = $fn();
            } catch (\Throwable $e) {
                $exception = $e;
            }
        });

        if ($exception !== null) {
            throw $exception;
        }

        return $result;
    }

    /**
     * 在协程中等待条件成立，超时则失败
     */
    protected function waitUntil(callable $predicate, float $timeoutSeconds = 10.0, float $intervalSeconds = 0.1): void
    {
        $deadline = microtime(true) + $timeoutSeconds;
        while (microtime(true) < $deadline) {
            if ($predicate()) {
                return;
            }
            Coroutine::sleep($intervalSeconds);
        }
        $this->fail('Condition not met within ' . $timeoutSeconds . ' seconds');
    }
}
