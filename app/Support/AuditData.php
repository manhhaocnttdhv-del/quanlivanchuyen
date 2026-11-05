<?php

namespace App\Support;

class AuditData
{
    public static function sanitize(array $values, array $allowed): array
    {
        $clean = [];
        foreach ($values as $key => $value) {
            if (is_string($key) && ! in_array($key, $allowed, true)) {
                continue;
            }
            // Loc ca key long nhau: changes/items/rules khong duoc tro thanh loi vao log credential.
            if (is_array($value)) {
                $clean[$key] = self::sanitize($value, $allowed);
            } elseif (is_scalar($value) || $value === null) {
                $freeText = in_array($key, ['name', 'email', 'phone', 'address', 'recipient_name', 'recipient_phone', 'recipient_address', 'goods', 'title', 'conclusion', 'financial_reason', 'resolution'], true);
                $clean[$key] = is_string($value) ? self::text($value, $freeText) : $value;
            }
        }

        return $clean;
    }

    public static function text(?string $value, bool $maskNumbers = true): ?string
    {
        if ($value === null) {
            return null;
        }
        // Ly do tu do co the bi dan nham thong tin dang nhap/so tai khoan.
        $value = preg_replace('/\b(password|passwd|token|secret|session|account_number|so_tai_khoan)\s*[:=]\s*[^\s,;]+/iu', '$1=[REDACTED]', $value);

        return $maskNumbers ? preg_replace('/\b\d{8,}\b/u', '[REDACTED]', $value) : $value;
    }
}
