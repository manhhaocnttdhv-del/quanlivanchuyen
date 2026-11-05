<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE adjustment_lines DROP CHECK adjustment_source');
        Schema::table('adjustment_lines', fn (Blueprint $table) => $table->decimal('verified_amount', 15, 2)->nullable());
        DB::statement('ALTER TABLE adjustment_lines ADD CONSTRAINT adjustment_source CHECK (((delivery_collection_id IS NOT NULL) + (receivable_id IS NOT NULL) + (order_id IS NOT NULL) = 1) AND (amount <> 0 OR (delivery_collection_id IS NOT NULL AND verified_amount IS NOT NULL)))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE adjustment_lines DROP CHECK adjustment_source');
        Schema::table('adjustment_lines', fn (Blueprint $table) => $table->dropColumn('verified_amount'));
        DB::statement('ALTER TABLE adjustment_lines ADD CONSTRAINT adjustment_source CHECK (amount <> 0 AND ((delivery_collection_id IS NOT NULL) + (receivable_id IS NOT NULL) + (order_id IS NOT NULL) = 1))');
    }
};
