<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Schema;
use SchoolAid\Assistant\Contracts\Workspace;
use SchoolAid\Assistant\Support\AssistantNaming;

/**
 * The tables that belong to the platform and not to one workspace: usage, limits, API keys and
 * what the platform allows each workspace. They live on `Workspace::sharedConnection()` and carry
 * the workspace id in `tenant_id` (null: the platform's own row).
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = $this->schema();

        $schema->create(AssistantNaming::table('usage'), function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id', 191);
            $table->char('period', 7);
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->unsignedBigInteger('cache_read_tokens')->default(0);
            $table->unsignedBigInteger('cache_write_tokens')->default(0);
            $table->decimal('cost_usd', 12, 4)->default(0);
            $table->decimal('own_key_cost_usd', 12, 4)->default(0);
            $table->timestamp('warned_at')->nullable();
            $table->timestamp('market_warned_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'period']);
        });

        $schema->create(AssistantNaming::table('user_usage'), function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id', 191);
            $table->unsignedBigInteger('user_id');
            $table->char('period', 7);
            $table->unsignedInteger('responses')->default(0);
            $table->decimal('cost_usd', 12, 4)->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'period', 'user_id']);
        });

        $schema->create(AssistantNaming::table('api_keys'), function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id', 191)->nullable()->unique();
            $table->text('api_key');
            $table->char('last4', 4);
            $table->string('status', 16);
            $table->timestamp('verified_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->string('updated_by', 191)->nullable();
            $table->timestamps();
        });

        $schema->create(AssistantNaming::table('limits'), function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id', 191)->nullable();
            $table->string('limit_key', 40);
            $table->decimal('value', 12, 4)->nullable();
            $table->decimal('max', 12, 4)->nullable();
            $table->boolean('locked')->default(false);
            $table->decimal('locked_value', 12, 4)->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'limit_key']);
        });

        $schema->create(AssistantNaming::table('tenant_settings'), function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id', 191)->unique();
            $table->boolean('own_key_allowed')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['tenant_settings', 'limits', 'api_keys', 'user_usage', 'usage'] as $table) {
            $this->schema()->dropIfExists(AssistantNaming::table($table));
        }
    }

    private function schema(): Builder
    {
        return Schema::connection(app(Workspace::class)->sharedConnection());
    }
};
