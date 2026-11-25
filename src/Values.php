<?php

declare(strict_types=1);

namespace AgentAutomation;

final class Values
{
    public static function json(mixed $value): string
    {
        return json_encode(self::ordered($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function decode(string $value): array
    {
        $result = json_decode($value, true, 48, JSON_THROW_ON_ERROR);
        if (!is_array($result)) {
            throw Problem::invalid('Expected a JSON object or array.');
        }
        return $result;
    }

    public static function digest(mixed $value): string
    {
        return hash('sha256', self::json($value));
    }

    public static function identifier(mixed $value, string $label): string
    {
        if (!is_string($value) || preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $value) !== 1) {
            throw Problem::invalid("Invalid $label.");
        }
        return $value;
    }

    public static function text(mixed $value, string $label, int $limit, int $minimum = 1): string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || strlen($value) < $minimum ||
            strlen($value) > $limit || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value) === 1) {
            throw Problem::invalid("Invalid $label.");
        }
        return $value;
    }

    public static function email(mixed $value): string
    {
        $value = self::text($value, 'recipient', 254);
        if (filter_var($value, FILTER_VALIDATE_EMAIL) === false || str_contains($value, "\n") || str_contains($value, "\r")) {
            throw Problem::invalid('Invalid recipient.');
        }
        return $value;
    }

    public static function number(mixed $value, string $label, int $min, int $max): int
    {
        if (!is_int($value) || $value < $min || $value > $max) {
            throw Problem::invalid("Invalid $label.");
        }
        return $value;
    }

    private static function ordered(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = self::ordered($item);
        }
        return $value;
    }
}
