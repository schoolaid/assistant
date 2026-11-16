<?php

/*
| Engine defaults. The app publishes this file under the name it registered with
| AssistantNaming::use() (default `assistant`) and fills in what is its own: the tools, their
| groups, the entities and the suggestions.
*/
return [

    'model' => env('ASSISTANT_MODEL', 'claude-sonnet-5-5'),
    'max_tokens' => 8192,
    'effort' => 'medium',
    'max_iterations' => 12,
    'max_conversation_input_tokens' => 150000,

    /*
    | A conversation past this size (input tokens of its last request) is summarized when its next
    | turn starts, instead of being closed at max_conversation_input_tokens. Null: never summarized.
    | Switch it on in a later release than the one that brings the code: a version without the
    | `summary` message kind cannot read a summarized conversation. Keep it below
    | max_conversation_input_tokens: above it nothing is ever summarized and the cap does not close
    | either, since it waits for a summary attempt.
    */
    'summarize_at_input_tokens' => null,
    'summary' => [
        // Cap of the summary call; its thinking counts.
        'max_tokens' => 8192,
        // Replaces the engine's summary instructions (ConversationSummary::DEFAULT_INSTRUCTIONS).
        'instructions' => null,
    ],

    /*
    | The Messages API. The platform key; a workspace may bring its own (AssistantApiKey).
    */
    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY', ''),
        'version' => env('ANTHROPIC_API_VERSION', '2023-06-01'),
        'url' => env('ANTHROPIC_URL', 'https://api.anthropic.com/v1'),
    ],

    /*
    | The panel user model. Null: the model of the `users` auth provider.
    */
    'user_model' => null,

    /*
    | Single-database apps: the column that holds the workspace id in the workspace tables
    | (conversations, turns, messages, confirmations, exports). Null when every workspace has its
    | own database. Set it before running the migrations.
    */
    'workspace_column' => null,
    'workspace_column_type' => 'string', // or 'integer', when the workspace ids are numeric

    /*
    | Defaults for the limits until the platform sets its own (AssistantLimitEnum::builtInDefault()).
    */
    'limits' => [
        'messages_per_minute' => 20,
        'warning_percent' => 80,
    ],

    /*
    | Files the assistant builds with `export-file`. `disk` must be private. `creator` signs the
    | files (properties of the Excel and of the PDF); null leaves the engine's, which names MarketAid.
    */
    'exports' => [
        'disk' => env('ASSISTANT_EXPORTS_DISK', 's3'),
        'creator' => null,
        'directory' => 'assistant-exports',
        'max_rows' => ['xlsx' => 5000, 'pdf' => 1000],
        'per_hour' => 10,
        'ttl_days' => 7,
        'page_size' => 50,
    ],

    /*
    | How long the fixed prefix of each call (tools + instructions) stays cached: `1h` or `5m`.
    */
    'prefix_cache_ttl' => env('ASSISTANT_PREFIX_CACHE_TTL', '1h'),

    /*
    | The app's tools (by name). `core_tools` load in every call, the rest are found with tool
    | search. A write is offered only with a group: 1 (undo in the chat) or 2 (fix it in the
    | panel); `panel_only` never runs from the chat. `subscription_action_groups` gives the group of
    | each action class of a tool that gathers several (ToolSource::actionClasses()).
    */
    'core_tools' => [],
    'tool_search' => ['enabled' => true],
    'denied_tools' => [],
    'denied_with_knowledge' => [],
    'tool_groups' => [],
    'subscription_action_groups' => [],
    'panel_only' => [],

    /*
    | How the model reads the engine's own tools, by name: `offer-choices`, `show-chart`,
    | `export-file`, `export-document`. The engine's descriptions talk about MarketAid (customers,
    | plans, branch offices, its tools): another app writes its own here, with its records and the
    | names of its reading tools. A tool without an entry keeps the engine's. They are config and
    | not translations because they sit in the cached prefix of every call: the same for everyone.
    */
    'tool_descriptions' => [],

    /*
    | Undo planners by tool name (class-string of an UndoPlanner) and how long the offer lasts.
    */
    'undo' => [],
    'undo_minutes' => 5,

    /*
    | USD per million tokens. Read https://platform.claude.com/docs/en/about-claude/pricing again
    | before billing a workspace or changing the model; add the row of the model that answers its
    | refusals too.
    */
    'pricing' => [
        'claude-sonnet-5-5' => ['input' => 2.00, 'output' => 10.00, 'cache_read' => 0.10, 'cache_write' => 2.50, 'cache_write_1h' => 4.00],
        'claude-sonnet-5' => ['input' => 2.00, 'output' => 10.00, 'cache_read' => 0.20, 'cache_write' => 2.50, 'cache_write_1h' => 4.00],
        'claude-haiku-5-5' => ['input' => 0.10, 'output' => 0.50, 'cache_read' => 0.01, 'cache_write' => 0.125, 'cache_write_1h' => 0.20],
    ],

    /*
    | search-knowledge: the Meilisearch index of the app's guide (host and key from `scout.meilisearch`).
    */
    'knowledge' => [
        'enabled' => (bool) env('ASSISTANT_KNOWLEDGE_ENABLED', false),
        'index' => 'assistant_knowledge',
        'embedder' => [
            'name' => 'default',
            'model' => env('ASSISTANT_KNOWLEDGE_EMBEDDER_MODEL', 'intfloat/multilingual-e5-small'),
            'document_prefix' => 'passage: ',
            'query_prefix' => 'query: ',
        ],
        'semantic_ratio' => 0.3,
        'matching_strategy' => env('ASSISTANT_KNOWLEDGE_MATCHING_STRATEGY', 'frequency'),
        'task_timeout' => 900,
        'abstain_below' => 0.0,
        'results' => 5,
        'videos' => [
            'catalog_url' => env('ASSISTANT_GUIDES_CATALOG_URL', ''),
            'results' => 2,
            'candidates' => 30,
            'semantic_ratio' => 0.5,
        ],
    ],

    /*
    | Opening phrases of the chat, in order: [['id' => ..., 'tools' => [...]]]. Only the ones the
    | person can do are offered, up to `suggestions_limit`.
    */
    'suggestions' => [],
    'suggestions_limit' => 4,

    /*
    | Which records each tool touches, to label them in the panel (AssistantEntityExtractor): per
    | tool, a list of {type, id, model_key?, date?} with paths `input.<arg>` (the call's arguments),
    | `output.<key>` (what the tool answered in the chat, when JSON) or `result.<key>` (what a
    | confirmed write returned: a record created). A path with `*` (`output.data.*.id`) gives a
    | record only when it finds exactly one value: a list or search that found a single record.
    */
    'entities' => [],

];
