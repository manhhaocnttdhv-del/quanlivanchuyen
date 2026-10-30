<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('result', 20)->default('succeeded');
            $table->index(['created_at', 'id'], 'audit_time');
            $table->index(['event', 'created_at'], 'audit_event_time');
            $table->index('request_id');
        });
        Schema::create('notification_dispatches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audit_log_id')->unique()->constrained()->restrictOnDelete();
            $table->dateTime('queued_at')->nullable();
            $table->dateTime('delivered_at')->nullable();
            $table->timestamps();
            $table->index(['delivered_at', 'queued_at']);
        });
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type', 100);
            // Dung quan he Notifiable cua Laravel; project chi gui thong bao cho User.
            $table->string('notifiable_type', 100);
            $table->foreignId('notifiable_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('audit_log_id')->constrained()->restrictOnDelete();
            $table->json('data');
            $table->dateTime('read_at')->nullable();
            $table->timestamps();
            $table->unique(['notifiable_type', 'notifiable_id', 'audit_log_id'], 'notification_recipient_event');
            $table->index(['notifiable_type', 'notifiable_id', 'read_at', 'created_at'], 'notification_inbox');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('notification_dispatches');
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex('audit_time');
            $table->dropIndex('audit_event_time');
            $table->dropIndex(['request_id']);
            $table->dropColumn('result');
        });
    }
};
