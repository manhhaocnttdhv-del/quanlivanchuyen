<?php

namespace App\Support;

use App\Models\Adjustment;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FinanceRules
{
    public static function money(bool $zero = false): array
    {
        return ['required', 'regex:/^\d{1,13}$/D', 'integer', 'min:'.($zero ? 0 : 1), 'max:'.Money::MAX];
    }

    public static function reason(): array
    {
        return ['required', 'string', 'min:5', 'max:2000'];
    }

    public static function base(): array
    {
        return ['client_token' => ['required', 'uuid'], 'reason' => self::reason()];
    }

    public static function allocations(string $name): array
    {
        return [$name => ['required', 'array', 'min:1', 'max:100'], $name.'.*' => self::money(true)];
    }

    public static function deposit(): array
    {
        return self::base() + ['warehouse_id' => ['required', 'integer'], 'shipper_id' => ['required', 'integer']] + self::allocations('allocations');
    }

    public static function confirmation(): array
    {
        return ['revision' => ['required', 'integer', 'min:1'], 'reason' => self::reason(), 'received_amount' => self::money(true), 'transaction_reference' => ['required', 'string', 'regex:/^[A-Za-z0-9._:\/-]{4,100}$/D'], 'allocations' => ['present', 'array', 'max:100'], 'allocations.*' => self::money(true)];
    }

    public static function selection(): array
    {
        return ['cod' => ['present', 'array', 'max:100'], 'cod.*' => self::money(true), 'fees' => ['present', 'array', 'max:100'], 'fees.*' => self::money(true),
            'adjustment_ids' => ['present', 'array', 'max:100'], 'adjustment_ids.*' => ['integer', 'distinct']];
    }

    public static function reconciliation(): array
    {
        return self::base() + ['warehouse_id' => ['required', 'integer'], 'shop_id' => ['required', 'integer'], 'period_start' => ['required', 'date_format:Y-m-d'], 'period_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_start']] + self::selection();
    }

    public static function payment(): array
    {
        return self::base() + ['amount' => self::money(), 'status' => ['required', Rule::in(['succeeded', 'failed'])], 'transaction_reference' => ['required', 'string', 'regex:/^[A-Za-z0-9._:\/-]{4,100}$/D']];
    }

    public static function receipt(): array
    {
        return self::base() + ['warehouse_id' => ['required', 'integer'], 'shop_id' => ['required', 'integer'], 'amount' => self::money(), 'transaction_reference' => ['required', 'string', 'regex:/^[A-Za-z0-9._:\/-]{4,100}$/D']] + self::allocations('allocations');
    }

    public static function adjustment(): array
    {
        return self::base() + ['kind' => ['required', Rule::in(array_keys(Adjustment::KINDS))], 'amount' => self::money(), 'incident_id' => ['nullable', 'integer'], 'reverses_adjustment_id' => ['nullable', 'integer'],
            'delivery_collection_id' => ['nullable', 'integer'], 'receivable_id' => ['nullable', 'integer']];
    }

    public static function action(): array
    {
        return ['revision' => ['required', 'integer', 'min:1'], 'reason' => self::reason()];
    }

    public static function evidence(bool $required = false): array
    {
        return [$required ? 'required' : 'nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'];
    }

    public static function amounts(array $values): array
    {
        $result = [];
        foreach ($values as $id => $value) {
            if (! ctype_digit((string) $id) || (int) $id < 1) {
                throw ValidationException::withMessages(['allocations' => 'Mã dòng nguồn không hợp lệ.']);
            }
            if (Money::value($value) > 0) {
                $result[(int) $id] = Money::value($value);
            }
        }
        ksort($result);

        return $result;
    }
}
