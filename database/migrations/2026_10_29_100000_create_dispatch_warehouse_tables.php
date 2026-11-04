<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedSmallInteger('delivery_attempt_count')->default(0);
            $table->foreignId('return_authorized_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('return_reason')->nullable();
        });
        Schema::create('warehouse_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->string('code', 40);
            $table->string('name', 150);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['warehouse_id', 'code']);
        });
        Schema::create('delivery_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('shipper_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('type', 20);
            $table->string('status', 30)->default('assigned');
            $table->text('reason');
            $table->dateTime('finished_at')->nullable();
            // NULL không cạnh tranh unique; mỗi đơn chỉ có một nhiệm vụ còn hiệu lực.
            $table->unsignedBigInteger('active_order_id')->nullable()->storedAs("CASE WHEN status IN ('assigned','in_custody','recall_pending') THEN order_id ELSE NULL END")->unique();
            $table->timestamps();
            $table->index(['shipper_id', 'status', 'created_at']);
            $table->index(['warehouse_id', 'status', 'type']);
        });
        Schema::create('task_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_task_id')->constrained()->restrictOnDelete();
            $table->foreignId('shipper_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('event', 30);
            $table->text('reason');
            $table->timestamps();
        });
        Schema::create('warehouse_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('parcel_number');
            $table->string('holder_type', 20);
            $table->foreignId('holder_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_location_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('actual_weight_grams')->nullable();
            $table->decimal('actual_weight_kg', 10, 3)->nullable();
            $table->string('condition', 20)->default('good');
            $table->unsignedInteger('custody_version')->default(1);
            $table->timestamps();
            $table->unique(['order_id', 'parcel_number']);
            $table->index(['warehouse_id', 'holder_type', 'warehouse_location_id'], 'inventory_location');
            $table->index(['holder_user_id', 'holder_type']);
        });
        Schema::create('pickup_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_task_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->boolean('successful');
            $table->string('condition', 20);
            $table->string('evidence_path');
            $table->text('reason');
            $table->timestamps();
        });
        Schema::create('handover_documents', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->foreignId('delivery_task_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('shipper_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('direction', 20);
            $table->string('status', 20)->default('pending');
            $table->text('reason');
            $table->text('confirmation_reason')->nullable();
            $table->string('evidence_path')->nullable();
            $table->dateTime('confirmed_at')->nullable();
            $table->unsignedBigInteger('pending_task_id')->nullable()->storedAs("CASE WHEN status = 'pending' THEN delivery_task_id ELSE NULL END")->unique();
            $table->timestamps();
            $table->index(['warehouse_id', 'status', 'created_at']);
        });
        Schema::create('handover_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('handover_document_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_item_id')->constrained()->restrictOnDelete();
            $table->boolean('received')->nullable();
            $table->unsignedInteger('actual_weight_grams')->nullable();
            $table->string('condition', 20)->nullable();
            $table->string('from_holder', 30);
            $table->string('to_holder', 30)->nullable();
            $table->timestamps();
            $table->unique(['handover_document_id', 'warehouse_item_id'], 'handover_parcel');
        });
        Schema::create('stocktakes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_location_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('status', 20)->default('draft');
            $table->text('reason');
            $table->text('unexpected_note')->nullable();
            $table->text('approval_reason')->nullable();
            $table->dateTime('snapshot_at');
            $table->dateTime('approved_at')->nullable();
            $table->timestamps();
            $table->index(['warehouse_id', 'status']);
        });
        Schema::create('stocktake_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stocktake_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_item_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('expected_version');
            $table->string('location_snapshot', 150)->nullable();
            $table->boolean('actual_present')->nullable();
            $table->boolean('movement_detected')->default(false);
            $table->text('note')->nullable();
            $table->timestamps();
            $table->unique(['stocktake_id', 'warehouse_item_id']);
        });
        Schema::create('discrepancies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_item_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('handover_document_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('stocktake_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('type', 30);
            $table->string('severity', 20);
            $table->string('status', 20)->default('open');
            $table->boolean('blocks_dispatch')->default(true);
            $table->text('reason');
            $table->string('evidence_path')->nullable();
            $table->text('resolution_reason')->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['warehouse_id', 'status']);
            $table->index(['order_id', 'status', 'blocks_dispatch']);
        });
    }

    public function down(): void
    {
        foreach (['discrepancies', 'stocktake_lines', 'stocktakes', 'handover_lines', 'handover_documents', 'pickup_attempts', 'warehouse_items', 'task_assignments', 'delivery_tasks', 'warehouse_locations'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('return_authorized_by');
            $table->dropColumn(['delivery_attempt_count', 'return_reason']);
        });
    }
};
