<?php

declare(strict_types=1);

namespace Swoole\Zookeeper\Protocol\Request;

use Swoole\Zookeeper\Protocol\Buffer;
use Swoole\Zookeeper\Protocol\OpCode;

/**
 * 重放Watch请求 (SET_WATCHES)
 *
 * 断线重连后用于把客户端本地仍然有效的watch一次性重新注册到服务端。
 */
class SetWatchesRequest
{
    public function __construct(
        public int $relativeZxid,
        public array $dataWatches = [],
        public array $existWatches = [],
        public array $childWatches = []
    ) {}

    public function serialize(Buffer $buffer, int $xid): void
    {
        $header = new RequestHeader($xid, OpCode::SET_WATCHES);
        $header->serialize($buffer);

        $buffer->writeLong($this->relativeZxid);
        $buffer->writeStringArray($this->dataWatches);
        $buffer->writeStringArray($this->existWatches);
        $buffer->writeStringArray($this->childWatches);
    }

    public function pack(int $xid): string
    {
        $body = new Buffer();
        $this->serialize($body, $xid);

        $packet = new Buffer();
        $packet->writeInt($body->length());
        $packet->writeRaw($body->getBuffer());

        return $packet->getBuffer();
    }
}
