<?php

declare(strict_types=1);

namespace Swoole\Zookeeper\Protocol\Response;

use Swoole\Zookeeper\Protocol\Buffer;

/**
 * 节点状态信息
 */
class Stat
{
    public function __construct(
        public int $czxid,           // 创建该节点的事务id
        public int $mzxid,           // 最后修改该节点的事务id
        public int $ctime,           // 创建时间
        public int $mtime,           // 最后修改时间
        public int $version,         // 数据版本号
        public int $cversion,        // 子节点版本号
        public int $aversion,        // ACL版本号
        public int $ephemeralOwner,  // 临时节点的owner session id，非临时节点为0
        public int $dataLength,      // 数据长度
        public int $numChildren,     // 子节点数量
        public int $pzxid            // 最后修改子节点的事务id
    ) {}
    
    public static function parse(Buffer $buffer): self
    {
        return new self(
            $buffer->readLong(),
            $buffer->readLong(),
            $buffer->readLong(),
            $buffer->readLong(),
            $buffer->readInt(),
            $buffer->readInt(),
            $buffer->readInt(),
            $buffer->readLong(),
            $buffer->readInt(),
            $buffer->readInt(),
            $buffer->readLong()
        );
    }
    
    /**
     * 转换为数组格式，与PHP Zookeeper扩展兼容
     */
    public function toArray(): array
    {
        return [
            'czxid' => $this->czxid,
            'mzxid' => $this->mzxid,
            'ctime' => $this->ctime,
            'mtime' => $this->mtime,
            'version' => $this->version,
            'cversion' => $this->cversion,
            'aversion' => $this->aversion,
            'ephemeralOwner' => $this->ephemeralOwner,
            'dataLength' => $this->dataLength,
            'numChildren' => $this->numChildren,
            'pzxid' => $this->pzxid,
        ];
    }
}

