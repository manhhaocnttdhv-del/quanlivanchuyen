<?php

namespace App\Policies;

use App\Models\ShopBankAccount;
use App\Models\User;

class ShopBankAccountPolicy
{
    public function verify(User $user, ShopBankAccount $bank): bool
    {
        return $user->hasPermission('banks.verify') && $bank->shop->user_id !== $user->id
            && $bank->shop->current_bank_account_id === $bank->id;
    }
}
