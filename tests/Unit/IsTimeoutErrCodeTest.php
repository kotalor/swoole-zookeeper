<?php

declare(strict_types=1);

namespace Swoole\Zookeeper\Tests\Unit;

use Swoole\Coroutine;
use Swoole\Coroutine\Client;
use Swoole\Coroutine\Socket;
use Swoole\Zookeeper\Connection;
use Swoole\Zookeeper\Tests\Support\CoroutineTestCase;
use Swoole\Zookeeper\Tests\Support\ReflectionHelper;

/**
 * 验证 recv 超时 errCode 识别逻辑。
 *
 * Swoole 5.1.x 在协程 Client 读超时时通常返回系统 errno 110 (ETIMEDOUT)；
 * 旧版可能为 -2 (SWOOLE_ERROR_SOCKET_POLL_TIMEOUT)。
 */
class IsTimeoutErrCodeTest extends CoroutineTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = new Connection('127.0.0.1:2181', 5000, 10000);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function knownTimeoutErrorCodesProvider(): array
    {
        $cases = [
            'linux_etimedout_110' => [110],
            'swoole_socket_poll_timeout_-2' => [-2],
        ];

        if (defined('SWOOLE_ERROR_CO_TIMEOUT')) {
            $cases['swoole_error_co_timeout'] = [SWOOLE_ERROR_CO_TIMEOUT];
        }
        if (defined('SOCKET_ETIMEDOUT')) {
            $cases['socket_etimedout'] = [SOCKET_ETIMEDOUT];
        }

        return $cases;
    }

    /**
     * @dataProvider knownTimeoutErrorCodesProvider
     */
    public function testRecognizesKnownTimeoutErrorCodes(int $errCode): void
    {
        $this->assertTrue(
            ReflectionHelper::invoke($this->connection, 'isTimeoutErrCode', $errCode),
            "errCode {$errCode} should be recognized as recv timeout"
        );
    }

    public function testRejectsNonTimeoutErrors(): void
    {
        $this->assertFalse(ReflectionHelper::invoke($this->connection, 'isTimeoutErrCode', 0));
        $this->assertFalse(ReflectionHelper::invoke($this->connection, 'isTimeoutErrCode', 104)); // ECONNRESET
        $this->assertFalse(ReflectionHelper::invoke($this->connection, 'isTimeoutErrCode', -1));
    }

    /**
     * 在真实 Swoole 5.1.x 环境下验证 recv 空闲超时的 errCode 可被正确识别。
     */
    public function testSwooleClientRecvTimeoutErrCodeIsRecognized(): void
    {
        $this->runInCoroutine(function () {
            $socket = new Socket(AF_INET, SOCK_STREAM, IPPROTO_IP);
            $this->assertTrue($socket->bind('127.0.0.1', 0));
            $this->assertTrue($socket->listen(1));

            $bound = $socket->getsockname();
            $port = $bound['port'];

            Coroutine::create(function () use ($socket) {
                $conn = $socket->accept();
                if ($conn) {
                    // 建立连接后不发送任何数据，等待对端读超时
                    Coroutine::sleep(5);
                    $conn->close();
                }
            });

            $client = new Client(SWOOLE_SOCK_TCP);
            $this->assertTrue($client->connect('127.0.0.1', $port, 1.0));

            $data = $client->recv(0.2);
            $errCode = $client->errCode;

            $client->close();
            $socket->close();

            $this->assertFalse($data, 'recv should return false on idle timeout');
            $this->assertTrue(
                ReflectionHelper::invoke($this->connection, 'isTimeoutErrCode', $errCode),
                sprintf(
                    'Swoole %s recv timeout errCode=%d (%s) should be treated as timeout',
                    SWOOLE_VERSION,
                    $errCode,
                    socket_strerror($errCode) ?: 'unknown'
                )
            );
        });
    }
}
