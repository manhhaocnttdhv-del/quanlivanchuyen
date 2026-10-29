<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fee_rules', fn (Blueprint $table) => $table->decimal('return_fee', 15, 2)->default(0));
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedSmallInteger('return_attempt_count')->default(0);
            $table->unsignedSmallInteger('delivery_attempt_limit')->default(3);
            $table->unsignedSmallInteger('retry_authorized_attempt')->nullable();
            $table->boolean('financial_hold')->default(false);
            $table->dateTime('delivered_at')->nullable();
            $table->dateTime('returned_at')->nullable();
        });
        Schema::create('delivery_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('decision', 30);
            $table->unsignedSmallInteger('attempt_count');
            $table->unsignedSmallInteger('authorized_attempt')->nullable();
            $table->text('shop_instruction');
            $table->text('reason');
            $table->timestamps();
            $table->index(['order_id', 'created_at']);
        });
        Schema::create('return_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('authorized_by')->constrained('users')->restrictOnDelete();
            $table->string('status', 20)->default('authorized');
            $table->string('fee_payment', 30)->default('reconciliation');
            $table->decimal('fee_amount', 15, 2);
            $table->json('fee_snapshot');
            $table->text('reason');
            $table->dateTime('returned_at')->nullable();
            $table->dateTime('fee_charged_at')->nullable();
            $table->timestamps();
            $table->index(['warehouse_id', 'status']);
        });
        Schema::create('delivery_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('delivery_task_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('return_record_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('shipper_id')->constrained('users')->restrictOnDelete();
            $table->string('type', 20);
            $table->unsignedSmallInteger('attempt_number');
            $table->string('outcome', 20);
            $table->string('reason_code', 30)->nullable();
            $table->text('reason');
            $table->string('financial_status', 20)->default('matched');
            $table->text('financial_reason')->nullable();
            foreach (['expected_cod', 'expected_fee', 'actual_cod', 'actual_fee'] as $field) {
                $table->decimal($field, 15, 2)->default(0);
            }
            $table->uuid('client_token');
            $table->char('request_hash', 64);
            $table->dateTime('attempted_at');
            $table->timestamps();
            $table->unique(['shipper_id', 'client_token']);
            $table->unique(['order_id', 'type', 'attempt_number']);
            $table->index(['shipper_id', 'attempted_at']);
        });
        Schema::create('delivery_proofs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_attempt_id')->unique()->constrained()->restrictOnDelete();
            $table->string('receiver_name', 150)->nullable();
            $table->string('evidence_path')->nullable();
            $table->char('content_sha256', 64)->nullable();
            $table->timestamps();
        });
        Schema::create('delivery_collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('delivery_attempt_id')->constrained()->restrictOnDelete();
            $table->foreignId('shipper_id')->constrained('users')->restrictOnDelete();
            $table->string('type', 20);
            $table->decimal('expected_amount', 15, 2);
            $table->decimal('amount', 15, 2);
            $table->string('status', 20)->default('recorded');
            $table->string('financial_status', 20)->default('matched');
            $table->char('idempotency_key', 64)->unique();
            $table->dateTime('collected_at');
            $table->timestamps();
            $table->unique(['delivery_attempt_id', 'type']);
            $table->index(['shipper_id', 'status', 'collected_at']);
            $table->index(['warehouse_id', 'financial_status', 'collected_at'], 'collection_financial_scope');
        });
        DB::statement('ALTER TABLE delivery_collections ADD CONSTRAINT nonnegative_collection CHECK (amount >= 0 AND expected_amount >= 0)');
        DB::statement('ALTER TABLE delivery_attempts ADD CONSTRAINT nonnegative_attempt CHECK (actual_cod >= 0 AND actual_fee >= 0 AND expected_cod >= 0 AND expected_fee >= 0)');
    }

    public function down(): void
    {
        foreach (['delivery_collections', 'delivery_proofs', 'delivery_attempts', 'return_records', 'delivery_decisions'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn(['return_attempt_count', 'delivery_attempt_limit', 'retry_authorized_attempt', 'financial_hold', 'delivered_at', 'returned_at']));
        Schema::table('fee_rules', fn (Blueprint $table) => $table->dropColumn('return_fee'));
    }
};
