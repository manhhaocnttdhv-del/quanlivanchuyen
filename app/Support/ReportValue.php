<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

class ReportValue
{
    public static function integer(mixed $value): int
    {
        // Tong bao cao co the lon hon mot chung tu, nhung van phai vua so nguyen PHP 64 bit.
        if (is_float($value) || ! preg_match('/^-?\d{1,18}(?:\.00)?$/D', (string) $value)) {
            throw ValidationException::withMessages(['until' => 'Tổng tiền quá lớn; hãy thu hẹp kỳ báo cáo.']);
        }

        // Bo phan .00 truoc khi ep kieu: PHP co the qua float neu chuoi con dau thap phan.
        return (int) explode('.', (string) $value, 2)[0];
    }

    public static function display(mixed $value, string $format): string
    {
        if ($value === null || $value === '') {
            return '-';
        }
        if ($format === 'date') {
            return Carbon::parse($value, config('app.timezone'))->setTimezone('Asia/Ho_Chi_Minh')->format('d/m/Y H:i');
        }
        if ($format === 'percent') {
            return number_format((float) $value, 1, ',', '.').'%';
        }
        if (in_array($format, ['money', 'number'])) {
            // Dinh dang chuoi so nguyen de tong lon khong bi lam tron qua float.
            return preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', (string) self::integer($value)).($format === 'money' ? 'đ' : '');
        }

        return (string) $value;
    }
}
