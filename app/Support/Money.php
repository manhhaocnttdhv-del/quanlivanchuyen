<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

class Money
{
    public const MAX = 9999999999999;

    public static function value(mixed $value): int
    {
        // VND chi nhan dong nguyen; DECIMAL tu MySQL luon la chuoi, khong tinh bang float.
        if (is_float($value) || ! preg_match('/^-?\d{1,16}(?:\.00)?$/D', (string) $value)) {
            throw ValidationException::withMessages(['amount' => 'Số tiền phải là số nguyên đồng.']);
        }

        return (int) $value;
    }

    public static function bounded(int $value): int
    {
        if (abs($value) > self::MAX) {
            throw ValidationException::withMessages(['amount' => 'Tổng tiền vượt giới hạn DECIMAL(15,2).']);
        }

        return $value;
    }
}
