<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->restrictOnDelete();
            $table->string('tracking_code', 32)->unique();
            $table->uuid('client_token');
            $table->char('creation_hash', 64);
            $table->string('status', 30)->default('draft');
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('pickup_address_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('origin_area_id')->nullable()->constrained('areas')->restrictOnDelete();
            $table->foreignId('area_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('assigned_shipper_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('custody_status', 30)->default('uncollected');
            $table->string('recipient_name', 150)->nullable();
            $table->string('recipient_phone', 10)->nullable();
            $table->string('recipient_address', 500)->nullable();
            $table->unsignedInteger('weight_grams')->nullable();
            $table->decimal('weight_kg', 10, 3)->nullable();
            $table->unsignedSmallInteger('parcel_count')->default(1);
            $table->string('service', 20)->default('standard');
            $table->string('fee_payer', 20)->default('sender');
            $table->decimal('cod_amount', 15, 2)->default(0);
            $table->decimal('shipping_fee', 15, 2)->nullable();
            $table->decimal('recipient_total', 15, 2)->nullable();
            $table->foreignId('fee_version_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('fee_rule_id')->nullable()->constrained()->restrictOnDelete();
            $table->json('sender_snapshot')->nullable();
            $table->json('recipient_snapshot')->nullable();
            $table->json('fee_snapshot')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('submitted_at')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->timestamps();
            $table->unique(['shop_id', 'client_token']);
            $table->index(['shop_id', 'status', 'created_at']);
            $table->index(['warehouse_id', 'status', 'created_at']);
            $table->index(['area_id', 'status']);
            $table->index(['assigned_shipper_id', 'status']);
            $table->index('created_at');
        });
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->string('name', 200);
            $table->unsignedInteger('quantity');
            $table->timestamps();
        });
        Schema::create('order_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('event', 40);
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->unsignedInteger('revision');
            $table->text('reason')->nullable();
            $table->json('before_values')->nullable();
            $table->json('after_values')->nullable();
            $table->dateTime('created_at');
            $table->index(['order_id', 'created_at']);
        });
        Schema::create('order_change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('type', 20);
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('order_revision');
            $table->json('changes')->nullable();
            $table->json('before_values');
            $table->text('reason');
            $table->text('review_reason')->nullable();
            $table->dateTime('reviewed_at')->nullable();
            // Chỉ một yêu cầu đang chờ trên mỗi đơn, kể cả khi có hai request đồng thời.
            $table->unsignedBigInteger('pending_order_id')->nullable()->storedAs("CASE WHEN status = 'pending' THEN order_id ELSE NULL END")->unique();
            $table->timestamps();
            $table->index(['order_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_change_requests');
        Schema::dropIfExists('order_status_histories');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
