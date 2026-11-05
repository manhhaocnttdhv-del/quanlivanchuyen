<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->string('account_type', 20)->default('business')->index();
        });
        DB::table('roles')->where('name', 'shop')->update(['label' => 'Khách hàng gửi hàng']);
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropIndex(['account_type']);
            $table->dropColumn('account_type');
        });
        DB::table('roles')->where('name', 'shop')->update(['label' => 'Chủ cửa hàng']);
    }
};
