# schoolaid/assistant

The panel assistant engine shared by SAID apps: an agent loop that calls the app's own MCP tools
in process, as the person using the panel and with their permissions. Every write ends in a
preview; only that person's click on Confirm runs it.

**Status: generated, not yet consumed.** The engine still lives in MarketAid
(`app/Modules/Assistant`) and this package is produced from it. No app requires it yet.

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
  the generated ones.
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
| `PanelScope` + `PanelSnapshot` | Run code as the person in the panel, with their scope |
| `Instructions` | The fixed prompt and the context of each message |
| `AccessGate` | Who may use it, who may configure it, the platform's monthly budget |
| `BudgetAlerts` | Who is warned when usage crosses its threshold |
| `EntityLabels` | How the records a conversation touched are shown |
| `PreviewDetails` | A preview as staff reads it on the Confirm card |
| `PlatformGrant` | The platform's switch and budget for a workspace, by id |

MarketAid's implementations are the reference: `app/Modules/Assistant/Host/` there.

## Not in the package yet

- Config, translations, routes and migrations: they are still MarketAid's files.
- Table names are fixed (`assistant_*`) and the workspace tables assume one database per
  workspace. An app with a single database, or one that already has an `assistant_conversations`
  table, needs a table prefix and a workspace column first.
- The task eval (`assistant:eval`) stays in MarketAid.
