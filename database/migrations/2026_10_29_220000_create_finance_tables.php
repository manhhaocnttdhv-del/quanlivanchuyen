<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_deposits', function (Blueprint $table) {
            $this->document($table);
            $table->foreignId('shipper_id')->constrained('users')->restrictOnDelete();
            $table->decimal('requested_amount', 15, 2);
            $table->decimal('received_amount', 15, 2)->default(0);
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('received_at')->nullable();
            $table->string('transaction_reference', 100)->nullable()->unique();
            $table->index(['shipper_id', 'status', 'created_at']);
        });
        Schema::create('cash_deposit_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_deposit_id')->constrained()->restrictOnDelete();
            $table->foreignId('delivery_collection_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->timestamps();
            $table->unique(['cash_deposit_id', 'delivery_collection_id']);
        });
        Schema::create('deposit_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_deposit_id')->constrained()->restrictOnDelete();
            $table->foreignId('delivery_collection_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->timestamps();
            $table->index(['delivery_collection_id', 'cash_deposit_id']);
        });
        Schema::create('reconciliations', function (Blueprint $table) {
            $this->document($table);
            $table->foreignId('shop_id')->constrained()->restrictOnDelete();
            $table->foreignId('shop_bank_account_id')->constrained()->restrictOnDelete();
            $table->text('bank_snapshot');
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedInteger('line_version')->default(1);
            foreach (['cod_total', 'fee_total', 'adjustment_total', 'payable_amount', 'debt_amount'] as $field) {
                $table->decimal($field, 15, 2)->default(0);
            }
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->index(['shop_id', 'status', 'period_start', 'period_end']);
        });
        Schema::create('receivables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('reconciliation_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('type', 30);
            $table->decimal('amount', 15, 2);
            $table->dateTime('accrued_at');
            $table->timestamps();
            $table->unique(['order_id', 'type']);
            $table->unique('reconciliation_id');
            $table->index(['shop_id', 'warehouse_id', 'accrued_at']);
        });
        Schema::create('payments', function (Blueprint $table) {
            $this->document($table);
            $table->foreignId('reconciliation_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->string('transaction_reference', 100)->unique();
            $table->dateTime('paid_at');
        });
        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('reconciliation_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->timestamps();
        });
        Schema::create('fee_receipts', function (Blueprint $table) {
            $this->document($table);
            $table->foreignId('shop_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->string('transaction_reference', 100)->unique();
            $table->dateTime('received_at');
        });
        Schema::create('fee_receipt_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fee_receipt_id')->constrained()->restrictOnDelete();
            $table->foreignId('receivable_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->timestamps();
            $table->unique(['fee_receipt_id', 'receivable_id']);
        });
        Schema::create('adjustments', function (Blueprint $table) {
            $this->document($table);
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('incident_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('reverses_adjustment_id')->nullable()->constrained('adjustments')->restrictOnDelete();
            $table->string('kind', 30);
            $table->decimal('amount', 15, 2);
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('approved_at')->nullable();
            // Cho phep nop lai sau tu choi, khong tao hai dieu chinh hieu luc cho mot can cu.
            $table->unsignedBigInteger('active_incident_id')->nullable()->storedAs("CASE WHEN status IN ('submitted','approved') THEN incident_id ELSE NULL END")->unique();
            $table->unsignedBigInteger('active_reversal_id')->nullable()->storedAs("CASE WHEN status IN ('submitted','approved') THEN reverses_adjustment_id ELSE NULL END")->unique();
            $table->index(['order_id', 'status']);
        });
        Schema::create('adjustment_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('adjustment_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('delivery_collection_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('receivable_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->timestamps();
        });
        Schema::create('reconciliation_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reconciliation_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('delivery_collection_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('receivable_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('adjustment_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('type', 30);
            $table->string('source_label', 100);
            $table->decimal('amount', 15, 2);
            $table->timestamps();
            $table->index(['reconciliation_id', 'version']);
        });
        Schema::create('finance_events', function (Blueprint $table) {
            $table->id();
            $table->string('document_type', 40);
            $table->unsignedBigInteger('document_id');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('event', 40);
            $table->text('reason');
            $table->json('payload')->nullable();
            $table->timestamps();
            $table->index(['document_type', 'document_id', 'id']);
        });
        Schema::create('finance_attachments', function (Blueprint $table) {
            $table->id();
            foreach (['cash_deposit', 'payment', 'fee_receipt', 'adjustment'] as $parent) {
                $table->foreignId($parent.'_id')->nullable()->constrained()->restrictOnDelete();
            }
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('path')->unique();
            $table->string('original_name', 180);
            $table->string('mime', 100);
            $table->char('content_sha256', 64);
            $table->unsignedInteger('size');
            $table->timestamps();
        });
        foreach (['cash_deposit_lines', 'deposit_allocations', 'payment_allocations', 'fee_receipt_allocations', 'reconciliation_lines'] as $table) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_positive CHECK (amount > 0)");
        }
        DB::statement('ALTER TABLE cash_deposits ADD CONSTRAINT deposit_amounts CHECK (requested_amount > 0 AND received_amount >= 0)');
        DB::statement('ALTER TABLE adjustments ADD CONSTRAINT adjustment_positive CHECK (amount > 0)');
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payment_positive CHECK (amount > 0)');
        DB::statement('ALTER TABLE fee_receipts ADD CONSTRAINT receipt_positive CHECK (amount > 0)');
        DB::statement('ALTER TABLE receivables ADD CONSTRAINT receivable_source CHECK (amount > 0 AND ((order_id IS NOT NULL) + (reconciliation_id IS NOT NULL) = 1))');
        DB::statement('ALTER TABLE adjustment_lines ADD CONSTRAINT adjustment_source CHECK (amount <> 0 AND ((delivery_collection_id IS NOT NULL) + (receivable_id IS NOT NULL) + (order_id IS NOT NULL) = 1))');
        DB::statement('ALTER TABLE reconciliation_lines ADD CONSTRAINT reconciliation_source CHECK ((delivery_collection_id IS NOT NULL) + (receivable_id IS NOT NULL) + (adjustment_id IS NOT NULL) = 1)');
        DB::statement('ALTER TABLE finance_attachments ADD CONSTRAINT finance_attachment_parent CHECK ((cash_deposit_id IS NOT NULL) + (payment_id IS NOT NULL) + (fee_receipt_id IS NOT NULL) + (adjustment_id IS NOT NULL) = 1)');
        DB::statement('ALTER TABLE adjustments ADD CONSTRAINT adjustment_basis CHECK ((incident_id IS NOT NULL) + (reverses_adjustment_id IS NOT NULL) = 1)');
        DB::statement('ALTER TABLE reconciliations ADD CONSTRAINT reconciliation_amounts CHECK (cod_total >= 0 AND fee_total >= 0 AND adjustment_total >= 0 AND payable_amount >= 0 AND debt_amount >= 0 AND NOT (payable_amount > 0 AND debt_amount > 0))');
    }

    private function document(Blueprint $table): void
    {
        $table->id();
        $table->string('code', 30)->unique();
        $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
        $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
        $table->string('status', 20);
        $table->text('reason');
        $table->uuid('client_token');
        $table->char('request_hash', 64);
        $table->unsignedInteger('revision')->default(1);
        $table->timestamps();
        $table->unique(['created_by', 'client_token']);
        $table->index(['warehouse_id', 'status', 'created_at']);
    }

    public function down(): void
    {
        foreach (['finance_attachments', 'finance_events', 'reconciliation_lines', 'adjustment_lines', 'adjustments', 'fee_receipt_allocations', 'fee_receipts', 'payment_allocations', 'payments', 'receivables', 'reconciliations', 'deposit_allocations', 'cash_deposit_lines', 'cash_deposits'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
