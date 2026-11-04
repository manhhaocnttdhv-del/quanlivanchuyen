<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 10)->nullable()->unique();
            $table->string('status', 20)->default('active')->index();
            $table->unsignedInteger('auth_version')->default(1);
        });
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 40)->unique();
            $table->string('label');
            $table->timestamps();
        });
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->unique();
            $table->string('label');
        });
        Schema::create('role_user', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->restrictOnDelete();
            $table->primary(['user_id', 'role_id']);
        });
        Schema::create('permission_role', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->restrictOnDelete();
            $table->primary(['role_id', 'permission_id']);
        });
        Schema::create('business_locks', function (Blueprint $table) {
            $table->string('name', 50)->primary();
        });
        DB::table('business_locks')->insert([['name' => 'active-administrators'], ['name' => 'fee-publication']]);
        Schema::create('areas', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();
            $table->string('name', 100);
            $table->string('region', 20);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 100);
            $table->foreignId('area_id')->constrained()->restrictOnDelete();
            $table->string('address');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('warehouse_user', function (Blueprint $table) {
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['warehouse_id', 'user_id']);
        });
        Schema::create('shops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->string('name', 150);
            $table->foreignId('area_id')->constrained()->restrictOnDelete();
            $table->string('address');
            $table->string('status', 20)->default('pending')->index();
            $table->text('review_reason')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });
        Schema::create('shop_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('bank_name', 100);
            $table->string('holder_name', 100);
            $table->text('account_number');
            $table->string('last_four', 4);
            $table->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->unique(['shop_id', 'version']);
        });
        Schema::table('shops', function (Blueprint $table) {
            $table->foreignId('current_bank_account_id')->nullable()->constrained('shop_bank_accounts')->restrictOnDelete();
        });
        Schema::create('pickup_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->restrictOnDelete();
            $table->foreignId('area_id')->constrained()->restrictOnDelete();
            $table->string('contact_name', 100);
            $table->string('phone', 10);
            $table->string('address');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            // MySQL unique permits multiple NULLs, but only one active default per shop.
            $table->unsignedBigInteger('default_shop_id')->nullable()
                ->storedAs('CASE WHEN is_default = 1 AND is_active = 1 THEN shop_id ELSE NULL END')->unique();
            $table->timestamps();
            $table->index(['shop_id', 'is_active']);
        });
        Schema::create('fee_versions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->dateTime('active_from');
            $table->dateTime('active_until')->nullable();
            $table->string('status', 20)->default('draft');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'active_from', 'active_until']);
        });
        Schema::create('fee_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fee_version_id')->constrained()->restrictOnDelete();
            $table->string('route', 20);
            $table->string('service', 20);
            $table->unsignedInteger('min_weight_grams');
            $table->unsignedInteger('max_weight_grams');
            $table->unsignedInteger('base_weight_grams');
            $table->unsignedInteger('step_weight_grams');
            $table->decimal('base_fee', 15, 2);
            $table->decimal('step_fee', 15, 2);
            $table->decimal('max_cod', 15, 2);
            $table->timestamps();
            $table->unique(['fee_version_id', 'route', 'service', 'min_weight_grams'], 'fee_rule_start_unique');
        });
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('event', 80);
            $table->string('subject_type', 100);
            $table->unsignedBigInteger('subject_id');
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->text('reason')->nullable();
            $table->uuid('request_id');
            $table->timestamp('created_at');
            $table->index(['subject_type', 'subject_id', 'created_at']);
            $table->index(['actor_id', 'event', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['audit_logs', 'fee_rules', 'fee_versions', 'pickup_addresses'] as $name) {
            Schema::dropIfExists($name);
        }
        Schema::table('shops', fn (Blueprint $table) => $table->dropConstrainedForeignId('current_bank_account_id'));
        foreach (['shop_bank_accounts', 'shops', 'warehouse_user', 'warehouses', 'areas', 'business_locks', 'permission_role', 'role_user', 'permissions', 'roles'] as $name) {
            Schema::dropIfExists($name);
        }
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['phone']);
            $table->dropIndex(['status']);
            $table->dropColumn(['phone', 'status', 'auth_version']);
        });
    }
};
