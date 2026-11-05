<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('export_logs', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('report_type', 20);
            $table->string('format', 8);
            $table->json('filters');
            $table->char('scope_hash', 64);
            $table->uuid('client_token');
            $table->char('request_hash', 64);
            $table->string('status', 20)->default('queued');
            $table->string('path')->nullable();
            $table->unsignedInteger('row_count')->default(0);
            $table->string('error_message')->nullable();
            $table->dateTime('generated_at')->nullable();
            $table->dateTime('expires_at');
            $table->timestamps();
            $table->unique(['user_id', 'client_token']);
            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'expires_at']);
        });
        Schema::create('jobs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->index(['warehouse_id', 'submitted_at'], 'report_orders_submitted');
            $table->index(['warehouse_id', 'approved_at'], 'report_orders_approved');
        });
        Schema::table('delivery_attempts', function (Blueprint $table) {
            $table->index(['type', 'outcome', 'attempted_at', 'order_id'], 'report_delivery_events');
        });
    }

    public function down(): void
    {
        Schema::table('delivery_attempts', fn (Blueprint $table) => $table->dropIndex('report_delivery_events'));
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('report_orders_submitted');
            $table->dropIndex('report_orders_approved');
        });
        Schema::dropIfExists('jobs');
        Schema::dropIfExists('export_logs');
    }
};
