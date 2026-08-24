# AI Assistant — Natural-Language Interface (Design Spec)

Status: approved in conversation 2026-08-23 · Scope: ATR Inventory v1.1

## Problem

Inventory operators want to drive ATR in plain English:
- "chris b got terminated and returned all their equipment" → resolve Christopher
  Baldolvsky, mark him terminated, check in every asset he holds into the named
  department (e.g. Provision).
- "did whoever #00039 was last used by return all their equipment?" → answer from
  live data (audit trail + current checkouts).

The model runs **locally** from a GGUF file the user uploads to a directory; the
user picks the active model from the System tab.

## Goals

- Chat-style assistant page with per-browser history.
- Natural-language reads (instant) and actions (plan → confirm → execute).
- GGUF model selection from a directory; start/stop of a local `llama-server`.
- Every assistant action is permission-checked, validated, and audited exactly
  like the manual UI (same model layer).

## Non-goals

- No multi-tenant/cloud inference, no API keys, no external services.
- No training/fine-tuning; no Ollama; no PHP-native GGUF runtime.
- No conversation persistence beyond the browser (localStorage); accountability
  lives in the existing audit log.
- No free-form writes beyond the listed action tools (e.g. no "edit arbitrary
  field" tool; field edits stay in the UI forms).

## Architecture (Approach A: llama.cpp managed by the app)

```
Browser (Assistant page)
   │  POST /assistant/chat {message, confirm?}
   ▼
AssistantController
   ├─ read tools  → execute now → results to model
   ├─ action tools → DRY RUN (validate + preview) → plan stored in session
   ▼
LlmClient (PHP) ── HTTP ──▶ llama-server (127.0.0.1:8082, OpenAI-compatible,
   │                          tool calling, Metal, 8k ctx, PID in storage/run/llama.pid)
   ▼
App\Models\* (Person, Asset, Department, Audit) — the ONLY write path
```

- Installer adds `brew install llama.cpp` (System tab offers an in-UI install
  fallback using the installer's existing Homebrew/sudo path).
- Models dir: `storage/models` (setting `llm.models_dir`, overridable). Drop
  `.gguf` files there; System tab lists them (name, size, mtime) with Rescan.
- Server lifecycle: app spawns `llama-server --host 127.0.0.1 --port <port>
  -m <model> -ngl 99 -c <context> -np 1` (nohup, detached), PID file
  `storage/run/llama.pid`, health via `GET /health`. Start/Stop/Restart in the
  System card and a "Start selected model" shortcut on the Assistant page.
  Changing the selected model while running → Restart.
- Server is app-managed (not launchd): one process, trivial to supervise, and
  the model choice is dynamic. The installer still verifies the binary exists at
  install time.

## Components

### 1. Settings (new keys, `config/app.php` defaults + settings table)
`llm.models_dir` (default `storage/models`), `llm.port` (8082),
`llm.context` (8192), `llm.selected_model` (basename of the `.gguf`).
Admin-only writes, from the System card.

### 2. `app/Services/LlmServer.php`
- `models(): array` — scan models dir for `*.gguf` (name, size, mtime).
- `state(): array` — stopped | loading | ready | error(+reason), from PID +
  `/health` probe.
- `start(): void` / `stop(): void` — spawn/kill; waits for ready (up to 90 s)
  in a background-tolerant way: the endpoint returns immediately, UI polls
  `state()`.
- `ensureRunning(): void` — used by `/assistant/chat` when a model is selected
  and stopped (auto-start + wait).
- Flags: Metal offload (`-ngl 99`), context from settings, single parallel slot
  (`-np 1`).

### 3. `app/Services/LlmClient.php`
- `chat(array $messages, bool $executeMode): array` — OpenAI-compatible
  `POST /v1/chat/completions` with `tools` (JSON Schema) and
  `tool_choice: auto`.
- Tool loop: max **8 rounds**, overall **120 s** timeout (cURL). Tool-call
  args are schema-validated (type/required/range); invalid args are fed back
  to the model as a tool error **once**, then the round is aborted cleanly.
- Returns: final assistant text + structured `plan` (when action tools ran in
  dry-run) + tool trace (for the UI detail row).
- System prompt: app description, current user (name, role, department scope),
  the strict rules — *never guess ids (always look up first); if a name is
  ambiguous, ask; quote asset tags; only act through tools; respect the
  permissions you were told you have.*

### 4. `app/Services/Assistant/Tools.php` (tool registry)
Each tool: name, JSON-schema args, handler, `read|action`, min role.

Read tools (execute immediately):
- `find_person(query)` — fuzzy: full name, first+last, initials ("chris b");
  returns matches with id, name, title, department, terminated, held assets.
- `find_asset(query)` — tag or serial, exact then ILIKE.
- `list_persons(terminated?)` · `list_assets(assignee_id?, status?, department_id?)`
  (capped at 25 rows, `total` reported).
- `person_detail(id)` · `asset_detail(id)` — incl. holder, due date, last
  audit entries.
- `department_list()` — resolve "Provision" → id.
- `asset_last_holder(tag)` — last `asset.check_out`/`asset.transfer` actor from
  `audit_log` (answers "whoever #00039 was last used by").

Action tools (dry-run by default; execute only after confirm):
- `terminate_person(id)` / `reinstate_person(id)` — min role **admin**.
- `check_in_asset(id)` / `check_in_all_for_person(person_id, department_id?)`
  — min role **admin/department_manager** (within dept scope). With
  `department_id`: each asset is checked in AND its owning department set to
  the named one (the approved "Provision" semantics).
- `check_out_asset(asset_id, person_id?, department_id?, due_date?)` — person
  must exist and be active; person XOR department.
- `transfer_asset(asset_id, person_id)` — active person only.
- `set_asset_department(asset_id, department_id)`.
- `create_person(fields)` / `update_person(id, fields)` — min role **admin**;
  fields validated like the UI form (full_name required).

Execution rules inside every action tool:
- Re-fetch the entity and re-check the transition (same code paths as the UI:
  `Person::toggleTerminated`, `Asset::setStatus`, `Asset::transfer`,
  `Asset::update`, `Person::create/update`).
- Batch tools return per-item results: `{ok: n, items: [{tag, ok, reason}]}` —
  e.g. "5 checked in, 1 skipped (in repair)".
- RBAC failure → tool returns a refusal string (the model relays it); nothing
  is executed.

### 5. Plan confirmation (two-phase)
1. `POST /assistant/chat` with `executeMode=false` (default). Action tool
   calls run as **previews**; the server records the concrete plan
   (tool + validated, resolved args + preview text) in the PHP session as
   `assistant_plan`. Response: model text + `plan` structure.
2. UI renders the plan card (operation rows) with **Confirm** / **Cancel**.
   - `POST /assistant/confirm` → executes recorded ops through the model layer
     (no inference), collects per-item results, logs `assistant.execute`
     (actor, plan, results), renders ✓/⚠ rows.
   - `POST /assistant/cancel` → discards the plan.
   - A new user message implicitly cancels any stale plan.
3. Read-only conversations never create a plan.

### 6. Assistant page (`/assistant`, all roles)
- Nav item (chat icon) above the admin section.
- Model status strip: loaded model + state dot, or "No model running —
  [Start selected model] · Configure in System".
- Chat panel: user right / assistant left; assistant text is the model's own
  words; plan cards and result tables are structured HTML (not model markup).
- History: localStorage, last ~50 messages, Clear button. No DB table.
- Audit: every user message logged as `assistant.query` (role, raw text, plan
  summary if any) — the model layer already logs each executed action.

### 7. System tab → "AI Assistant" card (admin)
- Model dropdown (all `.gguf` in dir: name · size · mtime) + Rescan.
- Start / Stop / Restart + live state (poll every 3 s while visible).
- Models folder path display ("drop `.gguf` files here").
- Collapsed advanced: context size, port.
- If `llama-server` binary missing: "Install via Homebrew" action
  (`brew install llama.cpp` via the installer's sudo mechanism).

## Data flow (example: "chris b got terminated and returned all their equipment")

1. User message → `assistant.query` audit row.
2. Model calls `find_person("chris b")` → match: Christopher Baldolvsky (id 7,
   6 assets checked out).
3. Model calls `terminate_person(7)` [dry-run] → preview: "mark terminated".
4. Model calls `check_in_all_for_person(7, department_id=3)` — it called
   `department_list()` first to resolve "Provision" (when no department is
   named the tool does a plain check-in to available stock; the model may ask
   the user first) → preview: "check in 6 assets → Provision, set owning
   department".
5. Response: model text summarizing the plan + plan card (3 ops).
6. User clicks Confirm → ops execute via model layer; results render per item;
   `assistant.execute` + `person.terminate` + 6 × `asset.check_in` audit rows.

## Safety & guardrails

- Model has no direct write access; only schema-validated tool calls.
- Ids are resolved by lookup tools and re-validated at execution (a
  hallucinated id fails before any write).
- Role + department scope enforced inside tools, identical to the UI.
- Two-phase confirm for every action; cancel/new-message discards the plan.
- 8 tool rounds / 120 s timeout / 25-row result caps; failures surface as
  clean in-chat messages, never as 500s.
- Server binds 127.0.0.1 only; no auth on the port is acceptable (loopback,
  same host as the app), app remains the only client.

## Error handling

| Situation | Behavior |
|---|---|
| No model selected | Assistant page offers "choose + start in System" (or start selected) |
| Server not running | Auto-start on send; status strip shows loading; 90 s load budget |
| Server died mid-chat | Dead-PID detect on next send → inline "Restart model" |
| Bad tool args | One model-side retry with the error; then clean refusal text |
| Model without tool template | Questions work; actions → "load a tool-calling model (c4ai template)" |
| Batch partially fails | Per-item ✓/⚠ with reasons; never silent |
| Timeout | "Model took too long — try a smaller model"; conversation intact |
| `brew install llama.cpp` needed | System card install action; clear error if it fails |

## Config / schema / file changes

- **No new tables.** Settings keys above. New dirs: `storage/models`
  (gitignored, created by installer), `storage/run/llama.pid`.
- New files: `app/Services/LlmServer.php`, `app/Services/LlmClient.php`,
  `app/Services/Assistant/Tools.php`, `app/Controllers/AssistantController.php`,
  `templates/assistant/index.php`, System card section in
  `templates/admin/system.php`, nav link in
  `templates/layout.php`, CSS additions in `public/theme/css/app.css`,
  page-specific JS in `public/theme/js/assistant.js` (loaded only on the
  Assistant page), routes in
  `config/routes.php`, installer step (llama.cpp + models dir) in
  `install/install.sh`.
- Routes: `GET /assistant`, `POST /assistant/chat`, `POST /assistant/confirm`,
  `POST /assistant/cancel`, `POST /admin/llm/{select,start,stop,restart,install}`.

## Verification plan

1. Install llama.cpp; fetch one small reference GGUF for end-to-end testing —
   Qwen2.5-3B-Instruct (c4ai-toolcalling, ~2 GB) into `storage/models`
   (user-approved; replaceable with the user's own models, e.g. Cactus Needle).
2. Extend the web sweep: assistant page 200 for all three roles; System card
   200 + model list; (if a model is loaded) send a read query, assert answer
   contains the expected asset tag; send the terminate+return instruction on a
   fixture person, assert plan card appears, confirm, assert DB state.
3. Manual scenarios: the "chris b" flow end-to-end; the "#00039 last holder"
   question; ambiguous-name ask-back; viewer role refusal; server kill/restart.
4. All existing sweep checks remain green; audit log shows the expected rows;
   live DB left clean (fixtures removed).

## Open items (accepted)

- Tool-calling quality is model-dependent; the reference model verifies the
  plumbing, the user's chosen production model may need the c4ai tool template
  for best results.
- `llama-server` run under the app user (not launchd): after a Mac reboot the
  user starts it once from System or the Assistant page.
