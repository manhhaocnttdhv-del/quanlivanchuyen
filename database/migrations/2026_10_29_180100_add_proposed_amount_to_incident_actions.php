<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incident_actions', function (Blueprint $table) {
            // So tien de xuat/duyet la can cu, chua phai giao dich chi tien.
            $table->decimal('proposed_amount', 15, 2)->nullable();
        });
        DB::statement('ALTER TABLE incident_actions ADD CONSTRAINT incident_amount_nonnegative CHECK (proposed_amount IS NULL OR proposed_amount >= 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE incident_actions DROP CHECK incident_amount_nonnegative');
        Schema::table('incident_actions', fn (Blueprint $table) => $table->dropColumn('proposed_amount'));
    }
};
