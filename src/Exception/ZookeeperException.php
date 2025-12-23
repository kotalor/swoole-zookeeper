<?php

declare(strict_types=1);

namespace Swoole\Zookeeper\Exception;

use Swoole\Zookeeper\Protocol\ErrorCode;

/**
 * Zookeeper异常基类
 */
class ZookeeperException extends \Exception
{
    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null)
    {
        if ($message === '' && $code !== 0) {
            $message = ErrorCode::getMessage($code);
        }
        parent::__construct($message, $code, $previous);
    }
    
    /**
     * 根据错误码创建对应的异常
     */
    public static function fromCode(int $code, string $message = ''): self
    {
        if ($message === '') {
            $message = ErrorCode::getMessage($code);
        }
        
        return match ($code) {
            ErrorCode::NO_NODE => new NoNodeException($message, $code),
            ErrorCode::NO_AUTH => new NoAuthException($message, $code),
            ErrorCode::BAD_VERSION => new BadVersionException($message, $code),
            ErrorCode::NODE_EXISTS => new NodeExistsException($message, $code),
            ErrorCode::NOT_EMPTY => new NotEmptyException($message, $code),
            ErrorCode::SESSION_EXPIRED => new SessionExpiredException($message, $code),
            ErrorCode::CONNECTION_LOSS => new ConnectionLossException($message, $code),
            ErrorCode::AUTH_FAILED => new AuthFailedException($message, $code),
            ErrorCode::INVALID_ACL => new InvalidAclException($message, $code),
            ErrorCode::BAD_ARGUMENTS => new BadArgumentsException($message, $code),
            ErrorCode::OPERATION_TIMEOUT => new OperationTimeoutException($message, $code),
            default => new self($message, $code),
        };
    }
}

