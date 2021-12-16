<?php

namespace Ulid\Internal;

use OverflowException;

/**
 * A class to manipulate byte array (and an array like it)
 * Stores internally in little endian, but represents it in big endian
 *
 * example:
 * $a = new ByteArray([1, 0]);
 * $b = new ByteArray([1]);
 * $a->add($b)->toBytes() === (new ByteArray([1, 1]))->toBytes();
 *
 * @internal
 */
class ByteArray
{
    /** @var int $bits */
    protected $bits;

    /** @var int[] $arrays */
    protected $values;

    /**
     * @param int[] $values
     * @param int|null $bits
     */
    public function __construct(array $values, int $bits = 8)
    {
        $this->bits = $bits;
        $this->values = array_reverse($values);
        foreach ($this->values as $value) {
            assert(is_int($value));
            assert($value < 2**$this->bits);
        }
    }

    /**
     * @param string $value
     * @return static
     */
    public static function fromBytes(string $value)
    {
        return new static(array_values(unpack('C*', $value)));
    }

    /**
     * @param int $value
     * @return static
     */
    public static function fromInt(int $value)
    {
        return static::fromBytes(pack("J", $value));
    }

    /**
     * @param int|array|self $target
     * @return static
     */
    public function add($target)
    {
        if (is_int($target)) {
            return $this->add(static::fromInt($target));
        } else if (is_array($target)) {
            return $this->add(new static($target));
        }
        assert($target instanceof self);
        assert($this->bits === $target->bits);

        $target = $target->trim();
        $result = [];
        $carry = 0;
        $i = 0;
        if (count($target->values) > count($this->values)) {
            throw new OverflowException();
        }
        while ($i < count($this->values)) {
            $sum = $i < count($target->values) ? $target->values[$i] : 0;
            $sum += $carry + $this->values[$i];
            $carry = $sum >> $this->bits;
            $result[] = $sum & (2**$this->bits-1);
            $i++;
        }
        if ($carry) {
            throw new OverflowException();
        }
        return new static(array_reverse($result), $this->bits);
    }

    /**
     * @param int $bits
     * @param int $length
     * @return static
     */
    public function convertBits(int $bits, int $length = null)
    {
        $result = [];
        $i = 0;

        $carry = 0;
        $carriedBits = 0;

        while(count($result) < ($length ?: PHP_INT_MAX) && $i < count($this->values)) {
            $bitDiff = $carriedBits + $this->bits - $bits;
            if ($bitDiff < 0) {
                $carry <<= $this->bits;
                $carry += $this->values[$i];
                $carriedBits += $this->bits;
            } else {
                $maskBits = $bits - $carriedBits;
                if ($maskBits < 0) {
                    $result[] = $carry & (2** $bits -1);
                    $carry >>= $bits;
                    $carriedBits -= $bits;
                    continue;
                } else {
                    $item = $this->values[$i] & (2**$maskBits-1);
                    $item <<= $carriedBits;
                    $item += $carry;
                    $result[] = $item;
                    $carry = $this->values[$i] >> $maskBits;
                    $carriedBits = $this->bits - $maskBits;
                }
            }
            $i++;
        }
        while ($length ? count($result) < $length : $carry) {
            $result[] = $carry & (2** $bits -1);
            $carry >>= $bits;
        }
        return new static(array_reverse($result), $bits);
    }

    /**
     * slice the byte array from the lower end
     *
     * @param int $offset
     * @param int $length
     * @return static
     */
    public function chomp(int $offset = null, int $length = null)
    {
        if (is_null($offset) && is_null($length)) {
            $offset = count($this->values);
        } else if (is_null($offset)) {
            $offset = 0;
        } else if (is_null($length)) {
            $length = $offset;
            $offset = 0;
        }
        return new static(array_reverse(array_slice($this->values, $offset, $length)), $this->bits);
    }

    /**
     * slice the byte array from the higher end
     *
     * @param int $offset
     * @param int $length
     * @return static
     */
    public function slice(int $offset = null, int $length = null)
    {
        if (is_null($offset) && is_null($length)) {
            $offset = count($this->values);
        } else if (is_null($offset)) {
            $offset = 0;
        } else if (is_null($length)) {
            $length = $offset;
            $offset = 0;
        }
        return new static(array_slice(array_reverse($this->values), $offset, $length), $this->bits);
    }

    /**
     * Drop the higher 0s
     *
     * @return static
     */
    public function trim()
    {
        $trimmed = [];
        foreach ($this->toArray() as $item) {
            if (! $item) {
                continue;
            }
            $trimmed[] = $item;
        }
        return new static($trimmed, $this->bits);
    }

    public function toBytes(): string
    {
        return pack("C*", ...array_reverse($this->convertBits(8)->values));
    }

    public function toArray(): array
    {
        return array_reverse($this->values);
    }
}