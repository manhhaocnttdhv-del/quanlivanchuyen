<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incidents', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_item_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('delivery_task_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('delivery_collection_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('discrepancy_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('type', 30);
            $table->string('severity', 20);
            $table->string('title', 180);
            $table->text('description');
            $table->string('status', 20)->default('open');
            $table->boolean('blocks_dispatch')->default(false);
            $table->boolean('blocks_reconciliation')->default(false);
            $table->string('financial_status', 30)->default('none');
            $table->uuid('client_token');
            $table->char('creation_hash', 64);
            $table->unsignedInteger('revision')->default(1);
            $table->dateTime('closed_at')->nullable();
            $table->timestamps();
            $table->unique(['created_by', 'client_token']);
            $table->index(['warehouse_id', 'status', 'created_at']);
            $table->index(['assigned_to', 'status', 'created_at']);
            $table->index(['order_id', 'blocks_dispatch']);
            $table->index(['order_id', 'blocks_reconciliation']);
        });
        Schema::create('incident_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('type', 30);
            $table->text('reason');
            $table->json('payload')->nullable();
            $table->timestamps();
            $table->index(['incident_id', 'id']);
        });
        Schema::table('incidents', function (Blueprint $table) {
            $table->foreignId('proposal_action_id')->nullable()->constrained('incident_actions')->restrictOnDelete();
            $table->foreignId('approved_action_id')->nullable()->constrained('incident_actions')->restrictOnDelete();
        });
        Schema::create('complaints', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('shop_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('incident_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('type', 30);
            $table->string('title', 180);
            $table->text('description');
            $table->string('status', 20)->default('open');
            $table->dateTime('due_at');
            $table->dateTime('first_response_at')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->text('resolution')->nullable();
            $table->uuid('client_token');
            $table->char('creation_hash', 64);
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->unique(['created_by', 'client_token']);
            $table->index(['warehouse_id', 'status', 'due_at']);
            $table->index(['shop_id', 'status', 'created_at']);
            $table->index(['assigned_to', 'status', 'due_at']);
        });
        Schema::create('complaint_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('complaint_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('type', 30)->default('message');
            $table->boolean('is_internal')->default(false);
            $table->text('body');
            $table->timestamps();
            $table->index(['complaint_id', 'is_internal', 'id']);
        });
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_action_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('complaint_message_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('path')->unique();
            $table->string('original_name', 180);
            $table->string('mime', 100);
            $table->unsignedInteger('size');
            $table->char('content_sha256', 64);
            $table->timestamps();
        });
        DB::statement('ALTER TABLE attachments ADD CONSTRAINT attachment_one_parent CHECK ((incident_action_id IS NOT NULL) + (complaint_message_id IS NOT NULL) = 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
        Schema::dropIfExists('complaint_messages');
        Schema::dropIfExists('complaints');
        Schema::table('incidents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('proposal_action_id');
            $table->dropConstrainedForeignId('approved_action_id');
        });
        Schema::dropIfExists('incident_actions');
        Schema::dropIfExists('incidents');
    }
};
