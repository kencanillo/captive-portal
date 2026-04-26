<?php

namespace App\Support;

use RuntimeException;

final class MacAddress
{
    public static function normalizeForStorage(mixed $value): ?string
    {
        return self::normalize($value, false);
    }

    public static function normalizeForDisplay(mixed $value): ?string
    {
        return self::normalize($value, true);
    }

    public static function equals(mixed $left, mixed $right): bool
    {
        $normalizedLeft = self::normalizeForStorage($left);
        $normalizedRight = self::normalizeForStorage($right);

        return $normalizedLeft !== null && $normalizedLeft === $normalizedRight;
    }

    public static function toPath(mixed $value): string
    {
        $normalized = self::normalizeForDisplay($value);

        if ($normalized === null) {
            throw new RuntimeException('A valid MAC address is required.');
        }

        return str_replace(':', '-', $normalized);
    }

    private static function normalize(mixed $value, bool $uppercase): ?string
    {
        $mac = preg_replace('/[^A-Fa-f0-9]/', '', trim((string) $value)) ?? '';

        if (strlen($mac) !== 12) {
            return null;
        }

        $mac = $uppercase ? strtoupper($mac) : strtolower($mac);

        return implode(':', str_split($mac, 2));
    }
}
