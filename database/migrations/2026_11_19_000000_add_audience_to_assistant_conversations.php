<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use SchoolAid\Assistant\Support\AssistantNaming;

/**
 * Who a conversation serves, for an app that already ran the first two migrations.
 *
 * - `audience`: `staff` (the panel) for every row that is already there.
 * - `owner_type` / `owner_id` and their index: the owner of a conversation that is not a panel user's.
 * - `impersonation_id`: the impersonation session that opened it.
 * - `user_id` may be empty: a conversation of another audience has no panel user.
 *
 * Expand only: a version of the engine without these columns keeps working on the new schema.
 * MySQL runs each column as its own ALTER, outside any transaction, so every column, the index and
 * the `user_id` change are guarded on their own: a run that stopped part-way finishes when it runs
 * again, and a run over a finished schema changes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        $conversations = AssistantNaming::table('conversations');

        if (! Schema::hasTable($conversations)) {
            return;
        }

        $this->addUnlessExists($conversations, 'audience', fn (Blueprint $table) => $table->string('audience', 20)->default('staff')->after('user_id'));
        $this->addUnlessExists($conversations, 'owner_type', fn (Blueprint $table) => $table->string('owner_type')->nullable());
        $this->addUnlessExists($conversations, 'owner_id', fn (Blueprint $table) => $table->unsignedBigInteger('owner_id')->nullable());
        $this->addUnlessExists($conversations, 'impersonation_id', fn (Blueprint $table) => $table->unsignedBigInteger('impersonation_id')->nullable());

        if (! Schema::hasIndex($conversations, ['owner_type', 'owner_id'])) {
            Schema::table($conversations, fn (Blueprint $table) => $table->index(['owner_type', 'owner_id']));
        }

        if (! $this->userIdIsNullable($conversations)) {
            Schema::table($conversations, fn (Blueprint $table) => $table->unsignedBigInteger('user_id')->nullable()->change());
        }
    }

    /**
     * `user_id` goes back to required first: with a conversation that has no panel user it fails
     * before any column is dropped, instead of leaving the table half undone.
     */
    public function down(): void
    {
        $conversations = AssistantNaming::table('conversations');

        if (! Schema::hasTable($conversations)) {
            return;
        }

        if ($this->userIdIsNullable($conversations)) {
            Schema::table($conversations, fn (Blueprint $table) => $table->unsignedBigInteger('user_id')->nullable(false)->change());
        }

        if (Schema::hasIndex($conversations, ['owner_type', 'owner_id'])) {
            Schema::table($conversations, fn (Blueprint $table) => $table->dropIndex(['owner_type', 'owner_id']));
        }

        foreach (['impersonation_id', 'owner_id', 'owner_type', 'audience'] as $column) {
            if (Schema::hasColumn($conversations, $column)) {
                Schema::table($conversations, fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }

    /**
     * @param  Closure(Blueprint): mixed  $add
     */
    private function addUnlessExists(string $table, string $column, Closure $add): void
    {
        if (! Schema::hasColumn($table, $column)) {
            Schema::table($table, fn (Blueprint $blueprint) => $add($blueprint));
        }
    }

    private function userIdIsNullable(string $table): bool
    {
        $column = collect(Schema::getColumns($table))->firstWhere('name', 'user_id');

        return (bool) ($column['nullable'] ?? false);
    }
};
