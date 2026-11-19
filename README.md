# schoolaid/assistant

The panel assistant engine shared by SAID apps: an agent loop that calls the app's own MCP tools
in process, as the person using the panel and with their permissions. Every write ends in a
preview; only that person's click on Confirm runs it.

**Status: generated from MarketAid.** The engine still lives in MarketAid
(`app/Modules/Assistant`) and this package is produced from it. SchoolAid requires it (on a
branch, as `panel_assistant`); MarketAid does not yet.

## How the source gets here

MarketAid is the source of truth until it switches to this package. Regenerate after every change
there:

```bash
php bin/sync-from-marketaid.php /path/to/marketaid          # copy and rewrite the namespace
php bin/sync-from-marketaid.php /path/to/marketaid --check  # write nothing; exit 1 on blockers
vendor/bin/pest
```

- `src/` and `database/factories/` are generated: do not edit them here, the next sync overwrites
  them. Change the code in MarketAid.
- `overrides/` holds the files this package owns (its service provider). The sync copies them over
  the generated ones. `config/`, `database/migrations/` and `tests/` are also the package's own;
  `resources/lang/` is copied from MarketAid.
- What stays in MarketAid is listed in its `tests/Unit/Assistant/AssistantBoundaryTest.php`
  (`ASSISTANT_HOST_FILES`, plus the module's `Host/` folder). The sync reads that list.
- A blocker is a reference from the engine to a class that is not in this package. The package is
  usable only with none.

## What an app has to provide

The engine talks to the app only through `SchoolAid\Assistant\Contracts`. The app binds each one
in its own service provider:

| Contract | What the engine asks the app |
|---|---|
| `Workspace` | Which workspace it runs in, which exist, how to enter one, where platform tables live |
| `ToolSource` | The MCP server's tools and instructions, which tools write |
| `WritePreviews` | Whether a preview can be offered, how to discard its token, how long it lives |
| `ToolCallLog` | Where each tool call is recorded |
| `PanelScope` + `PanelSnapshot` | Run code as the person in the panel, with their scope; `principalKey()` is who sent the message as `{table}:{id}` |
| `Instructions` | The fixed prompt and the context of each message |
| `AccessGate` | Who may use it, who may configure it, the platform's monthly budget |
| `BudgetAlerts` | Who is warned when usage crosses its threshold |
| `EntityLabels` | How the records a conversation touched are shown, and whether the person can see each one |
| `EntitySearch` | The records a person can attach to a message with `@`, found by text, grouped |
| `PreviewDetails` | A preview as staff reads it on the Confirm card |
| `PlatformGrant` | The platform's switch and budget for a workspace, by id |
| `GuideScreens` | The panel screens a guide page talks about, as a given person sees them |
| `Letterhead` | The name and logo on the PDFs the assistant builds |

MarketAid's implementations are the reference: `app/Modules/Assistant/Host/` there.

## Installing it in an app

The assistant has one name in the app: its config key, its translation file, the prefix of its
tables and its rate limiter. The default is `assistant`. An app that already has something called
"assistant" registers another name, in the `register()` of its own provider, and binds the
contracts there:

```php
AssistantNaming::use('panel_assistant');   // config('panel_assistant.*'), tables panel_assistant_*
```

```bash
php artisan vendor:publish --tag=assistant-config       # config/<name>.php, with the engine defaults
php artisan vendor:publish --tag=assistant-lang         # lang/<locale>/<name>.php
php artisan vendor:publish --tag=assistant-migrations   # two migrations: workspace and platform tables
```

Then, in the app:

- **Routes**: call `AssistantRoutes::panel($prefix, $namePrefix)` inside the route group where the
  session, the person and the workspace are already resolved. The package registers the rate
  limiter (per workspace and person) under the assistant's name.
- **Policy**: register the app's policy for the five workspace models; a conversation and what
  hangs from it belong to their author.
- **Queue**: a connection and a queue both named `assistant`, with their own worker.
- **Schedule**: `assistant:resume-stale-turns` every minute (it picks up a step whose worker
  died) and `assistant:prune` daily.
- **Config**: fill in what is the app's own in the published file: `core_tools`, `tool_groups`,
  `panel_only`, `undo`, `entities`, `suggestions`, and `tool_descriptions`: the engine describes
  its own four tools (`offer-choices`, `show-chart`, `export-file`, `export-document`) to the model
  with MarketAid's records and tool names, so another app writes its own. `exports.creator` signs
  the files it builds. The published translations carry MarketAid's
  texts for those sections; replace them.
- **Anthropic**: `anthropic.api_key`, `anthropic.url` and `anthropic.version` in the published
  config (`ANTHROPIC_API_KEY`…). Meilisearch comes from `scout.meilisearch.*`.
- **Texts of attached records and the `@` search**: `errors.assistant_mention_not_available`,
  `validation.mentions_*` and `validation.search_*` come with the published translations;
  `search_groups` is the app's own (the group names its `EntitySearch` returns).

### One database per workspace, or a single one

- **One database per workspace** (MarketAid): leave `workspace_column` null and run the workspace
  migration in each workspace database; the platform migration runs on `Workspace::sharedConnection()`.
- **A single database** (SchoolAid): set `workspace_column` (for example `school_id`) before
  migrating, and `workspace_column_type` to `integer` when the ids are numeric. Conversations, turns, messages, confirmations and exports then store the workspace
  where they were created and are read only from it; outside a workspace they return no rows.
  A command that must read across workspaces asks with `withoutGlobalScope(WorkspaceScope::class)`.
- Queued jobs remember the workspace where they were dispatched and re-enter it with
  `Workspace::run()`.

`tests/SingleDatabase/` installs the package as a single-database app with another name: it runs
the migrations and checks that rows do not cross between workspaces, and that config,
translations, routes and the limiter follow the name.

### Long conversations

A conversation is closed when its last request reached `max_conversation_input_tokens`. Set
`summarize_at_input_tokens` (null by default: off) and a conversation past that size is summarized
when its next turn starts instead: the engine asks the model for the summary itself, stores it as
a `summary` message that the panel never lists, and builds the history from the latest one.

- `summary.max_tokens` caps the summary call; `summary.instructions` replaces the engine's
  instructions (`ConversationSummary::DEFAULT_INSTRUCTIONS`).
- No migration: `kind` is a string column.
- **Switch it on in a later release than the one that brings this version.** A version without the
  `summary` kind cannot read a summarized conversation, so no image older than this version may be
  left to roll back to.
- A summary that fails never stops the turn; with summaries on, the size cap closes a conversation
  only after a failed attempt.

`tests/SingleDatabase/ConversationSummaryTest.php` runs whole turns against a scripted Messages
API (`tests/Fixtures/ScriptedTurn.php`).

## Attached records and the `@` search

A message may carry up to 10 records (`mentions: [{type, id, model_key?}]` with `content`). The
engine labels each one again with `EntityLabels`, as the person: one that does not come back is
refused with 422 `assistant_mention_not_available`. They are stored in `messages.payload`, shown in
the history and given to the model as one line each (type, id, label).

`GET {prefix}/entities/search?q=&conversation=` feeds the panel's `@` picker: with two characters or
more it asks the app's `EntitySearch` for at most five per group; with less and a conversation, it
offers that conversation's records, newest first. Everything goes through `EntityLabels`, so only
what the person can see comes out. `GET {prefix}/entities/label` (`refs`, shaped like `mentions`)
labels the records the panel opens the assistant with.

## Upgrading from 0.1.x

1. Implement `PanelSnapshot::principalKey()` and bind `EntitySearch` in the app's provider.
2. Add the new translation keys (above) to the app's published files.
3. Write a migration in the app with the columns of
   `database/migrations/2026_11_19_000000_add_audience_to_assistant_conversations.php`: an app that
   already published the first two migrations does not publish them again.
4. In production, run it right after the deploy: until the `audience` column exists,
   `assistant:resume-stale-turns` and `assistant:prune` skip every workspace. The chat itself does not
   write `audience` and keeps working on the old schema.

## Not done yet

- MarketAid does not require the package: it keeps its own copy of the engine, config,
  translations and migrations until it switches; they are the source of the ones here.
- The task eval (`assistant:eval`) stays in MarketAid.
