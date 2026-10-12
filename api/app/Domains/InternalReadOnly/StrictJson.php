<?php

namespace App\Domains\InternalReadOnly;

/** JSON syntax is validated by PHP; this bounded scanner additionally rejects duplicate keys. */
final class StrictJson
{
    private int $offset = 0;

    private function __construct(private readonly string $source) {}

    public static function object(string $source): \stdClass
    {
        try {
            $value = json_decode($source, false, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            if (!$value instanceof \stdClass) {
                throw new \UnexpectedValueException();
            }
            $scanner = new self($source);
            $scanner->value(0);
            $scanner->space();
            if ($scanner->offset !== strlen($source)) {
                throw new \UnexpectedValueException();
            }
            return $value;
        } catch (\Throwable) {
            throw new SliceFailure('INVALID_REQUEST', 400);
        }
    }

    public static function exact(\stdClass $object, array $keys): void
    {
        $actual = array_keys(get_object_vars($object));
        sort($actual);
        sort($keys);
        if ($actual !== $keys) {
            throw new SliceFailure('INVALID_REQUEST', 400);
        }
    }

    private function space(): void
    {
        while (isset($this->source[$this->offset]) && str_contains(" \t\r\n", $this->source[$this->offset])) {
            $this->offset++;
        }
    }

    private function string(): string
    {
        $start = $this->offset++;
        while (isset($this->source[$this->offset])) {
            $char = $this->source[$this->offset++];
            if ($char === '\\') {
                $this->offset++;
            } elseif ($char === '"') {
                return json_decode(substr($this->source, $start, $this->offset - $start), true, 32, JSON_THROW_ON_ERROR);
            }
        }
        throw new \UnexpectedValueException();
    }

    private function value(int $depth): void
    {
        if ($depth > 30) {
            throw new \UnexpectedValueException();
        }
        $this->space();
        $char = $this->source[$this->offset] ?? '';
        if ($char === '"') {
            $this->string();
            return;
        }
        if ($char === '{' || $char === '[') {
            $object = $char === '{';
            $end = $object ? '}' : ']';
            $this->offset++;
            $this->space();
            $seen = [];
            if (($this->source[$this->offset] ?? '') === $end) {
                $this->offset++;
                return;
            }
            do {
                $this->space();
                if ($object) {
                    if (($this->source[$this->offset] ?? '') !== '"') {
                        throw new \UnexpectedValueException();
                    }
                    $key = $this->string();
                    // Prefix avoids PHP converting numeric keys into integers.
                    if (isset($seen['k:'.$key])) {
                        throw new \UnexpectedValueException();
                    }
                    $seen['k:'.$key] = true;
                    $this->space();
                    if (($this->source[$this->offset++] ?? '') !== ':') {
                        throw new \UnexpectedValueException();
                    }
                }
                $this->value($depth + 1);
                $this->space();
                $separator = $this->source[$this->offset++] ?? '';
                if ($separator === $end) {
                    return;
                }
                if ($separator !== ',') {
                    throw new \UnexpectedValueException();
                }
            } while (true);
        }
        $start = $this->offset;
        while (isset($this->source[$this->offset]) && !str_contains(" \t\r\n,]}", $this->source[$this->offset])) {
            $this->offset++;
        }
        if ($start === $this->offset) {
            throw new \UnexpectedValueException();
        }
    }
}
