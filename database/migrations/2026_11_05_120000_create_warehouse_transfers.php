<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('origin_warehouse_id')->nullable()->constrained('warehouses')->restrictOnDelete();
        });
        DB::table('orders')->update(['origin_warehouse_id' => DB::raw('warehouse_id')]);

        Schema::create('warehouse_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('source_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('destination_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('purpose', 20);
            $table->string('status', 20)->default('in_transit');
            $table->string('carrier', 150);
            $table->string('transport_reference', 150);
            $table->text('reason');
            $table->dateTime('dispatched_at');
            $table->dateTime('received_at')->nullable();
            $table->timestamps();
            $table->unsignedBigInteger('active_order_id')->nullable()->storedAs("CASE WHEN status IN ('in_transit', 'partial') THEN order_id ELSE NULL END");
            $table->unique('active_order_id');
            $table->index(['destination_warehouse_id', 'status']);
            $table->index(['source_warehouse_id', 'status']);
        });
        Schema::create('warehouse_transfer_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_transfer_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('warehouse_location_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('dispatched_custody_version');
            $table->string('condition', 20)->nullable();
            $table->string('evidence_path')->nullable();
            $table->text('receipt_reason')->nullable();
            $table->dateTime('received_at')->nullable();
            $table->timestamps();
            $table->unique(['warehouse_transfer_id', 'warehouse_item_id'], 'transfer_item_unique');
        });
        Schema::table('warehouse_items', function (Blueprint $table) {
            $table->foreignId('warehouse_transfer_id')->nullable()->constrained()->restrictOnDelete();
        });
        Schema::table('discrepancies', function (Blueprint $table) {
            $table->foreignId('warehouse_transfer_id')->nullable()->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('discrepancies', fn (Blueprint $table) => $table->dropConstrainedForeignId('warehouse_transfer_id'));
        Schema::table('warehouse_items', fn (Blueprint $table) => $table->dropConstrainedForeignId('warehouse_transfer_id'));
        Schema::dropIfExists('warehouse_transfer_lines');
        Schema::dropIfExists('warehouse_transfers');
        Schema::table('orders', fn (Blueprint $table) => $table->dropConstrainedForeignId('origin_warehouse_id'));
    }
};
