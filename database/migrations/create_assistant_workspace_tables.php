<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use SchoolAid\Assistant\Support\AssistantNaming;

/**
 * The tables that belong to a workspace: conversations, turns, messages, confirmations and exports.
 *
 * An app with one database per workspace runs this in each of them. An app with a single database
 * sets `workspace_column` in the assistant config before migrating: every table then gets that
 * column (indexed) and the models only read the rows of the current workspace.
 */
return new class extends Migration
{
    public function up(): void
    {
        $conversations = AssistantNaming::table('conversations');
        $turns = AssistantNaming::table('turns');
        $messages = AssistantNaming::table('messages');

        Schema::create($conversations, function (Blueprint $table): void {
            $table->id();
            $this->workspaceColumn($table);
            $table->unsignedBigInteger('user_id')->index();
            $table->string('title')->nullable();
            $table->longText('system_prompt')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create($turns, function (Blueprint $table) use ($conversations): void {
            $table->id();
            $this->workspaceColumn($table);
            $table->foreignId('conversation_id')->constrained($conversations)->cascadeOnDelete();
            $table->string('run_id', 64)->unique();
            $table->string('status', 30)->index();
            $table->json('panel_context')->nullable();
            $table->unsignedSmallInteger('iterations')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('step_started_at')->nullable();
            $table->unsignedTinyInteger('resumes')->default(0);
            $table->timestamps();
        });

        Schema::create($messages, function (Blueprint $table) use ($conversations, $turns): void {
            $table->id();
            $this->workspaceColumn($table);
            $table->foreignId('conversation_id')->constrained($conversations)->cascadeOnDelete();
            $table->foreignId('turn_id')->nullable()->constrained($turns)->nullOnDelete();
            $table->string('role', 20);
            $table->string('kind', 20);
            $table->json('content');
            $table->text('display_text')->nullable();
            $table->json('choices')->nullable();
            $table->json('payload')->nullable();
            $table->string('model')->nullable();
            $table->string('stop_reason', 32)->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('cache_read_tokens')->default(0);
            $table->unsignedInteger('cache_write_tokens')->default(0);
            $table->boolean('helpful')->nullable();
            $table->text('feedback_comment')->nullable();
            $table->timestamps();
            $table->index(['conversation_id', 'id']);
        });

        Schema::create(AssistantNaming::table('confirmations'), function (Blueprint $table) use ($conversations, $turns, $messages): void {
            $table->id();
            $this->workspaceColumn($table);
            $table->foreignId('turn_id')->constrained($turns)->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained($conversations)->cascadeOnDelete();
            $table->foreignId('message_id')->constrained($messages)->cascadeOnDelete();
            $table->string('tool');
            $table->json('arguments');
            $table->text('summary');
            $table->json('preview');
            $table->string('token');
            $table->timestamp('expires_at');
            $table->string('status', 20)->index();
            $table->json('result')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->unsignedBigInteger('undoes_confirmation_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create(AssistantNaming::table('exports'), function (Blueprint $table) use ($conversations, $turns): void {
            $table->uuid('id')->primary();
            $this->workspaceColumn($table);
            $table->foreignId('conversation_id')->constrained($conversations)->cascadeOnDelete();
            $table->foreignId('turn_id')->constrained($turns)->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('format', 10);
            $table->string('title');
            $table->json('source');
            $table->json('columns')->nullable();
            $table->unsignedInteger('rows')->nullable();
            $table->string('status', 20)->index();
            $table->string('error')->nullable();
            $table->string('path')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['exports', 'confirmations', 'messages', 'turns', 'conversations'] as $table) {
            Schema::dropIfExists(AssistantNaming::table($table));
        }
    }

    /**
     * The workspace id is a string in the engine (`Workspace::id()`); a numeric id is stored as its digits.
     */
    private function workspaceColumn(Blueprint $table): void
    {
        $column = AssistantNaming::config('workspace_column');

        if ($column !== null) {
            $table->string($column, 191)->index();
        }
    }
};
