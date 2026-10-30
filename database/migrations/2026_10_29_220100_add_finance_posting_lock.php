<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('business_locks')->insert(['name' => 'finance-posting']);
    }

    public function down(): void
    {
        DB::table('business_locks')->where('name', 'finance-posting')->delete();
    }
};
