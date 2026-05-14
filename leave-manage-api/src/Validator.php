<?php

declare(strict_types=1);

final class Validator
{
    public static function requiredString(mixed $value, string $field, int $maxLength = 255): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException($field . ' is required.', 422);
        }

        $normalized = trim($value);
        if ($normalized === '') {
            throw new InvalidArgumentException($field . ' is required.', 422);
        }

        if (mb_strlen($normalized) > $maxLength) {
            throw new InvalidArgumentException($field . ' exceeds the maximum length.', 422);
        }

        return $normalized;
    }

    public static function optionalString(mixed $value, string $field, int $maxLength = 255): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_string($value)) {
            throw new InvalidArgumentException($field . ' must be a string.', 422);
        }

        $normalized = trim($value);
        if ($normalized === '') {
            return null;
        }

        if (mb_strlen($normalized) > $maxLength) {
            throw new InvalidArgumentException($field . ' exceeds the maximum length.', 422);
        }

        return $normalized;
    }

    public static function requiredInt(mixed $value, string $field): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', trim($value))) {
            return (int) trim($value);
        }

        throw new InvalidArgumentException($field . ' must be an integer.', 422);
    }

    public static function optionalInt(mixed $value, string $field): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::requiredInt($value, $field);
    }

    public static function requiredNumeric(mixed $value, string $field): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric(trim($value))) {
            return (float) trim($value);
        }

        throw new InvalidArgumentException($field . ' must be numeric.', 422);
    }

    public static function requiredDate(mixed $value, string $field): string
    {
        $date = self::requiredString($value, $field, 20);
        $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $date);

        if (!$parsed || $parsed->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException($field . ' must be in YYYY-MM-DD format.', 422);
        }

        return $date;
    }

    public static function optionalDate(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::requiredDate($value, $field);
    }

    public static function requiredMobile(mixed $value): string
    {
        $mobile = preg_replace('/\D+/', '', self::requiredString($value, 'mobile', 20));
        if ($mobile === null || strlen($mobile) < 10 || strlen($mobile) > 15) {
            throw new InvalidArgumentException('mobile must contain 10 to 15 digits.', 422);
        }

        return $mobile;
    }

    public static function requiredPin(mixed $value, string $field = 'pin'): string
    {
        $pin = self::requiredString($value, $field, 20);
        if (!preg_match('/^\d{4,6}$/', $pin)) {
            throw new InvalidArgumentException($field . ' must be a 4 to 6 digit PIN.', 422);
        }

        return $pin;
    }

    public static function enum(mixed $value, string $field, array $allowed): string
    {
        $normalized = self::requiredString($value, $field, 50);
        if (!in_array($normalized, $allowed, true)) {
            throw new InvalidArgumentException($field . ' has an invalid value.', 422);
        }

        return $normalized;
    }

    public static function optionalEnum(mixed $value, string $field, array $allowed): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::enum($value, $field, $allowed);
    }

    public static function booleanValue(mixed $value, string $field): int
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        if (is_int($value) && ($value === 0 || $value === 1)) {
            return $value;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['1', 'true', 'yes'], true)) {
                return 1;
            }

            if (in_array($normalized, ['0', 'false', 'no'], true)) {
                return 0;
            }
        }

        throw new InvalidArgumentException($field . ' must be a boolean.', 422);
    }
}
