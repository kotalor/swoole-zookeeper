<?php

declare(strict_types=1);

namespace Swoole\Zookeeper\Protocol;

/**
 * 二进制缓冲区 - 用于读写Zookeeper协议数据
 */
class Buffer
{
    private string $buffer;
    private int $position = 0;
    
    public function __construct(string $buffer = '')
    {
        $this->buffer = $buffer;
    }
    
    /**
     * 获取完整缓冲区数据
     */
    public function getBuffer(): string
    {
        return $this->buffer;
    }
    
    /**
     * 获取缓冲区长度
     */
    public function length(): int
    {
        return strlen($this->buffer);
    }
    
    /**
     * 获取剩余可读字节数
     */
    public function remaining(): int
    {
        return strlen($this->buffer) - $this->position;
    }
    
    /**
     * 重置读取位置
     */
    public function rewind(): void
    {
        $this->position = 0;
    }
    
    /**
     * 获取当前位置
     */
    public function position(): int
    {
        return $this->position;
    }
    
    /**
     * 设置位置
     */
    public function setPosition(int $position): void
    {
        $this->position = $position;
    }
    
    // ==================== 写入方法 ====================
    
    /**
     * 写入原始数据
     */
    public function writeRaw(string $data): self
    {
        $this->buffer .= $data;
        return $this;
    }
    
    /**
     * 写入1字节整数
     */
    public function writeByte(int $value): self
    {
        $this->buffer .= pack('c', $value);
        return $this;
    }
    
    /**
     * 写入4字节有符号整数 (大端序)
     */
    public function writeInt(int $value): self
    {
        $this->buffer .= pack('N', $value);
        return $this;
    }
    
    /**
     * 写入8字节有符号整数 (大端序)
     */
    public function writeLong(int $value): self
    {
        // PHP的pack不直接支持大端序的64位有符号整数
        // 我们需要手动处理
        $high = ($value >> 32) & 0xFFFFFFFF;
        $low = $value & 0xFFFFFFFF;
        $this->buffer .= pack('N', $high) . pack('N', $low);
        return $this;
    }
    
    /**
     * 写入布尔值
     */
    public function writeBool(bool $value): self
    {
        $this->buffer .= pack('c', $value ? 1 : 0);
        return $this;
    }
    
    /**
     * 写入字符串 (带长度前缀)
     */
    public function writeString(?string $value): self
    {
        if ($value === null) {
            $this->writeInt(-1);
        } else {
            $this->writeInt(strlen($value));
            $this->buffer .= $value;
        }
        return $this;
    }
    
    /**
     * 写入二进制数据 (带长度前缀)
     */
    public function writeBuffer(?string $data): self
    {
        return $this->writeString($data);
    }
    
    /**
     * 写入字符串数组
     */
    public function writeStringArray(array $strings): self
    {
        $this->writeInt(count($strings));
        foreach ($strings as $str) {
            $this->writeString($str);
        }
        return $this;
    }
    
    // ==================== 读取方法 ====================
    
    /**
     * 读取指定长度的原始数据
     */
    public function readRaw(int $length): string
    {
        if ($this->position + $length > strlen($this->buffer)) {
            throw new \RuntimeException("Buffer underflow: need {$length} bytes, only " . $this->remaining() . " available");
        }
        $data = substr($this->buffer, $this->position, $length);
        $this->position += $length;
        return $data;
    }
    
    /**
     * 读取1字节整数
     */
    public function readByte(): int
    {
        $data = $this->readRaw(1);
        $result = unpack('c', $data);
        return $result[1];
    }
    
    /**
     * 读取4字节有符号整数 (大端序)
     */
    public function readInt(): int
    {
        $data = $this->readRaw(4);
        $result = unpack('N', $data);
        $value = $result[1];
        // 处理有符号整数
        if ($value >= 0x80000000) {
            $value -= 0x100000000;
        }
        return $value;
    }
    
    /**
     * 读取8字节有符号整数 (大端序)
     */
    public function readLong(): int
    {
        $high = $this->readInt();
        $low = $this->readInt();
        // 重新组合为64位整数
        if ($low < 0) {
            $low += 0x100000000;
        }
        return ($high << 32) | $low;
    }
    
    /**
     * 读取布尔值
     */
    public function readBool(): bool
    {
        return $this->readByte() !== 0;
    }
    
    /**
     * 读取字符串 (带长度前缀)
     */
    public function readString(): ?string
    {
        $length = $this->readInt();
        if ($length < 0) {
            return null;
        }
        if ($length === 0) {
            return '';
        }
        return $this->readRaw($length);
    }
    
    /**
     * 读取二进制数据 (带长度前缀)
     */
    public function readBuffer(): ?string
    {
        return $this->readString();
    }
    
    /**
     * 读取字符串数组
     */
    public function readStringArray(): array
    {
        $count = $this->readInt();
        $result = [];
        for ($i = 0; $i < $count; $i++) {
            $result[] = $this->readString();
        }
        return $result;
    }
    
    /**
     * 预览数据但不移动指针
     */
    public function peek(int $length): string
    {
        if ($this->position + $length > strlen($this->buffer)) {
            throw new \RuntimeException("Buffer underflow");
        }
        return substr($this->buffer, $this->position, $length);
    }
    
    /**
     * 预览4字节整数但不移动指针
     */
    public function peekInt(): int
    {
        $data = $this->peek(4);
        $result = unpack('N', $data);
        $value = $result[1];
        if ($value >= 0x80000000) {
            $value -= 0x100000000;
        }
        return $value;
    }
    
    /**
     * 清空缓冲区
     */
    public function clear(): void
    {
        $this->buffer = '';
        $this->position = 0;
    }
    
    /**
     * 丢弃已读取的数据
     */
    public function compact(): void
    {
        $this->buffer = substr($this->buffer, $this->position);
        $this->position = 0;
    }
    
    /**
     * 追加数据
     */
    public function append(string $data): void
    {
        $this->buffer .= $data;
    }
}

