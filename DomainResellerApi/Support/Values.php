<?php

namespace Paymenter\Extensions\Servers\DomainResellerApi\Support;

/**
 * Paymenter stores extension settings and service properties as strings,
 * booleans or JSON depending on the field type and the code path that wrote
 * them. These helpers read them back consistently.
 */
final class Values
{
    public static function toBool(mixed $value, bool $default = false): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return $value != 0;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on', 'y'], true);
    }

    public static function toInt(mixed $value, int $default = 0): int
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return $default;
        }

        return (int) $value;
    }

    /**
     * @return string[]
     */
    public static function toList(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if (str_starts_with($trimmed, '[')) {
                $decoded = json_decode($trimmed, true);
                if (is_array($decoded)) {
                    $value = $decoded;
                }
            }
        }

        if (is_string($value)) {
            $value = preg_split('/[\s,;]+/', $value) ?: [];
        }

        if (!is_array($value)) {
            return [];
        }

        $list = [];
        foreach ($value as $item) {
            if (is_scalar($item) && trim((string) $item) !== '') {
                $list[] = trim((string) $item);
            }
        }

        return $list;
    }
}
