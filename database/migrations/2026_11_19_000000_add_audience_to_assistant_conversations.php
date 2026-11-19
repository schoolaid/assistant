<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use SchoolAid\Assistant\Support\AssistantNaming;

/**
 * Who a conversation serves, for an app that already ran the first two migrations.
 *
 * - `audience`: `staff` (the panel) for every row that is already there.
 * - `owner_type` / `owner_id`: the owner of a conversation that is not a panel user's.
 * - `impersonation_id`: the impersonation session that opened it.
 * - `user_id` may be empty: a conversation of another audience has no panel user.
 *
 * Expand only: a version of the engine without these columns keeps working on the new schema.
 * Each column is added only when it is missing, so it can run again.
 */
return new class extends Migration
{
    public function up(): void
    {
        $conversations = AssistantNaming::table('conversations');

        if (! Schema::hasTable($conversations)) {
            return;
        }

        Schema::table($conversations, function (Blueprint $table) use ($conversations): void {
            if (! Schema::hasColumn($conversations, 'audience')) {
                $table->string('audience', 20)->default('staff')->after('user_id');
            }

            if (! Schema::hasColumn($conversations, 'owner_type')) {
                $table->nullableMorphs('owner');
            }

            if (! Schema::hasColumn($conversations, 'impersonation_id')) {
                $table->unsignedBigInteger('impersonation_id')->nullable();
            }

            $table->unsignedBigInteger('user_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        $conversations = AssistantNaming::table('conversations');

        if (! Schema::hasTable($conversations) || ! Schema::hasColumn($conversations, 'audience')) {
            return;
        }

        Schema::table($conversations, function (Blueprint $table): void {
            $table->dropMorphs('owner');
            $table->dropColumn(['audience', 'impersonation_id']);
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
        });
    }
};
