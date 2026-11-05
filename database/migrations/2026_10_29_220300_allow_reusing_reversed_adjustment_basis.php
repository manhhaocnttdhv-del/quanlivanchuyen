<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('adjustments', function (Blueprint $table) {
            // Chung tu goc van approved sau reversal; Service khoa su co va loai ban da dao.
            $table->dropUnique(['active_incident_id']);
            $table->index(['incident_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('adjustments', function (Blueprint $table) {
            $table->dropIndex(['incident_id', 'status']);
            $table->unique('active_incident_id');
        });
    }
};
