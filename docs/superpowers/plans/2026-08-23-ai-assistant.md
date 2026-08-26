# AI Assistant (Natural-Language Interface) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a local-LLM chat assistant to ATR Inventory that answers questions and performs inventory actions (terminate person, check in/out, transfer, …) from natural language, using a user-selected GGUF model served by a locally managed `llama-server`.

**Architecture:** `llama-server` (llama.cpp, Homebrew) runs on 127.0.0.1:8082, spawned/stopped by the app (PID file, health probe via `/v1/models`). PHP `LlmClient` calls the OpenAI-compatible chat API with JSON-schema tools; a tool loop executes calls through `Assistant/Tools`, which wraps the existing model layer (RBAC, scoping, audit intact). Action tools run two-phase: dry-run preview → plan card → user confirms → execute.

**Tech Stack:** PHP 8.5 (vanilla, static model classes, PDO pgsql), PostgreSQL 18, llama.cpp `llama-server` (Metal), curl ext, existing Nginx + PHP-FPM. No new composer deps, no new DB tables.

**Spec:** `docs/superpowers/specs/2026-08-23-ai-assistant-design.md` — read it first; this plan implements it.

## Global Constraints

- Git: work happens on branch `feature/ai-assistant` (baseline commit `d726763` on main); one commit per task with a clear subject (required by the subagent-driven execution the user selected). NEVER push — no remote exists.
- No comments in new code unless explaining a non-obvious "why" (repo style: sparse comments).
- `declare(strict_types=1)` in every new PHP file; `final class` for services; static methods (repo convention).
- Bind booleans to PG as `1`/`0`, never PHP `true`/`false` (PDO native prepares send `false` as `""` → error 22P02).
- Every POST route is CSRF-checked by `App` (`_token` required) — include `_token` in all curl tests, fetched from the page (`name="_token" value="…"`).
- Live DB is a production deployment: never leave fixture rows behind; every test that mutates data must clean up (DELETE + verify count 0).
- Web app: `http://127.0.0.1:8081`. Admin `admin`/`Admin1234`, manager `manager`/`Manager1234`, viewer `viewer`/`Viewer1234`.
- DB access for tests: `PGPASSWORD=testpass123 psql -h 127.0.0.1 -U atr -d atr -tAc "…"`
- LLM server binds 127.0.0.1 only; defaults: models dir `storage/models`, port 8082, context 8192.
- Settings keys (no migration — `Setting::set` upserts): `llm.models_dir`, `llm.port`, `llm.context`, `llm.selected_model`.
- Verification style (no test framework in repo): targeted `curl`/`psql`/`php -r` assertions per task + the shared web sweep at `/var/folders/69/3nhlrb8s7m708ngjx1m9l9xm0000gr/T/opencode/web_sweep.sh` (baseline 56/56; Task 9 extends it to 64 baseline, 71 when the model is ready — it must end 0-failed; the sweep's checkout/transfer/check-in section must use its own fixture asset, never a real user asset).
- Lint gate after every PHP change: `php -l <file>` → "No syntax errors".

## File Structure

| File | Responsibility |
|---|---|
| Create `app/Services/LlmServer.php` | Models dir scan, settings, llama-server lifecycle (state/start/stop/ensureRunning), binary lookup, install |
| Create `app/Services/LlmClient.php` | OpenAI-compatible chat request + tool-call loop (rounds/timeout/error capture) |
| Create `app/Services/Assistant/Tools.php` | Tool registry: OpenAI JSON-schema definitions + read/action handlers (dry-run & execute) |
| Create `app/Controllers/AssistantController.php` | `/assistant` page, `chat`, `confirm`, `cancel`, `clear`, `state` |
| Modify `app/Controllers/AdminController.php` | `llmState/llmSelect/llmStart/llmStop/llmConfig/llmInstall` actions |
| Modify `app/Models/Asset.php` | Add `setDepartment(int, ?int, ?array)` |
| Create `templates/assistant/index.php` | Chat page (status strip, messages, plan card, composer) |
| Create `public/theme/js/assistant.js` | Chat client: send, render, confirm/cancel, state polling, localStorage history |
| Modify `templates/layout.php` | Nav item "Assistant" (all roles) |
| Modify `templates/admin/system.php` | "AI Assistant" panel |
| Modify `public/theme/css/app.css` | Chat + AI-panel styles |
| Modify `config/routes.php` | 6 assistant routes + 6 admin llm routes |
| Modify `install/install.sh` | `ensure_formula llama.cpp llama-server`; add `run` + `models` to storage dirs |
| Modify `README.md`, `docs/ARCHITECTURE.md`, `docs/INSTALL.md` | Feature + ops docs |
| Modify sweep script (tmp dir) | Assistant page/RBAC checks (always) + LLM end-to-end (auto-skipped when no model is ready) |

---

### Task 1: LlmServer service (models dir, settings, lifecycle)

**Files:**
- Create: `app/Services/LlmServer.php`
- Test: ad-hoc `php -r` via `bin/_bootstrap.php`

**Interfaces:**
- Consumes: `App\Models\Setting` (`get(string, mixed): mixed`, `set(string, mixed): void`), `App\Core\Logger`, `BASE_PATH`.
- Produces (exact names — later tasks depend on these):
  - `LlmServer::modelsDir(): string` · `LlmServer::models(): array` (`[['name','size','mtime'],…]` sorted by name)
  - `LlmServer::port(): int` · `LlmServer::context(): int`
  - `LlmServer::selectedModel(): ?string` · `LlmServer::setSelectedModel(string): void`
  - `LlmServer::binary(): ?string`
  - `LlmServer::state(): array` — `['status'=>'stopped'|'loading'|'ready','model'=>?string,'selected'=>?string,'matches_selected'=>bool,'pid'=>?int,'binary'=>?string,'models'=>array]`
  - `LlmServer::start(): void` (throws `RuntimeException`) · `LlmServer::stop(): void` · `LlmServer::ensureRunning(): void` (≤90 s) · `LlmServer::install(): array` (`['ok'=>bool,'output'=>string]`)

- [ ] **Step 1: Create the service**

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Logger;
use App\Models\Setting;
use RuntimeException;

final class LlmServer
{
    public static function modelsDir(): string
    {
        $dir = (string) Setting::get('llm.models_dir', BASE_PATH . '/storage/models');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    public static function models(): array
    {
        $out = [];
        foreach (glob(self::modelsDir() . '/*.gguf') ?: [] as $file) {
            $out[] = [
                'name' => basename($file),
                'size' => (int) filesize($file),
                'mtime' => (int) filemtime($file),
            ];
        }
        usort($out, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));
        return $out;
    }

    public static function port(): int
    {
        return max(1, (int) Setting::get('llm.port', 8082));
    }

    public static function context(): int
    {
        return max(512, (int) Setting::get('llm.context', 8192));
    }

    public static function selectedModel(): ?string
    {
        $sel = (string) Setting::get('llm.selected_model', '');
        if ($sel !== '' && is_file(self::modelsDir() . '/' . basename($sel))) {
            return basename($sel);
        }
        $models = self::models();
        return $models[0]['name'] ?? null;
    }

    public static function setSelectedModel(string $name): void
    {
        Setting::set('llm.selected_model', basename($name));
    }

    public static function binary(): ?string
    {
        $out = trim((string) shell_exec('command -v llama-server 2>/dev/null'));
        return $out !== '' ? $out : null;
    }

    private static function pidFile(): string
    {
        return BASE_PATH . '/storage/run/llama.pid';
    }

    private static function pidAlive(int $pid): bool
    {
        return (int) shell_exec('kill -0 ' . $pid . ' 2>/dev/null; echo $?') === 0;
    }

    private static function httpGet(string $url, int $timeout = 1): ?string
    {
        $ctx = stream_context_create(['http' => ['timeout' => $timeout, 'ignore_errors' => true]]);
        $raw = @file_get_contents($url, false, $ctx);
        return $raw === false ? null : $raw;
    }

    public static function state(): array
    {
        $selected = self::selectedModel();
        $pid = null;
        $pidf = self::pidFile();
        if (is_file($pidf)) {
            $p = (int) trim((string) file_get_contents($pidf));
            if ($p > 0 && self::pidAlive($p)) {
                $pid = $p;
            } else {
                @unlink($pidf);
            }
        }

        $loaded = null;
        $raw = self::httpGet('http://127.0.0.1:' . self::port() . '/v1/models', 1);
        if ($raw !== null) {
            $data = json_decode($raw, true);
            $loaded = $data['data'][0]['id'] ?? null;
        }

        $status = $loaded !== null ? 'ready' : ($pid !== null ? 'loading' : 'stopped');

        return [
            'status' => $status,
            'model' => $loaded !== null ? basename((string) $loaded) : null,
            'selected' => $selected,
            'matches_selected' => $status === 'ready' && $selected !== null && self::modelMatches($loaded, $selected),
            'pid' => $pid,
            'binary' => self::binary(),
            'models' => self::models(),
        ];
    }

    private static function modelMatches(?string $loaded, string $selected): bool
    {
        $loaded = (string) $loaded;
        return $loaded === $selected
            || str_ends_with($loaded, '/' . $selected)
            || str_ends_with($loaded, '/' . basename($selected));
    }

    public static function start(): void
    {
        $bin = self::binary();
        if ($bin === null) {
            throw new RuntimeException('llama-server not found. Install llama.cpp from the System tab or run: brew install llama.cpp');
        }
        $model = self::selectedModel();
        if ($model === null) {
            throw new RuntimeException('No .gguf model found in ' . self::modelsDir() . '. Drop a model file there first.');
        }
        $path = self::modelsDir() . '/' . $model;

        $st = self::state();
        if ($st['status'] === 'ready' && $st['matches_selected']) {
            return;
        }
        self::stop();

        if (!is_dir(dirname(self::pidFile()))) {
            @mkdir(dirname(self::pidFile()), 0775, true);
        }
        $log = BASE_PATH . '/storage/logs/llama.log';
        $cmd = sprintf(
            'nohup %s --host 127.0.0.1 --port %d -m %s -ngl 99 -c %d -np 1 --alias %s >> %s 2>&1 & echo $!',
            escapeshellarg($bin),
            self::port(),
            escapeshellarg($path),
            self::context(),
            escapeshellarg($model),
            escapeshellarg($log)
        );
        $pid = (int) trim((string) shell_exec($cmd));
        if ($pid <= 0) {
            throw new RuntimeException('Failed to launch llama-server. Check storage/logs/llama.log.');
        }
        file_put_contents(self::pidFile(), (string) $pid);
        Logger::info('llama-server started', ['pid' => $pid, 'model' => $model]);
    }

    public static function stop(): void
    {
        $st = self::state();
        if ($st['pid'] !== null) {
            shell_exec('kill ' . (int) $st['pid'] . ' 2>/dev/null');
        }
        for ($i = 0; $i < 20; $i++) {
            if (self::state()['status'] === 'stopped') {
                break;
            }
            usleep(500000);
        }
        @unlink(self::pidFile());
    }

    public static function ensureRunning(): void
    {
        $st = self::state();
        if ($st['status'] === 'ready' && $st['matches_selected']) {
            return;
        }
        self::start();
        $deadline = time() + 90;
        while (time() < $deadline) {
            $st = self::state();
            if ($st['status'] === 'ready' && $st['matches_selected']) {
                return;
            }
            if ($st['status'] === 'stopped') {
                throw new RuntimeException('llama-server exited during load. Check storage/logs/llama.log.');
            }
            sleep(2);
        }
        throw new RuntimeException('Model is still loading. Try again in a moment or choose a smaller model.');
    }

    public static function install(): array
    {
        $out = (string) shell_exec('brew install llama.cpp 2>&1');
        return ['ok' => self::binary() !== null, 'output' => $out];
    }
}
```

`--alias` makes `/v1/models` report the bare filename so `matches_selected` works; `modelMatches()` tolerates full-path ids on older builds. If an old llama.cpp rejects `--alias`, drop the flag — the fallback comparisons still work (verify in Task 2).

- [ ] **Step 2: Verify stopped state (llama.cpp not installed yet)**

Run:
```bash
cd /Users/zacheri/Documents/ATR && php -r '
require "bin/_bootstrap.php";
use App\Services\LlmServer;
$s = LlmServer::state();
var_export(["status"=>$s["status"],"binary"=>$s["binary"],"models"=>count($s["models"]),"dir"=>LlmServer::modelsDir(),"port"=>LlmServer::port(),"ctx"=>LlmServer::context()]);
echo "\n";'
```
Expected: `status => 'stopped'`, `binary => NULL`, `models => 0`, `dir` ends in `/storage/models`, `port => 8082`, `ctx => 8192`; `storage/models` directory created.

- [ ] **Step 3: Verify model listing + selection fallback**

Run:
```bash
cd /Users/zacheri/Documents/ATR && touch storage/models/fake-a.gguf storage/models/fake-b.gguf && php -r '
require "bin/_bootstrap.php";
use App\Services\LlmServer;
var_export(LlmServer::models());
echo "\nselected=" . var_export(LlmServer::selectedModel(), true) . "\n";' && rm storage/models/fake-a.gguf storage/models/fake-b.gguf
```
Expected: two entries sorted by name; `selected='fake-a.gguf'`.

- [ ] **Step 4: Lint** — `php -l app/Services/LlmServer.php` → No syntax errors.

---

### Task 2: llama.cpp install + reference model, start/stop verified

Purpose: a real tool-capable model on 127.0.0.1:8082 so Tasks 3–5 test end to end.

**Files:**
- Modify: `install/install.sh`

**Interfaces:**
- Consumes: Task 1 (`start/stop/state/install/binary`).
- Produces: a running `llama-server` with the reference model for all later tasks.

- [ ] **Step 1: Add llama.cpp to the installer** — in `install/install.sh`, after the `ensure_formula composer composer` line:
```bash
ensure_formula llama.cpp llama-server
```

- [ ] **Step 2: Install the formula** — Run: `brew install llama.cpp`. Verify: `command -v llama-server` prints a path.

- [ ] **Step 3: Download the reference model (~2.5 GB)**

Run:
```bash
cd /Users/zacheri/Documents/ATR && curl -L --fail --retry 3 -o storage/models/Qwen3-4B-Q4_K_M.gguf \
  "https://huggingface.co/ggml-org/Qwen3-4B-GGUF/resolve/main/Qwen3-4B-Q4_K_M.gguf" && ls -lh storage/models/
```
Expected: file ~2.5 GB. Fallback if that URL 404s:
```bash
curl -L --fail --retry 3 -o storage/models/Qwen3-4B-Q4_K_M.gguf \
  "https://huggingface.co/QuantFactory/Qwen3-4B-GGUF/resolve/main/qwen3-4b-q4_k_m.gguf"
```
If both fail (offline), ask the user to drop any tool-calling GGUF (e.g. their Cactus Needle) into `storage/models` and continue with that file name.

- [ ] **Step 4: Select + start via the service**

Run:
```bash
cd /Users/zacheri/Documents/ATR && php -r '
require "bin/_bootstrap.php";
use App\Services\LlmServer;
LlmServer::setSelectedModel("Qwen3-4B-Q4_K_M.gguf");
LlmServer::ensureRunning();
var_export(LlmServer::state());
echo "\n";'
```
Expected: `status => 'ready'`, `model => 'Qwen3-4B-Q4_K_M.gguf'`, `matches_selected => true`, numeric `pid`. (First load: 5–30 s on M3 Max.)

- [ ] **Step 5: Verify the OpenAI endpoint answers**

Run:
```bash
curl -s http://127.0.0.1:8082/v1/chat/completions -H 'Content-Type: application/json' \
  -d '{"model":"Qwen3-4B-Q4_K_M.gguf","messages":[{"role":"user","content":"Reply with exactly: PONG"}]}' | head -c 400
```
Expected: JSON containing `"PONG"`.

- [ ] **Step 6: Verify stop**

Run:
```bash
cd /Users/zacheri/Documents/ATR && php -r 'require "bin/_bootstrap.php"; App\Services\LlmServer::stop(); var_export(App\Services\LlmServer::state()["status"]); echo "\n";'
```
Expected: `stopped`. (Later test snippets auto-start via `ensureRunning()`; leave it running at the end of this task to save time.)

- [ ] **Step 7: Installer sanity** — `bash -n install/install.sh` → no output.

---

### Task 3: LlmClient (chat + tool loop)

**Files:**
- Create: `app/Services/LlmClient.php`
- Test: ad-hoc `php -r` (server must be running)

**Interfaces:**
- Consumes: `LlmServer::port()`, `LlmServer::selectedModel()`, `App\Core\Logger`.
- Produces:
  - `LlmClient::chat(array $messages, array $toolDefs, callable $toolHandler, int $maxRounds = 8, int $timeout = 120): array` → `['text' => string, 'trace' => array]`. `$toolHandler(string $name, array $args): mixed`; its return value is JSON-encoded into the `tool` message; `RuntimeException` from the handler becomes `{"error":"…"}`.
  - `LlmClient::request(string $path, array $body, int $timeout = 120): array`

- [ ] **Step 1: Write the failing check** — Run:
```bash
cd /Users/zacheri/Documents/ATR && php -r 'require "bin/_bootstrap.php"; new App\Services\LlmClient;' 2>&1 | head -1
```
Expected: Fatal error — class not found.

- [ ] **Step 2: Create the client**

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Logger;
use RuntimeException;

final class LlmClient
{
    public static function chat(array $messages, array $toolDefs, callable $toolHandler, int $maxRounds = 8, int $timeout = 120): array
    {
        $trace = [];
        $deadline = time() + $timeout;
        for ($round = 0; $round < $maxRounds; $round++) {
            if (time() > $deadline) {
                throw new RuntimeException('The model took too long. Try a smaller model or rephrase.');
            }
            $body = [
                'model' => LlmServer::selectedModel(),
                'messages' => $messages,
                'temperature' => 0.2,
                'max_tokens' => 1024,
            ];
            if ($toolDefs !== []) {
                $body['tools'] = $toolDefs;
                $body['tool_choice'] = 'auto';
            }
            $res = self::request('/v1/chat/completions', $body, $timeout);
            $msg = $res['choices'][0]['message'] ?? null;
            if (!is_array($msg)) {
                throw new RuntimeException('Empty model response.');
            }
            $calls = $msg['tool_calls'] ?? [];
            if ($calls === []) {
                return ['text' => (string) ($msg['content'] ?? ''), 'trace' => $trace];
            }
            $messages[] = $msg;
            foreach ($calls as $call) {
                $name = (string) ($call['function']['name'] ?? '');
                $args = json_decode((string) ($call['function']['arguments'] ?? '{}'), true);
                if (!is_array($args)) {
                    $args = [];
                }
                try {
                    $result = $toolHandler($name, $args);
                    if (!is_array($result)) {
                        $result = ['message' => (string) $result];
                    }
                } catch (RuntimeException $e) {
                    $result = ['error' => $e->getMessage()];
                }
                $trace[] = ['tool' => $name, 'args' => $args, 'result' => $result];
                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => (string) ($call['id'] ?? ''),
                    'content' => json_encode($result, JSON_UNESCAPED_SLASHES),
                ];
            }
        }
        return ['text' => 'I ran out of steps before finishing. Please rephrase the request.', 'trace' => $trace];
    }

    public static function request(string $path, array $body, int $timeout = 120): array
    {
        $ch = curl_init('http://127.0.0.1:' . LlmServer::port() . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer atr-local'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => $timeout,
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            Logger::error('llama-server unreachable', ['error' => $err]);
            throw new RuntimeException('Cannot reach the local model server. Start it from the System tab.');
        }
        $data = json_decode((string) $raw, true);
        if ($code < 200 || $code >= 300 || !is_array($data)) {
            $detail = is_array($data)
                ? (string) ($data['error']['message'] ?? substr((string) $raw, 0, 200))
                : substr((string) $raw, 0, 200);
            throw new RuntimeException('Model server error ' . $code . ': ' . $detail);
        }
        return $data;
    }
}
```

- [ ] **Step 3: Verify plain chat (no tools)** — Run:
```bash
cd /Users/zacheri/Documents/ATR && php -r '
require "bin/_bootstrap.php";
use App\Services\LlmServer; use App\Services\LlmClient;
LlmServer::ensureRunning();
$r = LlmClient::chat([["role"=>"user","content"=>"Reply with exactly: PONG"]], [], fn($n,$a)=>[]);
var_export($r); echo "\n";'
```
Expected: `text => 'PONG'` (± punctuation), `trace => []`.

- [ ] **Step 4: Verify the tool loop with a fake tool** — Run:
```bash
cd /Users/zacheri/Documents/ATR && php -r '
require "bin/_bootstrap.php";
use App\Services\LlmServer; use App\Services\LlmClient;
LlmServer::ensureRunning();
$defs = [["type"=>"function","function"=>["name"=>"current_time","description"=>"Get the current time","parameters"=>["type"=>"object","properties"=>[],"required"=>[]]]]];
$r = LlmClient::chat([["role"=>"user","content"=>"What time is it right now?"]], $defs,
  fn(string $n, array $a): array => $n === "current_time" ? ["time" => date("H:i")] : ["error"=>"unknown tool"]);
echo $r["text"], "\n";
var_export(array_column($r["trace"], "tool")); echo "\n";'
```
Expected: `trace` contains `"current_time"` exactly once; the answer states an actual HH:MM time.

- [ ] **Step 5: Lint** — `php -l app/Services/LlmClient.php` → No syntax errors.

---

### Task 4: Tools registry (reads + action dry-runs/execute) + Asset::setDepartment

**Files:**
- Create: `app/Services/Assistant/Tools.php`
- Modify: `app/Models/Asset.php` (add `setDepartment`)
- Test: `php -r` with psql fixtures (clean up after)

**Interfaces:**
- Consumes: `App\Core\Auth` (`scopeWhere(string $alias): array`), `App\Core\Database`, `App\Models\{Person,Asset,Department,Audit}`; `Asset::setStatus(int, string, array, ?array)`, `Asset::transfer(int, int, ?array)`, `Asset::find(int, ?array)`, `Person::find/create/update/toggleTerminated`, `Department::all()`, `Audit::log(string,string,string,array)`.
- Produces (Task 5/6/9 depend on these exact names):
  - `Tools::definitions(): array` — OpenAI `tools` list (17 tools)
  - `Tools::isAction(string $name): bool`
  - `Tools::execute(string $name, array $args, array $user, bool $executeMode): array`
    - reads: identical in both modes
    - actions: `executeMode=false` → validates, returns `['preview'=>string,'op'=>string,'args'=>array]` or `['error'=>string]`; `executeMode=true` → performs, returns `['ok'=>bool,...]` / per-item results / `['error'=>string]`
  - Tool names (fixed): reads `find_person, find_asset, list_persons, list_assets, person_detail, asset_detail, department_list, asset_last_holder`; actions `terminate_person, reinstate_person, check_in_asset, check_in_all_for_person, check_out_asset, transfer_asset, set_asset_department, create_person, update_person`

- [ ] **Step 1: Add Asset::setDepartment** — append to `app/Models/Asset.php` next to `transfer()`:

```php
    public static function setDepartment(int $id, ?int $departmentId, ?array $user): void
    {
        $asset = self::find($id, $user);
        if ($asset === null) {
            throw new RuntimeException('Asset not found.');
        }
        if ($departmentId !== null) {
            $dept = Database::fetchColumn('SELECT name FROM departments WHERE id = :id', ['id' => $departmentId]);
            if ($dept === false || $dept === null) {
                throw new RuntimeException('Department not found.');
            }
        }
        Database::execute(
            'UPDATE assets SET department_id = :d, updated_at = now() WHERE id = :id',
            ['d' => $departmentId, 'id' => $id]
        );
        Audit::log('asset.department_change', 'asset', (string) $id, [
            'asset_tag' => $asset['asset_tag'],
            'department_id' => $departmentId,
        ]);
    }
```

- [ ] **Step 2: Create the tool registry**

```php
<?php

declare(strict_types=1);

namespace App\Services\Assistant;

use App\Core\Auth;
use App\Core\Database;
use App\Models\Asset;
use App\Models\Department;
use App\Models\Person;
use RuntimeException;

final class Tools
{
    private const ACTIONS = [
        'terminate_person', 'reinstate_person', 'check_in_asset', 'check_in_all_for_person',
        'check_out_asset', 'transfer_asset', 'set_asset_department', 'create_person', 'update_person',
    ];

    public static function isAction(string $name): bool
    {
        return in_array($name, self::ACTIONS, true);
    }

    public static function definitions(): array
    {
        $obj = static fn (array $props, array $required = []): array => [
            'type' => 'object', 'properties' => $props, 'required' => $required,
        ];
        $str = static fn (): array => ['type' => 'string'];
        $int = static fn (): array => ['type' => 'integer'];
        $bool = static fn (): array => ['type' => 'boolean'];
        return [
            ['type' => 'function', 'function' => ['name' => 'find_person',
                'description' => 'Search people by name. Fuzzy fragments work: "chris b" matches Christopher Baldolvsky. Returns matches with id, title, department, terminated flag, held-asset count.',
                'parameters' => $obj(['query' => $str()], ['query'])]],
            ['type' => 'function', 'function' => ['name' => 'find_asset',
                'description' => 'Find an asset by exact tag or serial, else close matches. Returns status, holder, due date.',
                'parameters' => $obj(['query' => $str()], ['query'])]],
            ['type' => 'function', 'function' => ['name' => 'list_persons',
                'description' => 'List people (max 25).',
                'parameters' => $obj(['terminated' => $bool()])]],
            ['type' => 'function', 'function' => ['name' => 'list_assets',
                'description' => 'List assets (max 25) with optional filters.',
                'parameters' => $obj(['assignee_id' => $int(), 'status' => $str(), 'department_id' => $int()])]],
            ['type' => 'function', 'function' => ['name' => 'person_detail',
                'description' => 'Full record of one person incl. assets they currently hold.',
                'parameters' => $obj(['id' => $int()], ['id'])]],
            ['type' => 'function', 'function' => ['name' => 'asset_detail',
                'description' => 'Full record of one asset incl. holder and depreciation.',
                'parameters' => $obj(['id' => $int()], ['id'])]],
            ['type' => 'function', 'function' => ['name' => 'department_list',
                'description' => 'All departments (id + name). Use to resolve a department name to an id.',
                'parameters' => $obj([])]],
            ['type' => 'function', 'function' => ['name' => 'asset_last_holder',
                'description' => 'Who an asset was last checked out to / transferred to (from the audit trail).',
                'parameters' => $obj(['tag' => $str()], ['tag'])]],
            ['type' => 'function', 'function' => ['name' => 'terminate_person',
                'description' => 'Mark a person as terminated (they keep any assets they hold). Admin only. Preview first.',
                'parameters' => $obj(['id' => $int()], ['id'])]],
            ['type' => 'function', 'function' => ['name' => 'reinstate_person',
                'description' => 'Mark a terminated person as active again. Admin only. Preview first.',
                'parameters' => $obj(['id' => $int()], ['id'])]],
            ['type' => 'function', 'function' => ['name' => 'check_in_asset',
                'description' => 'Check one asset back in to available stock.',
                'parameters' => $obj(['id' => $int()], ['id'])]],
            ['type' => 'function', 'function' => ['name' => 'check_in_all_for_person',
                'description' => 'Check in EVERY asset currently checked out to a person. Optional department_id: if given, each asset is also moved to that owning department (resolve the name via department_list first).',
                'parameters' => $obj(['person_id' => $int(), 'department_id' => $int()], ['person_id'])]],
            ['type' => 'function', 'function' => ['name' => 'check_out_asset',
                'description' => 'Check an available asset out to a person OR a department (exactly one). Person must be active.',
                'parameters' => $obj(['asset_id' => $int(), 'person_id' => $int(), 'department_id' => $int(), 'due_date' => $str()], ['asset_id'])]],
            ['type' => 'function', 'function' => ['name' => 'transfer_asset',
                'description' => 'Transfer a checked-out asset to another active person.',
                'parameters' => $obj(['asset_id' => $int(), 'person_id' => $int()], ['asset_id', 'person_id'])]],
            ['type' => 'function', 'function' => ['name' => 'set_asset_department',
                'description' => 'Change an asset\'s owning department.',
                'parameters' => $obj(['asset_id' => $int(), 'department_id' => $int()], ['asset_id', 'department_id'])]],
            ['type' => 'function', 'function' => ['name' => 'create_person',
                'description' => 'Create a person record. Admin only.',
                'parameters' => $obj([
                    'full_name' => $str(), 'job_title' => $str(), 'work_email' => $str(),
                    'personal_email' => $str(), 'phone' => $str(), 'address' => $str(),
                    'department_id' => $int(), 'notes' => $str(),
                ], ['full_name'])]],
            ['type' => 'function', 'function' => ['name' => 'update_person',
                'description' => 'Update fields of a person record. Admin only. Omit fields to leave unchanged.',
                'parameters' => $obj(['id' => $int(), 'full_name' => $str(), 'job_title' => $str(),
                    'work_email' => $str(), 'personal_email' => $str(), 'phone' => $str(),
                    'address' => $str(), 'department_id' => $int(), 'notes' => $str(),
                    'is_terminated' => $bool()], ['id'])]],
        ];
    }

    public static function execute(string $name, array $args, array $user, bool $executeMode): array
    {
        return match ($name) {
            'find_person' => self::findPerson((string) ($args['query'] ?? '')),
            'find_asset' => self::findAsset((string) ($args['query'] ?? '')),
            'list_persons' => self::listPersons(!empty($args['terminated'])),
            'list_assets' => self::listAssets($args),
            'person_detail' => self::personDetail((int) ($args['id'] ?? 0)),
            'asset_detail' => self::assetDetail((int) ($args['id'] ?? 0), $user),
            'department_list' => self::departmentList(),
            'asset_last_holder' => self::assetLastHolder((string) ($args['tag'] ?? '')),
            'terminate_person' => self::terminatePerson((int) ($args['id'] ?? 0), $user, $executeMode),
            'reinstate_person' => self::reinstatePerson((int) ($args['id'] ?? 0), $user, $executeMode),
            'check_in_asset' => self::checkInAsset((int) ($args['id'] ?? 0), $user, $executeMode),
            'check_in_all_for_person' => self::checkInAllForPerson((int) ($args['person_id'] ?? 0), $args, $user, $executeMode),
            'check_out_asset' => self::checkOutAsset($args, $user, $executeMode),
            'transfer_asset' => self::transferAsset((int) ($args['asset_id'] ?? 0), (int) ($args['person_id'] ?? 0), $user, $executeMode),
            'set_asset_department' => self::setAssetDepartment((int) ($args['asset_id'] ?? 0), (int) ($args['department_id'] ?? 0), $user, $executeMode),
            'create_person' => self::createPerson($args, $user, $executeMode),
            'update_person' => self::updatePerson((int) ($args['id'] ?? 0), $args, $user, $executeMode),
            default => ['error' => 'Unknown tool: ' . $name],
        };
    }

    private static function findPerson(string $query): array
    {
        $tokens = array_values(array_filter(array_map('trim', preg_split('/\s+/', $query) ?: [])));
        if ($tokens === []) {
            return ['matches' => [], 'note' => 'Provide a name fragment to search.'];
        }
        $where = [];
        $params = [];
        foreach ($tokens as $i => $t) {
            $where[] = 'p.full_name ILIKE :t' . $i;
            $params['t' . $i] = '%' . str_replace(['%', '_'], ['\%', '\_'], $t) . '%';
        }
        $rows = Database::fetchAll(
            'SELECT p.id, p.full_name, p.job_title, p.work_email, p.phone, p.is_terminated,
                    d.name AS department,
                    (SELECT COUNT(*) FROM assets a WHERE a.assigned_to_person_id = p.id AND a.status = \'checked_out\')::int AS held
             FROM persons p LEFT JOIN departments d ON d.id = p.department_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY p.full_name LIMIT 5',
            $params
        );
        return $rows === []
            ? ['matches' => [], 'note' => 'No person matches "' . $query . '".']
            : ['matches' => $rows];
    }

    private static function findAsset(string $query): array
    {
        $select = 'SELECT a.id, a.asset_tag, a.serial_number, a.brand, a.model_number, a.status, a.due_date,
                    p.full_name AS holder_person, d.name AS holder_department
             FROM assets a
             LEFT JOIN persons p ON p.id = a.assigned_to_person_id
             LEFT JOIN departments d ON d.id = a.assigned_to_department_id';
        $row = Database::fetchOne($select . ' WHERE a.asset_tag = :q OR a.serial_number = :q', ['q' => $query]);
        if ($row !== null) {
            return ['matches' => [$row]];
        }
        $rows = Database::fetchAll(
            $select . ' WHERE a.asset_tag ILIKE :q OR a.serial_number ILIKE :q ORDER BY a.asset_tag LIMIT 5',
            ['q' => '%' . str_replace(['%', '_'], ['\%', '\_'], $query) . '%']
        );
        return $rows === []
            ? ['matches' => [], 'note' => 'No asset matches "' . $query . '".']
            : ['matches' => $rows];
    }

    private static function listPersons(bool $terminated): array
    {
        $rows = Database::fetchAll(
            'SELECT p.id, p.full_name, p.job_title, p.is_terminated, d.name AS department,
                    (SELECT COUNT(*) FROM assets a WHERE a.assigned_to_person_id = p.id AND a.status = \'checked_out\')::int AS held
             FROM persons p LEFT JOIN departments d ON d.id = p.department_id
             WHERE p.is_terminated = :t ORDER BY p.full_name LIMIT 25',
            ['t' => $terminated ? 1 : 0]
        );
        return ['persons' => $rows];
    }

    private static function listAssets(array $args): array
    {
        [$scope, $params] = Auth::scopeWhere('a');
        $where = ['1=1'];
        if (!empty($args['assignee_id'])) {
            $where[] = 'a.assigned_to_person_id = :pid';
            $params['pid'] = (int) $args['assignee_id'];
        }
        if (!empty($args['status'])) {
            $where[] = 'a.status = :st';
            $params['st'] = (string) $args['status'];
        }
        if (!empty($args['department_id'])) {
            $where[] = 'a.department_id = :did';
            $params['did'] = (int) $args['department_id'];
        }
        $whereSql = implode(' AND ', $where);
        $total = (int) Database::fetchColumn("SELECT COUNT(*) FROM assets a WHERE {$whereSql} {$scope}", $params);
        $rows = Database::fetchAll(
            "SELECT a.id, a.asset_tag, a.brand, a.model_number, a.status, a.due_date,
                    c.name AS category, p.full_name AS holder_person, d.name AS holder_department
             FROM assets a
             LEFT JOIN categories c ON c.id = a.category_id
             LEFT JOIN persons p ON p.id = a.assigned_to_person_id
             LEFT JOIN departments d ON d.id = a.assigned_to_department_id
             WHERE {$whereSql} {$scope}
             ORDER BY a.asset_tag LIMIT 25",
            $params
        );
        return ['total' => $total, 'assets' => $rows];
    }

    private static function personDetail(int $id): array
    {
        $p = Person::find($id);
        if ($p === null) {
            return ['error' => 'Person not found (id ' . $id . ').'];
        }
        $assets = Database::fetchAll(
            'SELECT asset_tag, status, due_date FROM assets
             WHERE assigned_to_person_id = :id AND status = \'checked_out\' ORDER BY asset_tag',
            ['id' => $id]
        );
        return ['person' => $p, 'checked_out_assets' => $assets];
    }

    private static function assetDetail(int $id, array $user): array
    {
        $a = Asset::find($id, $user);
        if ($a === null) {
            return ['error' => 'Asset not found (id ' . $id . ').'];
        }
        unset($a['photos'], $a['audit']);
        return ['asset' => $a];
    }

    private static function departmentList(): array
    {
        return ['departments' => Department::all()];
    }

    private static function assetLastHolder(string $tag): array
    {
        $candidates = [$tag];
        if (ctype_digit($tag)) {
            $candidates[] = str_pad($tag, 5, '0', STR_PAD_LEFT);
        }
        $whereSql = implode(' OR ', array_map(static fn (string $c, int $i): string => 'a.asset_tag = :c' . $i, $candidates, array_keys($candidates)));
        $params = [];
        foreach ($candidates as $i => $c) {
            $params['c' . $i] = $c;
        }
        $assetId = Database::fetchColumn('SELECT a.id FROM assets a WHERE ' . $whereSql, $params);
        if ($assetId === null) {
            return ['error' => 'No asset with tag "' . $tag . '".'];
        }
        $row = Database::fetchOne(
            'SELECT al.details->>"to" AS holder, al.action, al.created_at
             FROM audit_log al
             WHERE al.entity = \'asset\' AND al.entity_id = :aid
               AND al.action IN (\'asset.check_out\', \'asset.transfer\')
             ORDER BY al.created_at DESC LIMIT 1',
            ['aid' => (string) $assetId]
        );
        if ($row === null) {
            return ['tag' => $tag, 'holder' => null, 'note' => 'No check-out or transfer recorded for this asset.'];
        }
        return ['tag' => $tag, 'holder' => $row['holder'], 'when' => (string) $row['created_at'], 'via' => $row['action']];
    }

    private static function requireRole(array $user, array $allowed, string $label): ?array
    {
        if (!in_array($user['role_name'] ?? '', $allowed, true)) {
            return ['error' => "You don't have permission to {$label} (requires " . implode('/', $allowed) . ').'];
        }
        return null;
    }

    private static function terminatePerson(int $id, array $user, bool $executeMode): array
    {
        if ($deny = self::requireRole($user, ['admin'], 'terminate a person')) {
            return $deny;
        }
        $p = Person::find($id);
        if ($p === null) {
            return ['error' => 'Person not found (id ' . $id . ').'];
        }
        if ($p['is_terminated']) {
            return ['error' => $p['full_name'] . ' is already terminated.'];
        }
        if (!$executeMode) {
            return ['preview' => 'Mark ' . $p['full_name'] . ' as terminated.', 'op' => 'terminate_person', 'args' => ['id' => $id]];
        }
        Person::toggleTerminated($id);
        return ['ok' => true, 'message' => $p['full_name'] . ' marked as terminated.'];
    }

    private static function reinstatePerson(int $id, array $user, bool $executeMode): array
    {
        if ($deny = self::requireRole($user, ['admin'], 'reinstate a person')) {
            return $deny;
        }
        $p = Person::find($id);
        if ($p === null) {
            return ['error' => 'Person not found (id ' . $id . ').'];
        }
        if (empty($p['is_terminated'])) {
            return ['error' => $p['full_name'] . ' is already active.'];
        }
        if (!$executeMode) {
            return ['preview' => 'Reinstate ' . $p['full_name'] . ' (mark active).', 'op' => 'reinstate_person', 'args' => ['id' => $id]];
        }
        Person::toggleTerminated($id);
        return ['ok' => true, 'message' => $p['full_name'] . ' reinstated.'];
    }

    private static function checkInAsset(int $id, array $user, bool $executeMode): array
    {
        if ($deny = self::requireRole($user, ['admin', 'department_manager'], 'check in assets')) {
            return $deny;
        }
        $a = Asset::find($id, $user);
        if ($a === null) {
            return ['error' => 'Asset not found (id ' . $id . ').'];
        }
        if ($a['status'] === 'available') {
            return ['error' => $a['asset_tag'] . ' is already available.'];
        }
        if (in_array($a['status'], ['disposed', 'sold', 'donated'], true)) {
            return ['error' => $a['asset_tag'] . ' is ' . $a['status'] . ' and cannot be checked in.'];
        }
        if (!$executeMode) {
            return ['preview' => 'Check in ' . $a['asset_tag'] . ' (currently ' . $a['status'] . ').', 'op' => 'check_in_asset', 'args' => ['id' => $id]];
        }
        try {
            Asset::setStatus($id, 'available', [], $user);
        } catch (RuntimeException $e) {
            return ['error' => $e->getMessage()];
        }
        return ['ok' => true, 'message' => $a['asset_tag'] . ' checked in and available.'];
    }

    private static function checkInAllForPerson(int $personId, array $args, array $user, bool $executeMode): array
    {
        if ($deny = self::requireRole($user, ['admin', 'department_manager'], 'check in assets')) {
            return $deny;
        }
        $p = Person::find($personId);
        if ($p === null) {
            return ['error' => 'Person not found (id ' . $personId . ').'];
        }
        $deptId = !empty($args['department_id']) ? (int) $args['department_id'] : null;
        $deptName = null;
        if ($deptId !== null) {
            $deptName = Database::fetchColumn('SELECT name FROM departments WHERE id = :id', ['id' => $deptId]);
            if ($deptName === false || $deptName === null) {
                return ['error' => 'Department not found (id ' . $deptId . '). Use department_list to see valid departments.'];
            }
        }
        [$scope, $scopeParams] = Auth::scopeWhere('a');
        $assets = Database::fetchAll(
            'SELECT a.id, a.asset_tag, a.status FROM assets a
             WHERE a.assigned_to_person_id = :pid AND a.status = \'checked_out\' ' . $scope . '
             ORDER BY a.asset_tag',
            array_merge(['pid' => $personId], $scopeParams)
        );
        if ($assets === []) {
            return ['note' => $p['full_name'] . ' has no assets currently checked out.'];
        }
        $suffix = $deptName !== null ? ' and move each to the ' . $deptName . ' department' : '';
        if (!$executeMode) {
            return [
                'preview' => 'Check in ' . count($assets) . ' asset(s) held by ' . $p['full_name']
                    . ' (' . implode(', ', array_column($assets, 'asset_tag')) . ')' . $suffix . '.',
                'op' => 'check_in_all_for_person',
                'args' => ['person_id' => $personId, 'department_id' => $deptId],
            ];
        }
        $items = [];
        foreach ($assets as $a) {
            try {
                Asset::setStatus((int) $a['id'], 'available', [], $user);
                if ($deptId !== null) {
                    Asset::setDepartment((int) $a['id'], $deptId, $user);
                }
                $items[] = ['tag' => $a['asset_tag'], 'ok' => 1, 'reason' => 'checked in' . ($deptId !== null ? ' → ' . $deptName : '')];
            } catch (\Throwable $e) {
                $items[] = ['tag' => $a['asset_tag'], 'ok' => 0, 'reason' => $e->getMessage()];
            }
        }
        $okCount = count(array_filter($items, static fn (array $i) => $i['ok'] === 1));
        return ['ok' => $okCount === count($items), 'checked_in' => $okCount, 'total' => count($items), 'items' => $items];
    }

    private static function checkOutAsset(array $args, array $user, bool $executeMode): array
    {
        if ($deny = self::requireRole($user, ['admin', 'department_manager'], 'check out assets')) {
            return $deny;
        }
        $assetId = (int) ($args['asset_id'] ?? 0);
        $a = Asset::find($assetId, $user);
        if ($a === null) {
            return ['error' => 'Asset not found (id ' . $assetId . ').'];
        }
        if ($a['status'] !== 'available') {
            return ['error' => $a['asset_tag'] . ' is ' . $a['status'] . ' and cannot be checked out.'];
        }
        $personId = !empty($args['person_id']) ? (int) $args['person_id'] : null;
        $deptId = !empty($args['department_id']) ? (int) $args['department_id'] : null;
        if ($personId === null && $deptId === null) {
            return ['error' => 'Choose a person or a department to check out to.'];
        }
        $who = null;
        if ($personId !== null) {
            $person = Person::find($personId);
            if ($person === null || !empty($person['is_terminated'])) {
                return ['error' => 'Person not found or terminated (id ' . $personId . ').'];
            }
            $who = $person['full_name'];
        } else {
            $deptName = Database::fetchColumn('SELECT name FROM departments WHERE id = :id', ['id' => $deptId]);
            if ($deptName === false || $deptName === null) {
                return ['error' => 'Department not found (id ' . $deptId . ').'];
            }
            $who = $deptName;
        }
        $extra = [
            'assigned_to_person_id' => $personId,
            'assigned_to_department_id' => $personId !== null ? null : $deptId,
            'due_date' => (string) ($args['due_date'] ?? ''),
        ];
        if (!$executeMode) {
            return [
                'preview' => 'Check out ' . $a['asset_tag'] . ' to ' . $who . '.',
                'op' => 'check_out_asset',
                'args' => [
                    'asset_id' => $assetId,
                    'person_id' => $personId,
                    'department_id' => $deptId,
                    'due_date' => (string) ($args['due_date'] ?? ''),
                ],
            ];
        }
        try {
            Asset::setStatus($assetId, 'checked_out', $extra, $user);
        } catch (RuntimeException $e) {
            return ['error' => $e->getMessage()];
        }
        return ['ok' => true, 'message' => $a['asset_tag'] . ' checked out to ' . $who . '.'];
    }

    private static function transferAsset(int $assetId, int $personId, array $user, bool $executeMode): array
    {
        if ($deny = self::requireRole($user, ['admin', 'department_manager'], 'transfer assets')) {
            return $deny;
        }
        $a = Asset::find($assetId, $user);
        if ($a === null) {
            return ['error' => 'Asset not found (id ' . $assetId . ').'];
        }
        if ($a['status'] !== 'checked_out') {
            return ['error' => $a['asset_tag'] . ' is not checked out, so it cannot be transferred.'];
        }
        $person = Person::find($personId);
        if ($person === null || !empty($person['is_terminated'])) {
            return ['error' => 'Transfer target person not found or terminated (id ' . $personId . ').'];
        }
        if (!$executeMode) {
            return ['preview' => 'Transfer ' . $a['asset_tag'] . ' to ' . $person['full_name'] . '.', 'op' => 'transfer_asset', 'args' => ['asset_id' => $assetId, 'person_id' => $personId]];
        }
        try {
            Asset::transfer($assetId, $personId, $user);
        } catch (RuntimeException $e) {
            return ['error' => $e->getMessage()];
        }
        return ['ok' => true, 'message' => $a['asset_tag'] . ' transferred to ' . $person['full_name'] . '.'];
    }

    private static function setAssetDepartment(int $assetId, int $deptId, array $user, bool $executeMode): array
    {
        if ($deny = self::requireRole($user, ['admin', 'department_manager'], 'change an asset department')) {
            return $deny;
        }
        $a = Asset::find($assetId, $user);
        if ($a === null) {
            return ['error' => 'Asset not found (id ' . $assetId . ').'];
        }
        $deptName = Database::fetchColumn('SELECT name FROM departments WHERE id = :id', ['id' => $deptId]);
        if ($deptName === false || $deptName === null) {
            return ['error' => 'Department not found (id ' . $deptId . ').'];
        }
        if (!$executeMode) {
            return ['preview' => 'Move ' . $a['asset_tag'] . ' to the ' . $deptName . ' department.', 'op' => 'set_asset_department', 'args' => ['asset_id' => $assetId, 'department_id' => $deptId]];
        }
        try {
            Asset::setDepartment($assetId, $deptId, $user);
        } catch (RuntimeException $e) {
            return ['error' => $e->getMessage()];
        }
        return ['ok' => true, 'message' => $a['asset_tag'] . ' moved to ' . $deptName . '.'];
    }

    private static function createPerson(array $args, array $user, bool $executeMode): array
    {
        if ($deny = self::requireRole($user, ['admin'], 'create a person')) {
            return $deny;
        }
        $name = trim((string) ($args['full_name'] ?? ''));
        if ($name === '') {
            return ['error' => 'full_name is required.'];
        }
        $clean = [
            'full_name' => $name,
            'job_title' => (string) ($args['job_title'] ?? ''),
            'personal_email' => (string) ($args['personal_email'] ?? ''),
            'work_email' => (string) ($args['work_email'] ?? ''),
            'phone' => (string) ($args['phone'] ?? ''),
            'address' => (string) ($args['address'] ?? ''),
            'department_id' => !empty($args['department_id']) ? (int) $args['department_id'] : null,
            'notes' => (string) ($args['notes'] ?? ''),
            'is_terminated' => !empty($args['is_terminated']),
        ];
        if (!empty($clean['department_id'])) {
            $dept = Database::fetchColumn('SELECT name FROM departments WHERE id = :id', ['id' => (int) $clean['department_id']]);
            if ($dept === false || $dept === null) {
                return ['error' => 'Department not found (id ' . (int) $clean['department_id'] . '). Use department_list to see valid departments.'];
            }
        }
        $dup = Database::fetchColumn('SELECT id FROM persons WHERE full_name = :n', ['n' => $name]);
        if ($dup !== false && $dup !== null) {
            return ['error' => 'A person named "' . $name . '" already exists.'];
        }
        if (!$executeMode) {
            return ['preview' => 'Create person "' . $name . '" (' . ($clean['job_title'] !== '' ? $clean['job_title'] : 'no title') . ').', 'op' => 'create_person', 'args' => $clean];
        }
        try {
            $id = Person::create($clean);
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
        return ['ok' => true, 'id' => $id, 'message' => $name . ' created.'];
    }

    private static function updatePerson(int $id, array $args, array $user, bool $executeMode): array
    {
        if ($deny = self::requireRole($user, ['admin'], 'update a person')) {
            return $deny;
        }
        $p = Person::find($id);
        if ($p === null) {
            return ['error' => 'Person not found (id ' . $id . ').'];
        }
        $clean = [
            'full_name' => (string) ($args['full_name'] ?? $p['full_name']),
            'job_title' => (string) ($args['job_title'] ?? $p['job_title']),
            'personal_email' => (string) ($args['personal_email'] ?? $p['personal_email']),
            'work_email' => (string) ($args['work_email'] ?? $p['work_email']),
            'phone' => (string) ($args['phone'] ?? $p['phone']),
            'address' => (string) ($args['address'] ?? $p['address']),
            'department_id' => array_key_exists('department_id', $args)
                ? (!empty($args['department_id']) ? (int) $args['department_id'] : null)
                : (empty($p['department_id']) ? null : (int) $p['department_id']),
            'notes' => (string) ($args['notes'] ?? $p['notes']),
            'is_terminated' => array_key_exists('is_terminated', $args) ? !empty($args['is_terminated']) : !empty($p['is_terminated']),
        ];
        if (trim($clean['full_name']) === '') {
            return ['error' => 'full_name cannot be empty.'];
        }
        if ($clean['department_id'] !== null) {
            $dept = Database::fetchColumn('SELECT name FROM departments WHERE id = :id', ['id' => (int) $clean['department_id']]);
            if ($dept === false || $dept === null) {
                return ['error' => 'Department not found (id ' . (int) $clean['department_id'] . '). Use department_list to see valid departments.'];
            }
        }
        $dup = Database::fetchColumn('SELECT id FROM persons WHERE full_name = :n AND id <> :id', ['n' => $clean['full_name'], 'id' => $id]);
        if ($dup !== false && $dup !== null) {
            return ['error' => 'Another person is already named "' . $clean['full_name'] . '".'];
        }
        if (!$executeMode) {
            $changed = [];
            foreach (['full_name', 'job_title', 'personal_email', 'work_email', 'phone', 'address', 'notes', 'is_terminated'] as $f) {
                if ((string) $clean[$f] !== (string) $p[$f]) {
                    $changed[] = $f;
                }
            }
            if ($changed === []) {
                return ['note' => 'No changes requested for ' . $p['full_name'] . '.'];
            }
            return ['preview' => 'Update ' . $p['full_name'] . ' — change: ' . implode(', ', $changed) . '.', 'op' => 'update_person', 'args' => array_merge(['id' => $id], $clean)];
        }
        try {
            Person::update($id, $clean);
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
        return ['ok' => true, 'message' => $p['full_name'] . ' updated.'];
    }
}
```

- [ ] **Step 3: Create fixtures, test reads + dry-runs (no model needed)**

Run:
```bash
cd /Users/zacheri/Documents/ATR && PGPASSWORD=testpass123 psql -h 127.0.0.1 -U atr -d atr <<'SQL'
INSERT INTO persons (full_name, job_title, work_email, department_id, notes)
VALUES ('Christopher Baldolvsky', 'Field Tech', 'cbald@corp.local', 1, 'fixture');
INSERT INTO assets (asset_tag, brand, model_number, status, assigned_to_person_id, purchase_cost, created_by)
VALUES ('ZZ-9001', 'TestCo', 'TM-1', 'checked_out', (SELECT id FROM persons WHERE full_name='Christopher Baldolvsky'), 100, 1);
SQL
PID=$(PGPASSWORD=testpass123 psql -h 127.0.0.1 -U atr -d atr -tAc "SELECT id FROM persons WHERE full_name='Christopher Baldolvsky'")
cd /Users/zacheri/Documents/ATR && php -r '
require "bin/_bootstrap.php";
use App\Services\Assistant\Tools;
$user = ["id"=>1,"username"=>"admin","role_name"=>"admin","department_id"=>null];
var_export(Tools::execute("find_person", ["query"=>"chris b"], $user, false));
echo "\n---\n";
var_export(Tools::execute("find_asset", ["query"=>"ZZ-9001"], $user, false));
echo "\n---\n";
var_export(Tools::execute("check_in_all_for_person", ["person_id"=>(int)$argv[1]], $user, false));
echo "\n---\n";' "$PID"
```
Expected:
- `find_person`: exactly one match (id = fixture pid, `held => 1`)
- `find_asset`: match with `status => checked_out`, `holder_person => Christopher Baldolvsky`
- `check_in_all_for_person` dry-run: `preview` mentioning `ZZ-9001`, `op => check_in_all_for_person`
- DB untouched: `psql … -tAc "SELECT status FROM assets WHERE asset_tag='ZZ-9001'"` → `checked_out`

- [ ] **Step 4: Test execute mode + RBAC refusal, then clean up**

Run:
```bash
PID=$(PGPASSWORD=testpass123 psql -h 127.0.0.1 -U atr -d atr -tAc "SELECT id FROM persons WHERE full_name='Christopher Baldolvsky'")
cd /Users/zacheri/Documents/ATR && php -r '
require "bin/_bootstrap.php";
use App\Services\Assistant\Tools;
$user = ["id"=>1,"username"=>"admin","role_name"=>"admin","department_id"=>null];
$view = ["id"=>3,"username"=>"viewer","role_name"=>"viewer","department_id"=>null];
var_export(Tools::execute("terminate_person", ["id"=>(int)$argv[1]], $view, false));
echo "\n---\n";
var_export(Tools::execute("check_in_all_for_person", ["person_id"=>(int)$argv[1]], $user, true));
echo "\n---\n";
var_export(Tools::execute("terminate_person", ["id"=>(int)$argv[1]], $user, true));
echo "\n";' "$PID"
PGPASSWORD=testpass123 psql -h 127.0.0.1 -U atr -d atr -tAc "SELECT status FROM assets WHERE asset_tag='ZZ-9001'; SELECT is_terminated FROM persons WHERE full_name='Christopher Baldolvsky'"
PGPASSWORD=testpass123 psql -h 127.0.0.1 -U atr -d atr -c "DELETE FROM assets WHERE asset_tag='ZZ-9001'; DELETE FROM persons WHERE full_name='Christopher Baldolvsky';"
PGPASSWORD=testpass123 psql -h 127.0.0.1 -U atr -d atr -tAc "SELECT count(*) FROM persons WHERE full_name LIKE '%Baldolvsky%'"
```
Expected: viewer dry-run → `error` mentioning permission; execute → `checked_in => 1`; terminate → `ok => true`; then `available | t`; cleanup count `0`.

- [ ] **Step 5: Lint** — `php -l app/Services/Assistant/Tools.php && php -l app/Models/Asset.php` → No syntax errors (both).

---

### Task 5: AssistantController + routes

**Files:**
- Create: `app/Controllers/AssistantController.php`
- Modify: `config/routes.php`

**Interfaces:**
- Consumes: Tasks 1–4 (`LlmServer::*`, `LlmClient::chat(array, array, callable, int, int): ['text','trace']`, `Tools::*`), `App\Core\{Auth,Request,Response,View}`, `App\Models\Audit`.
- Produces (Task 6 UI + Task 9 sweep depend on these exact routes/JSON shapes):
  - `GET /assistant` → 200 HTML
  - `GET /assistant/state` → JSON = `LlmServer::state()`
  - `POST /assistant/chat` body `{_token, message}` → JSON `{'text': string, 'plan': null | [{'tool','args','preview'}], 'trace': array}` or 400 `{'error': string, 'code': string}` (codes: `no_binary`, `no_model`, `model_not_ready`, `llm_error`)
  - `POST /assistant/confirm` body `{_token}` → JSON `{'results': [{'op','preview','result':…}]}` or 400 `{'error': 'No pending plan to confirm.'}`
  - `POST /assistant/cancel` → JSON `{'ok': true}` · `POST /assistant/clear` → JSON `{'ok': true}`
  - Session keys: `llm_history` (array of `{role, content}`, last 12), `assistant_plan` (array); a new chat message unsets any stale plan

- [ ] **Step 1: Add routes** — in `config/routes.php`, after the Preferences block (`/prefs`), insert:

```php
    // AI Assistant
    ['method' => 'GET',  'path' => '/assistant',           'controller' => 'Assistant', 'action' => 'index'],
    ['method' => 'GET',  'path' => '/assistant/state',     'controller' => 'Assistant', 'action' => 'state'],
    ['method' => 'POST', 'path' => '/assistant/chat',      'controller' => 'Assistant', 'action' => 'chat'],
    ['method' => 'POST', 'path' => '/assistant/confirm',   'controller' => 'Assistant', 'action' => 'confirm'],
    ['method' => 'POST', 'path' => '/assistant/cancel',    'controller' => 'Assistant', 'action' => 'cancel'],
    ['method' => 'POST', 'path' => '/assistant/clear',     'controller' => 'Assistant', 'action' => 'clear'],
```
And in the Admin block, after the `/admin/system` line, insert:
```php
    ['method' => 'GET',  'path' => '/admin/llm/state',     'controller' => 'Admin', 'action' => 'llmState',   'roles' => ['admin']],
    ['method' => 'POST', 'path' => '/admin/llm/select',    'controller' => 'Admin', 'action' => 'llmSelect',  'roles' => ['admin']],
    ['method' => 'POST', 'path' => '/admin/llm/start',     'controller' => 'Admin', 'action' => 'llmStart',   'roles' => ['admin']],
    ['method' => 'POST', 'path' => '/admin/llm/stop',      'controller' => 'Admin', 'action' => 'llmStop',    'roles' => ['admin']],
    ['method' => 'POST', 'path' => '/admin/llm/config',    'controller' => 'Admin', 'action' => 'llmConfig',  'roles' => ['admin']],
    ['method' => 'POST', 'path' => '/admin/llm/install',   'controller' => 'Admin', 'action' => 'llmInstall', 'roles' => ['admin']],
```
(The six `llm*` actions land in Task 7 — until then those six routes 500, everything else works.)

- [ ] **Step 2: Create the controller**

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Models\Audit;
use App\Services\Assistant\Tools;
use App\Services\LlmClient;
use App\Services\LlmServer;
use RuntimeException;

class AssistantController
{
    public function index(): void
    {
        Auth::requireLogin();
        View::output(View::render('assistant/index', [
            'title' => 'Assistant',
        ]));
    }

    public function state(): void
    {
        Auth::requireLogin();
        Response::json(LlmServer::state());
    }

    public function chat(): void
    {
        $user = Auth::requireLogin();
        $message = trim((string) Request::post('message', ''));
        if ($message === '') {
            Response::json(['error' => 'Empty message.'], 400);
        }
        unset($_SESSION['assistant_plan']);

        $st = LlmServer::state();
        if ($st['binary'] === null) {
            Response::json(['error' => 'llama.cpp is not installed. Install it from Admin → System (AI Assistant card).', 'code' => 'no_binary']);
        }
        if ($st['selected'] === null) {
            Response::json(['error' => 'No model available. Drop a .gguf file into the models folder (Admin → System).', 'code' => 'no_model']);
        }
        try {
            LlmServer::ensureRunning();
        } catch (RuntimeException $e) {
            Response::json(['error' => $e->getMessage(), 'code' => 'model_not_ready']);
        }

        $history = $_SESSION['llm_history'] ?? [];
        $messages = array_merge(
            [['role' => 'system', 'content' => $this->systemPrompt($user)]],
            $history,
            [['role' => 'user', 'content' => $message]]
        );

        $plan = [];
        $handler = static function (string $name, array $args) use ($user, &$plan): array {
            $result = Tools::execute($name, $args, $user, false);
            if (!is_array($result)) {
                $result = ['message' => (string) $result];
            }
            if (Tools::isAction($name) && isset($result['preview'])) {
                $plan[] = [
                    'tool' => $result['op'],
                    'args' => $result['args'],
                    'preview' => $result['preview'],
                ];
            }
            return $result;
        };

        try {
            $res = LlmClient::chat($messages, Tools::definitions(), $handler);
        } catch (RuntimeException $e) {
            Response::json(['error' => $e->getMessage(), 'code' => 'llm_error']);
        }

        $history[] = ['role' => 'user', 'content' => $message];
        $history[] = ['role' => 'assistant', 'content' => $res['text']];
        $_SESSION['llm_history'] = array_slice($history, -12);
        if ($plan !== []) {
            $_SESSION['assistant_plan'] = $plan;
        }
        Audit::log('assistant.query', 'assistant', (string) time(), [
            'role' => $user['role_name'] ?? '',
            'text' => $message,
            'plan_ops' => array_column($plan, 'tool'),
        ]);
        Response::json(['text' => $res['text'], 'plan' => $plan !== [] ? $plan : null, 'trace' => $res['trace']]);
    }

    public function confirm(): void
    {
        $user = Auth::requireLogin();
        $plan = $_SESSION['assistant_plan'] ?? null;
        if (!is_array($plan) || $plan === []) {
            Response::json(['error' => 'No pending plan to confirm.'], 400);
        }
        unset($_SESSION['assistant_plan']);
        $results = [];
        foreach ($plan as $op) {
            try {
                $r = Tools::execute((string) $op['tool'], (array) $op['args'], $user, true);
            } catch (RuntimeException $e) {
                $r = ['error' => $e->getMessage()];
            }
            if (!is_array($r)) {
                $r = ['message' => (string) $r];
            }
            $results[] = ['op' => $op['tool'], 'preview' => $op['preview'] ?? '', 'result' => $r];
        }
        Audit::log('assistant.execute', 'assistant', (string) time(), ['results' => $results]);
        Response::json(['results' => $results]);
    }

    public function cancel(): void
    {
        Auth::requireLogin();
        unset($_SESSION['assistant_plan']);
        Response::json(['ok' => true]);
    }

    public function clear(): void
    {
        Auth::requireLogin();
        unset($_SESSION['llm_history'], $_SESSION['assistant_plan']);
        Response::json(['ok' => true]);
    }

    private function systemPrompt(array $user): string
    {
        $scope = '';
        if (($user['role_name'] ?? '') === 'department_manager') {
            $scope = ' You are a department manager: you can only see and act on assets in your own department.';
        }
        if (($user['role_name'] ?? '') === 'viewer') {
            $scope = ' You are a viewer: read-only. Never attempt action tools.';
        }
        return 'You are the assistant of ATR Inventory, a local asset-management app (assets have tags like 00001; '
            . 'statuses: available, checked_out, in_repair, broken, lost, disposed, sold, donated). '
            . 'Current user: ' . ($user['full_name'] ?? '') . ' (role: ' . ($user['role_name'] ?? '') . '). '
            . $scope . ' '
            . 'Use the provided tools for every fact you state; never invent asset tags, people, or numbers. '
            . 'Resolve names with find_person and assets with find_asset before acting. '
            . 'If a name is ambiguous, ask which one. Action tools only preview — the user confirms separately. '
            . 'If a tool returns an error, relay it plainly and suggest a fix. Be concise. Quote asset tags in backticks.';
    }
}
```

- [ ] **Step 3: Verify state endpoint + role access (model not required)**

Run:
```bash
B=http://127.0.0.1:8081; J=/tmp/j_ai.txt; rm -f $J
T=$(curl -s -c $J $B/login | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"$//')
curl -s -b $J -c $J -o /dev/null --data-urlencode "username=admin" --data-urlencode "password=Admin1234" --data-urlencode "_token=$T" $B/login
curl -s -b $J $B/assistant/state | head -c 300; echo
J2=/tmp/jv_ai.txt; rm -f $J2
T2=$(curl -s -c $J2 $B/login | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"$//')
curl -s -b $J2 -c $J2 -o /dev/null --data-urlencode "username=viewer" --data-urlencode "password=Viewer1234" --data-urlencode "_token=$T2" $B/login
curl -s -b $J2 -o /dev/null -w "%{http_code}\n" $B/assistant
```
Expected: state JSON contains `status`, `models` (with the reference model), `binary` (a path); viewer `GET /assistant` → `200`.

- [ ] **Step 4: Verify read-only chat end to end (model required — auto-starts)**

Run:
```bash
B=http://127.0.0.1:8081; J=/tmp/j_ai.txt
T=$(curl -s -b $J -c $J $B/assistant | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"$//')
curl -s -b $J -c $J --max-time 180 --data-urlencode "_token=$T" --data-urlencode "message=What is asset 00001? Status and holder please." $B/assistant/chat
```
Expected: JSON `text` mentions `00001` and states its REAL current status (verify against `psql … -tAc "SELECT status FROM assets WHERE asset_tag='00001'"` — the user may have it checked out; the assistant must report the truth); `plan` null; `trace` contains `find_asset`. (First call may take 10–30 s while the model loads.)

- [ ] **Step 5: Verify the plan/confirm cycle (model required; fixture cleaned after)**

Run:
```bash
PGPASSWORD=testpass123 psql -h 127.0.0.1 -U atr -d atr <<'SQL'
INSERT INTO persons (full_name, job_title, work_email, notes)
VALUES ('Tessa Quickreturn', 'Ops', 'tq@corp.local', 'fixture');
INSERT INTO assets (asset_tag, brand, model_number, status, assigned_to_person_id, purchase_cost, created_by)
VALUES ('ZZ-9101', 'TestCo', 'TM-9', 'checked_out', (SELECT id FROM persons WHERE full_name='Tessa Quickreturn'), 50, 1);
SQL
B=http://127.0.0.1:8081; J=/tmp/j_ai.txt
T=$(curl -s -b $J -c $J $B/assistant | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"$//')
curl -s -b $J -c $J --max-time 180 --data-urlencode "_token=$T" \
  --data-urlencode "message=Please check in every asset that is currently checked out to Tessa Quickreturn." $B/assistant/chat
```
Expected: JSON `plan` with one op `check_in_all_for_person` whose `preview` mentions `ZZ-9101`; `text` summarizes it. Nothing executed yet — verify: `psql … -tAc "SELECT status FROM assets WHERE asset_tag='ZZ-9101'"` → `checked_out`.

Then confirm:
```bash
T=$(curl -s -b $J -c $J $B/assistant | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"$//')
curl -s -b $J -c $J --data-urlencode "_token=$T" $B/assistant/confirm
psql -h 127.0.0.1 -U atr -d atr -tAc "SELECT status FROM assets WHERE asset_tag='ZZ-9101'"   # with PGPASSWORD=testpass123 prefix
```
Expected: `results[0].result.checked_in => 1`; asset status `available`.

Cleanup:
```bash
PGPASSWORD=testpass123 psql -h 127.0.0.1 -U atr -d atr -c "DELETE FROM assets WHERE asset_tag='ZZ-9101'; DELETE FROM persons WHERE full_name='Tessa Quickreturn';"
PGPASSWORD=testpass123 psql -h 127.0.0.1 -U atr -d atr -tAc "SELECT count(*) FROM persons WHERE full_name LIKE '%Quickreturn%'"   # expect 0
```

- [ ] **Step 6: Lint** — `php -l app/Controllers/AssistantController.php` → No syntax errors.

---

### Task 6: Assistant UI (template + assistant.js + CSS + nav)

**Files:**
- Create: `templates/assistant/index.php`, `public/theme/js/assistant.js`
- Modify: `public/theme/css/app.css` (append block), `templates/layout.php` (nav item)

**Interfaces:**
- Consumes: Task 5 endpoints (`/assistant/state`, `/assistant/chat`, `/assistant/confirm`, `/assistant/cancel`, `/assistant/clear`); `window.ATR.token` / `window.ATR.base` (already in `<head>`); `asset_url('js/assistant.js')`.
- DOM ids (fixed, used by the JS): `#assistant-state`, `#assistant-messages`, `#assistant-input`, `#assistant-send`, `#assistant-clear`; plan card buttons `#plan-confirm` / `#plan-cancel` (created at runtime).
- localStorage key (fixed): `atr_assistant_history`.

- [ ] **Step 1: Create the page template** `templates/assistant/index.php`

```php
<div class="page-head animate-fadeup">
  <div>
    <h1 class="page-title">Assistant</h1>
    <div class="page-sub">Ask in plain language. Read answers are instant — any action needs your confirmation first.</div>
  </div>
  <div class="page-actions">
    <button type="button" class="btn btn-ghost btn-sm" id="assistant-clear">Clear conversation</button>
  </div>
</div>

<div class="assistant-wrap animate-fadeup">
  <div class="assistant-state" id="assistant-state">Checking model…</div>
  <div class="assistant-messages" id="assistant-messages"></div>
  <div class="assistant-composer">
    <textarea id="assistant-input" rows="1"
              placeholder='Try: "who has 00001?" · "check in everything tessa is holding" · "move 00001 to the IT department"'></textarea>
    <button type="button" class="btn btn-primary" id="assistant-send">Send</button>
  </div>
</div>

<script src="<?= e(asset_url('js/assistant.js')) ?>"></script>
```

- [ ] **Step 2: Create `public/theme/js/assistant.js`** (ES5-style like `app.js`)

```js
/* ATR Assistant — chat behavior */
(function () {
  'use strict';

  var ATR = window.ATR || {};
  var base = ATR.base || '';
  var token = ATR.token || '';

  var stateEl = document.getElementById('assistant-state');
  var messagesEl = document.getElementById('assistant-messages');
  var inputEl = document.getElementById('assistant-input');
  var sendEl = document.getElementById('assistant-send');
  var clearEl = document.getElementById('assistant-clear');
  if (!stateEl || !messagesEl || !inputEl || !sendEl) return;

  var LS_KEY = 'atr_assistant_history';
  var busy = false;

  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
  function post(path, done) {
    var fd = new FormData();
    fd.append('_token', token);
    fetch(base + path, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) {
        return r.json().catch(function () { return {}; }).then(function (j) { return { ok: r.ok, json: j }; });
      })
      .then(done)
      .catch(function () { done({ ok: false, json: { error: 'Network error — is the app server up?' } }); });
  }

  /* ---------- state chip (poll) ---------- */
  function renderState(st) {
    if (!st || !st.binary) {
      stateEl.className = 'assistant-state is-off';
      stateEl.textContent = 'LLM not installed — an admin can install it from System';
      return;
    }
    if (!st.selected) {
      stateEl.className = 'assistant-state is-warn';
      stateEl.textContent = 'No model found — drop a .gguf into the models folder (Admin → System)';
      return;
    }
    var name = st.model || st.selected || '';
    if (st.status === 'ready') {
      if (st.matches_selected) {
        stateEl.className = 'assistant-state is-on';
        stateEl.textContent = 'Model ready — ' + name;
      } else {
        stateEl.className = 'assistant-state is-warn';
        stateEl.textContent = 'Loaded model differs from selection — it restarts on the next reply';
      }
    } else if (st.status === 'loading') {
      stateEl.className = 'assistant-state is-warn';
      stateEl.textContent = 'Loading model (first reply is slower)…';
    } else {
      stateEl.className = 'assistant-state is-off';
      stateEl.textContent = 'Model stopped — ' + name;
    }
  }
  function pollState() {
    fetch(base + '/assistant/state', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(renderState)
      .catch(function () { renderState(null); });
  }
  pollState();
  setInterval(pollState, 4000);

  /* ---------- messages ---------- */
  function loadHistory() {
    try { return JSON.parse(localStorage.getItem(LS_KEY) || '[]') || []; } catch (e) { return []; }
  }
  function saveHistory(list) {
    try { localStorage.setItem(LS_KEY, JSON.stringify(list.slice(-40))); } catch (e) {}
  }
  function mdLite(text) {
    return esc(text).replace(/`([^`]+)`/g, '<code>$1</code>').replace(/\n/g, '<br>');
  }
  function pushBubble(role, html) {
    var row = document.createElement('div');
    row.className = 'msg ' + role;
    var body = document.createElement('div');
    body.className = 'msg-body';
    body.innerHTML = html;
    row.appendChild(body);
    messagesEl.appendChild(row);
    messagesEl.scrollTop = messagesEl.scrollHeight;
    return body;
  }
  function addMsg(role, text) {
    var list = loadHistory();
    list.push({ role: role, text: text });
    saveHistory(list);
    return pushBubble(role, mdLite(text));
  }

  function renderResultRows(results) {
    results.forEach(function (r) {
      var res = r.result || {};
      var err = res.error;
      var msg = err ? String(err) : String(res.message || res.note || 'Done.');
      pushBubble('assistant', (err ? '✗ ' : '✓ ') + esc(msg));
    });
  }

  function renderPlanCard(plan) {
    var card = document.createElement('div');
    card.className = 'assistant-plan';
    var lines = plan.map(function (op) { return esc(op.preview); }).join('<br>');
    card.innerHTML =
      '<div class="assistant-plan-head">Please confirm:</div>' +
      '<div class="assistant-plan-list">' + lines + '</div>' +
      '<div class="assistant-plan-actions">' +
      '<button type="button" class="btn btn-primary btn-sm" id="plan-confirm">Confirm</button>' +
      '<button type="button" class="btn btn-ghost btn-sm" id="plan-cancel">Cancel</button>' +
      '</div>';
    messagesEl.appendChild(card);
    messagesEl.scrollTop = messagesEl.scrollHeight;

    card.querySelector('#plan-confirm').addEventListener('click', function () {
      card.querySelectorAll('button').forEach(function (b) { b.disabled = true; });
      post('/assistant/confirm', function (res) {
        card.remove();
        if (!res.ok || !res.json || !res.json.results) {
          pushBubble('assistant', '⚠ ' + esc((res.json && res.json.error) || 'Confirm failed.'));
          return;
        }
        renderResultRows(res.json.results);
      });
    });
    card.querySelector('#plan-cancel').addEventListener('click', function () {
      post('/assistant/cancel', function () {});
      card.remove();
      pushBubble('assistant', 'Plan cancelled — nothing was changed.');
    });
  }

  /* ---------- composer ---------- */
  function setBusy(b) {
    busy = b;
    sendEl.disabled = b;
  }
  function send() {
    var text = inputEl.value.trim();
    if (!text || busy) return;
    setBusy(true);
    inputEl.value = '';
    addMsg('user', text);
    var typing = pushBubble('assistant', '<span class="msg-typing">Thinking…</span>');

    var fd = new FormData();
    fd.append('_token', token);
    fd.append('message', text);
    fetch(base + '/assistant/chat', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) {
        return r.json().catch(function () { return {}; }).then(function (j) { return { ok: r.ok, json: j }; });
      })
      .then(function (res) {
        if (!res.ok || !res.json || res.json.error) {
          typing.innerHTML = '⚠ ' + esc((res.json && res.json.error) || 'Request failed.');
          setBusy(false);
          return;
        }
        typing.innerHTML = mdLite(res.json.text || '(no reply)');
        if (res.json.plan && res.json.plan.length) renderPlanCard(res.json.plan);
        setBusy(false);
        inputEl.focus();
      })
      .catch(function () {
        typing.innerHTML = '⚠ Network error — could not reach the assistant.';
        setBusy(false);
      });
  }
  sendEl.addEventListener('click', send);
  inputEl.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); }
  });
  if (clearEl) {
    clearEl.addEventListener('click', function () {
      if (busy) return;
      post('/assistant/clear', function (res) {
        messagesEl.innerHTML = '';
        try { localStorage.removeItem(LS_KEY); } catch (e) {}
        if (!res.ok) pushBubble('assistant', '⚠ Could not clear the server-side history.');
        pollState();
      });
    });
  }

  /* ---------- restore ---------- */
  var hist = loadHistory();
  if (hist.length === 0) {
    pushBubble('assistant', 'Hi — ask me about assets and people, e.g. "who has 00001?" or "check in everything tessa is holding". Actions always need your confirmation first.');
  } else {
    hist.forEach(function (m) { pushBubble(m.role || 'assistant', mdLite(m.text)); });
  }
  inputEl.focus();
})();
```

- [ ] **Step 3: Append the CSS block** — at the very end of `public/theme/css/app.css`:

```css
/* ---------- assistant ---------- */
.assistant-wrap { display: flex; flex-direction: column; height: calc(100vh - 200px); min-height: 420px; }
.assistant-state { align-self: flex-start; margin-bottom: 10px; padding: 4px 12px; border-radius: 999px; font-size: 12px; border: 1px solid var(--line); background: var(--surface); color: var(--muted); }
.assistant-state.is-on { color: var(--green); border-color: var(--green-bg); background: var(--green-bg); }
.assistant-state.is-warn { color: var(--amber); border-color: var(--amber-bg); background: var(--amber-bg); }
.assistant-state.is-off { color: var(--red); border-color: var(--red-bg); background: var(--red-bg); }
.assistant-messages {
  flex: 1; overflow-y: auto; padding: 16px; background: var(--bg);
  border: 1px solid var(--line); border-radius: var(--radius);
  display: flex; flex-direction: column; gap: 10px;
}
.msg { max-width: 78%; }
.msg.user { align-self: flex-end; }
.msg.assistant { align-self: flex-start; }
.msg-body { padding: 9px 13px; border-radius: 12px; font-size: 14px; line-height: 1.5; word-break: break-word; }
.msg.user .msg-body { background: var(--primary-2); color: #fff; border-bottom-right-radius: 4px; }
.msg.assistant .msg-body { background: var(--surface); border: 1px solid var(--line); border-bottom-left-radius: 4px; }
.msg-body code { background: rgba(15, 23, 42, .07); padding: 1px 5px; border-radius: 4px; font-size: 13px; }
.msg.user .msg-body code { background: rgba(255, 255, 255, .22); }
.msg-typing { opacity: .55; font-style: italic; }
.assistant-plan { align-self: flex-start; max-width: 78%; background: #fffbeb; border: 1px solid #fcd34d; border-radius: 10px; padding: 12px 14px; font-size: 13.5px; }
.assistant-plan-head { font-weight: 600; margin-bottom: 6px; }
.assistant-plan-list { color: #78350f; line-height: 1.7; margin-bottom: 10px; }
.assistant-plan-actions { display: flex; gap: 8px; }
.assistant-composer { display: flex; gap: 8px; margin-top: 12px; }
.assistant-composer textarea { flex: 1; resize: none; min-height: 44px; max-height: 140px; padding: 10px 12px; font: inherit; border: 1px solid var(--line); border-radius: 10px; background: var(--surface); }
.assistant-composer textarea:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(37, 99, 235, .15); }
@media (max-width: 900px) {
  .assistant-wrap { height: calc(100vh - 160px); }
  .msg, .assistant-plan { max-width: 92%; }
}
```

- [ ] **Step 4: Add the nav item** — in `templates/layout.php`, directly after the Reports link (the `<a href=... /reports>…</a>` block ending at the line before `<?php if (($user['role_name'] ?? '') === 'admin'): ?>`), insert:

```php
      <a href="<?= e(url('/assistant')) ?>" class="<?= str_starts_with($nav_active, 'assistant') ? 'active' : '' ?>">
        <?= $icon('<path d="M12 3a7 7 0 0 0-7 7c0 2.4 1.2 4.4 3 5.7V18a2 2 0 0 0 2 2h4a2 2 0 0 0 2-2v-2.3c1.8-1.3 3-3.3 3-5.7a7 7 0 0 0-7-7z"/><path d="M9.5 10.5h.01M14.5 10.5h.01M9 13.5c.9.8 5.1.8 6 0"/>') ?> Assistant
      </a>
```

- [ ] **Step 5: Verify the page + assets load (model not required)**

Run:
```bash
B=http://127.0.0.1:8081; J=/tmp/j_ai.txt
curl -s -b $J $B/assistant | grep -c "assistant-messages\|assistant.js"
curl -s -b $J -o /dev/null -w "%{http_code}\n" $B/theme/js/assistant.js
curl -s -b $J $B/assistant | grep -o 'href="/assistant"' | head -1
```
Expected: count ≥ 2 (template id + script tag); JS asset `200`; nav link present.

- [ ] **Step 6: Manual browser check** — open http://127.0.0.1:8081/assistant (logged in as any role): nav "Assistant" is active; state chip reflects the model; typing "hi" and pressing Enter shows a user bubble then an assistant reply (or the correct ⚠ error if the model is down); after a reply, a "Please confirm" card appears only for action requests.

---

### Task 7: System tab — AI Assistant card + AdminController `llm*` endpoints

**Files:**
- Modify: `app/Controllers/AdminController.php` (import + 6 methods + `system()` data)
- Modify: `templates/admin/system.php` (AI panel)

**Interfaces:**
- Consumes: `LlmServer::{state, models, modelsDir, selectedModel, setSelectedModel, start, stop, install}` (Task 1), `Setting::set`, `Request::post`, `Auth::flash`, `Response::{redirect,json}`.
- Produces: routes from Task 5 Step 1 (`/admin/llm/state|select|start|stop|config|install`, all admin-only). JSON: `state` → `LlmServer::state()`; `select` → `{ok:true}` or 400 `{error}`; `start`/`stop`/`config`/`install` → `{ok:true, note?: string, message?: string, output?: string}` or 4xx/5xx `{error}`.

- [ ] **Step 1: Add import + methods** — in `app/Controllers/AdminController.php`, add `use App\Services\LlmServer;` after `use App\Services\Backup;`. Then insert the six methods right after `system()` (before `private function dirSize`):

```php
    public function llmState(): void
    {
        Auth::requireLogin();
        Response::json(LlmServer::state());
    }

    public function llmSelect(): void
    {
        Auth::requireLogin();
        $file = basename((string) Request::post('model', ''));
        if ($file === '' || !is_file(LlmServer::modelsDir() . '/' . $file)) {
            Response::json(['error' => 'Model file not found.'], 400);
        }
        LlmServer::setSelectedModel($file);
        Response::json(['ok' => true]);
    }

    public function llmStart(): void
    {
        Auth::requireLogin();
        try {
            LlmServer::start();
        } catch (RuntimeException $e) {
            Response::json(['error' => $e->getMessage()], 500);
        }
        Response::json(['ok' => true, 'note' => 'Model is loading. Replies may be slower until it is ready.']);
    }

    public function llmStop(): void
    {
        Auth::requireLogin();
        LlmServer::stop();
        Response::json(['ok' => true]);
    }

    public function llmConfig(): void
    {
        Auth::requireLogin();
        $port = (int) Request::post('port', 0);
        $context = (int) Request::post('context', 0);
        if ($port < 1024 || $port > 65535) {
            Response::json(['error' => 'Port must be between 1024 and 65535.'], 400);
        }
        if ($context < 2048 || $context > 32768) {
            Response::json(['error' => 'Context must be between 2048 and 32768.'], 400);
        }
        Setting::set('llm.port', (string) $port);
        Setting::set('llm.context', (string) $context);
        LlmServer::stop();
        Response::json(['ok' => true, 'note' => 'Saved. The model server restarts with the new settings on next use.']);
    }

    public function llmInstall(): void
    {
        Auth::requireLogin();
        $res = LlmServer::install();
        if (!$res['ok']) {
            Response::json(['error' => 'llama.cpp install failed. Try: brew install llama.cpp', 'output' => substr((string) $res['output'], 0, 2000)], 500);
        }
        Response::json(['ok' => true, 'message' => 'llama.cpp installed.', 'output' => substr((string) $res['output'], 0, 2000)]);
    }
```

- [ ] **Step 2: Pass LLM data to the System view** — in `system()`, add to the `View::render` data array (after the `'storage' => [...]` entry):

```php
            'llm' => [
                'state' => LlmServer::state(),
                'port' => LlmServer::port(),
                'context' => LlmServer::context(),
                'models_dir' => LlmServer::modelsDir(),
            ],
```

- [ ] **Step 3: Add the AI Assistant panel** — in `templates/admin/system.php`, after the "Background services" section, append:

```php
<section class="panel animate-fadeup" style="margin-top:16px;animation-delay:.2s">
  <div class="panel-head"><h2>AI Assistant <span class="pill" id="llm-pill">…</span></div>
  <div class="panel-body">
    <p class="table-note">Local natural-language access to the inventory, powered by a GGUF model run on <code>llama-server</code> (127.0.0.1:<?= (int) $llm['port'] ?>). Nothing leaves this machine.</p>
    <div class="detail-grid">
      <div><span class="dk">llama.cpp binary</span><span class="dv" id="llm-binary"><?= e($llm['state']['binary'] ?? '') !== '' ? e($llm['state']['binary']) : 'not installed' ?></span></div>
      <div><span class="dk">Models folder</span><span class="dv"><code><?= e($llm['models_dir']) ?></code></span></div>
      <div><span class="dk">Context length</span><span class="dv" id="llm-context"><?= (int) $llm['context'] ?></span></div>
      <div><span class="dk">Selected model</span><span class="dv" id="llm-selected"><?= e($llm['state']['selected'] ?? '') !== '' ? e($llm['state']['selected']) : '—' ?></span></div>
    </div>

    <div class="form-grid" style="margin-top:12px">
      <label class="field"><span>Model</span>
        <select id="llm-model">
          <?php foreach ($llm['state']['models'] as $m): ?>
            <option value="<?= e($m['name']) ?>" <?= ($m['name'] === ($llm['state']['selected'] ?? '')) ? 'selected' : '' ?>><?= e($m['name']) ?> (<?= e(round($m['size'] / 1048576)) ?> MB)</option>
          <?php endforeach; ?>
          <?php if (($llm['state']['models'] ?? []) === []): ?><option value="">No .gguf files found</option><?php endif; ?>
        </select>
      </label>
      <label class="field"><span>Port</span><input type="number" id="llm-port" value="<?= (int) $llm['port'] ?>" min="1024" max="65535"></label>
      <label class="field"><span>Context</span><input type="number" id="llm-context-input" value="<?= (int) $llm['context'] ?>" min="2048" max="32768" step="1024"></label>
    </div>

    <div class="page-actions" style="margin-top:12px">
      <?php if (($llm['state']['binary'] ?? null) === null): ?>
        <button type="button" class="btn btn-primary" id="llm-install">Install llama.cpp (brew)</button>
      <?php endif; ?>
      <button type="button" class="btn" id="llm-select">Select model</button>
      <button type="button" class="btn" id="llm-config">Save settings</button>
      <button type="button" class="btn btn-primary" id="llm-start">Start</button>
      <button type="button" class="btn btn-ghost" id="llm-stop">Stop</button>
    </div>
    <div class="table-note" id="llm-msg" style="margin-top:10px"></div>
  </div>
</section>

<script>
(function () {
  'use strict';
  var ATR = window.ATR || {};
  var base = ATR.base || '';
  var token = ATR.token || '';
  var $ = function (id) { return document.getElementById(id); };
  var pill = $('llm-pill');
  var msg = $('llm-msg');

  function post(path, data, done) {
    var fd = new FormData();
    fd.append('_token', token);
    Object.keys(data || {}).forEach(function (k) { if (data[k] !== null && data[k] !== undefined) fd.append(k, data[k]); });
    fetch(base + path, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { return { ok: r.ok, json: j }; }); })
      .then(done)
      .catch(function () { done({ ok: false, json: { error: 'Network error.' } }); });
  }
  function say(t, bad) {
    msg.textContent = t;
    msg.style.color = bad ? 'var(--red)' : 'var(--muted)';
  }
  function refresh() {
    fetch(base + '/admin/llm/state', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (s) {
        var sel = $('llm-selected');
        if (sel) sel.textContent = s.selected || '—';
        var bin = $('llm-binary');
        if (bin) bin.textContent = s.binary || 'not installed';
        if (pill) {
          pill.textContent = s.status;
          pill.className = 'pill ' + (s.status === 'ready' && s.matches_selected ? 'pill-green' : s.status === 'loading' ? 'pill-amber' : 'pill-gray');
        }
      })
      .catch(function () {});
  }
  Array.prototype.forEach.call(document.querySelectorAll('#llm-select,#llm-config,#llm-start,#llm-stop,#llm-install'), function (btn) {
    btn.addEventListener('click', function () {
      btn.disabled = true;
      say('Working…');
      if (btn.id === 'llm-select') {
        post('/admin/llm/select', { model: $('llm-model').value }, function (r) { say(r.json && r.json.error ? r.json.error : 'Model selected.', !r.ok); refresh(); });
      } else if (btn.id === 'llm-config') {
        post('/admin/llm/config', { port: $('llm-port').value, context: $('llm-context-input').value }, function (r) { say(r.json && (r.json.error || r.json.note), !r.ok); });
      } else if (btn.id === 'llm-start') {
        post('/admin/llm/start', {}, function (r) { say(r.json && (r.json.error || r.json.note), !r.ok); refresh(); });
      } else if (btn.id === 'llm-stop') {
        post('/admin/llm/stop', {}, function (r) { say(r.json && r.json.error ? r.json.error : 'Stopped.', !r.ok); refresh(); });
      } else if (btn.id === 'llm-install') {
        say('Installing llama.cpp via brew — this can take a few minutes…');
        post('/admin/llm/install', {}, function (r) { say(r.json && (r.json.message || r.json.error), !r.ok); refresh(); });
      }
      btn.disabled = false;
    });
  });
  refresh();
  setInterval(refresh, 5000);
})();
</script>
```

And append this to `public/theme/css/app.css` (right after the assistant block from Task 6):

```css
/* ---------- llm status pill ---------- */
.pill { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: 11.5px; font-weight: 600; vertical-align: middle; margin-left: 8px; }
.pill-gray { background: var(--slate-bg); color: var(--ink-2); }
.pill-amber { background: var(--amber-bg); color: var(--amber); }
.pill-green { background: var(--green-bg); color: var(--green); }
```

- [ ] **Step 4: Verify endpoints (RBAC + JSON)**

Run:
```bash
B=http://127.0.0.1:8081; J=/tmp/j_ai.txt; JV=/tmp/jv_ai.txt
T=$(curl -s -b $J -c $J $B/admin/system | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"$//')
curl -s -b $J -c $J $B/admin/llm/state | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["status"],"|",$d["binary"]?($d["binary"]?"bin":"n/bin"),"|",count($d["models"]),"\n";'
# viewer must be denied:
TV=$(curl -s -b $JV -c $JV $B/admin/llm/state -o /dev/null -w "%{http_code}"); echo "viewer state: $TV"
curl -s -b $J -c $J --data-urlencode "_token=$T" --data-urlencode "model=does-not-exist.gguf" $B/admin/llm/select; echo
curl -s -b $J -c $J --data-urlencode "_token=$T" --data-urlencode "port=99" --data-urlencode "context=4096" $B/admin/llm/config; echo
```
Expected: state line like `stopped|n/bin|1` (1 = the reference model, if downloaded in Task 2); viewer `403`; select → `{"error":"Model file not found."}`; config → `{"error":"Port must be between 1024 and 65535."}`.
(If the viewer jar has no session, log in first as in Task 5 Step 3 — the point is a non-admin gets no JSON body from `/admin/llm/state`.)

- [ ] **Step 5: Verify start/stop from the UI endpoints (model required)**

Run:
```bash
T=$(curl -s -b $J -c $J $B/admin/system | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"$//')
curl -s -b $J -c $J --data-urlencode "_token=$T" $B/admin/llm/start; echo
sleep 5; curl -s -b $J -c $J $B/admin/llm/state | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["status"],"\n";'
curl -s -b $J -c $J --data-urlencode "_token=$T" $B/admin/llm/stop; echo
curl -s -b $J -c $J $B/admin/llm/state | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["status"],"\n";'
```
Expected: start → `{"ok":true,…}`; status transitions `loading` → `ready` (first start loads the model; allow up to ~60 s — poll if needed); stop → `stopped`.

- [ ] **Step 6: Lint** — `php -l app/Controllers/AdminController.php && php -l templates/admin/system.php` → No syntax errors (both).

---

### Task 8: Installer (llama.cpp + storage dirs) + docs

**Files:**
- Modify: `install/install.sh`, `README.md`, `docs/ARCHITECTURE.md`

**Interfaces:** none new; must not break existing installs (llama.cpp is optional for core app — the assistant degrades gracefully without it).

- [ ] **Step 1: Installer changes** — in `install/install.sh`:
  1. The llama.cpp formula line was already added in Task 2 — verify it exists after `ensure_formula composer composer` (add it only if missing, e.g. in a re-run scenario):
  ```bash
  ensure_formula llama.cpp llama-server
  ```
  2. In the storage loop `for d in uploads sessions backups logs reports labels; do`, change to:
  ```bash
  for d in uploads sessions backups logs reports labels run models; do
  ```

- [ ] **Step 2: README** — in `README.md`, in the feature list (next to the other capability bullets), add one bullet:
  ```
  - AI Assistant — plain-language chat (Admin sidebar) over a local GGUF model (llama.cpp); read answers + confirmed actions (check-in/out, transfer, terminate, …)
  ```
  and in the "Requirements" section add: `llama.cpp (optional, for the AI Assistant — installed by the installer)`.
  (Match the README's existing bullet/section style — read the file first and place accordingly.)

  In `docs/INSTALL.md`, in the dependencies section, add one line: `llama.cpp (optional — only for the AI Assistant; the installer installs it, and the app degrades gracefully without it).`

- [ ] **Step 3: ARCHITECTURE.md** — append a section at the end:
  ```markdown
  ## AI Assistant (local LLM)

  - `app/Services/LlmServer.php` — app-managed `llama-server` on 127.0.0.1 (port from `llm.port`, default 8082). Models = `.gguf` files in `storage/models` (override `llm.models_dir`); selection = `llm.selected_model`. PID in `storage/run/llama.pid`, log in `storage/logs/llama.log`. Never reachable from outside localhost.
  - `app/Services/LlmClient.php` — OpenAI-compatible `/v1/chat/completions` loop (tools, ≤8 rounds, 120 s budget) → `{text, trace}`.
  - `app/Services/Assistant/Tools.php` — the ONLY path from the model to the DB. Read tools return rows (scoped via `Auth::scopeWhere`); action tools run in two modes: dry-run (returns `{preview, op, args}`) and execute (calls the normal model layer). RBAC is enforced per-tool with `requireRole()` — never trust the model.
  - `app/Controllers/AssistantController.php` — `/assistant*` routes. Session: `llm_history` (last 12 messages) and `assistant_plan` (pending ops). Audit: `assistant.query` (every prompt) and `assistant.execute` (every confirm).
  - Two-phase safety: a model can only *propose*; the UI renders a confirm card; `POST /assistant/confirm` executes the stored plan through `Tools::execute(..., executeMode: true)` and audits each result.
  - Settings keys (all in `settings`, no migrations): `llm.models_dir`, `llm.port`, `llm.context`, `llm.selected_model`. Admin card: Admin → System → "AI Assistant".
  ```

- [ ] **Step 4: Verify installer edit syntax** — `bash -n install/install.sh` → no output (OK). Do NOT run the installer.

---

### Task 9: Extend the web sweep + final end-to-end verification

**Files:**
- Modify: `/var/folders/69/3nhlrb8s7m708ngjx1m9l9xm0000gr/T/opencode/web_sweep.sh` (append checks before the final `echo "RESULT…"`)

**Interfaces:** reuses the sweep's existing helpers (`check`, `tok`, `$PSQL`, `$B`, `$JAR`). No app changes.

- [ ] **Step 1: Append the assistant checks to the sweep** — in `web_sweep.sh`, insert immediately before the final `echo` block (the `# --- RBAC: viewer denied persons/backups…` section is the last feature block; place the new block just above the `# --- cleanup test persons ---` section, after the manager `check 403` line):

```bash
# --- AI assistant: pages + LLM RBAC (no model required) ---
for p in "/assistant" "/theme/js/assistant.js"; do
    code=$(curl -s -b "$JAR" -o /dev/null -w "%{http_code}" --max-time 20 "$B$p")
    check 200 "GET $p" "$code"
done
code=$(curl -s -b "$JAR" -o /dev/null -w "%{http_code}" --max-time 20 "$B/admin/llm/state")
check 200 "admin /admin/llm/state" "$code"
# viewer: can open /assistant but is denied /admin/llm/*
code=$(curl -s -b /tmp/jv.txt -o /dev/null -w "%{http_code}" "$B/assistant")
check 200 "viewer /assistant allowed" "$code"
code=$(curl -s -b /tmp/jv.txt -o /dev/null -w "%{http_code}" "$B/admin/llm/state")
check 403 "viewer denied /admin/llm/state" "$code"
# manager: can open /assistant but is denied /admin/llm/*
code=$(curl -s -b /tmp/jm.txt -o /dev/null -w "%{http_code}" "$B/assistant")
check 200 "manager /assistant allowed" "$code"
code=$(curl -s -b /tmp/jm.txt -o /dev/null -w "%{http_code}" "$B/admin/llm/state")
check 403 "manager denied /admin/llm/state" "$code"

# --- AI assistant end-to-end (model required) ---
# Only run when a model is actually loaded/ready; otherwise skip gracefully.
LLM_STATE=$(curl -s -b "$JAR" --max-time 5 "$B/assistant/state")
LLM_READY=$(echo "$LLM_STATE" | grep -o '"status":"[a-z]*"' | head -1 | sed 's/.*"\([a-z]*\)".*/\1/')
if [ "$LLM_READY" = "ready" ]; then
    # create a fixture person + a checked-out asset
    T=$(tok "$B/admin/persons/new")
    curl -s -b "$JAR" -o /dev/null --data-urlencode "_token=$T" \
      --data-urlencode "person[full_name]=AI Sweep Person" \
      --data-urlencode "person[job_title]=QA" \
      --data-urlencode "person[work_email]=aisweep@example.com" \
      "$B/admin/persons" >/dev/null
    AIQ=$($PSQL "SELECT id FROM persons WHERE full_name='AI Sweep Person'")
    T=$(tok "$B/assets/new")
    AITAG="AIQ-$(date +%s)"
    curl -s -b "$JAR" -o /dev/null --data-urlencode "_token=$T" \
      --data-urlencode "asset[asset_tag]=$AITAG" \
      --data-urlencode "asset[brand]=Sweep" \
      --data-urlencode "asset[model_number]=SW-1" \
      --data-urlencode "asset[purchase_cost]=10" \
      "$B/assets" >/dev/null
    AID=$($PSQL "SELECT id FROM assets WHERE asset_tag='$AITAG'")
    # check it out to the AI sweep person
    T=$(tok "$B/assets/$AID")
    curl -s -b "$JAR" -o /dev/null --data-urlencode "_token=$T" \
      --data-urlencode "assigned_to_person_id=$AIQ" \
      --data-urlencode "due_date=2026-12-31" \
      "$B/assets/$AID/check-out" >/dev/null
    S0=$($PSQL "SELECT status FROM assets WHERE id=$AID")
    check checked_out "ai fixture checked out (pre)" "$S0"

    # ask the assistant a read question — should surface the tag/holder
    T=$(tok "$B/assistant")
    Q=$(curl -s -b "$JAR" -c "$JAR" --max-time 180 --data-urlencode "_token=$T" \
      --data-urlencode "message=Which asset is checked out to AI Sweep Person? Give me the tag." "$B/assistant/chat")
    echo "$Q" | grep -q "$AITAG" && check yes "LLM read query returns fixture tag" yes || check yes "LLM read query returns fixture tag" no

    # ask for a check-in — should return a PLAN (not execute)
    T=$(tok "$B/assistant")
    Q=$(curl -s -b "$JAR" -c "$JAR" --max-time 180 --data-urlencode "_token=$T" \
      --data-urlencode "message=Check in that asset for AI Sweep Person." "$B/assistant/chat")
    echo "$Q" | grep -Eq "check_in_asset|check_in_all_for_person" && check yes "LLM returns check_in plan" yes || check yes "LLM returns check_in plan" no
    S1=$($PSQL "SELECT status FROM assets WHERE id=$AID")
    check checked_out "NOT executed before confirm" "$S1"

    # confirm — should execute
    T=$(tok "$B/assistant")
    R=$(curl -s -b "$JAR" -c "$JAR" --max-time 60 --data-urlencode "_token=$T" "$B/assistant/confirm")
    S2=$($PSQL "SELECT status FROM assets WHERE id=$AID")
    check available "executed after confirm" "$S2"

    # audit rows exist
    AUD=$($PSQL "SELECT count(*) FROM audit_log WHERE action IN ('assistant.query','assistant.execute')")
    [ "$AUD" -ge 1 ] && check yes "assistant audit rows written" yes || check yes "assistant audit rows written" no

    # cleanup LLM fixtures
    if [ -n "$AID" ]; then
        T=$(tok "$B/assets/$AID")
        curl -s -b "$JAR" -o /dev/null --data-urlencode "_token=$T" "$B/assets/$AID/delete" >/dev/null
    fi
    if [ -n "$AIQ" ]; then
        T=$(tok "$B/admin/persons")
        curl -s -b "$JAR" -o /dev/null --data-urlencode "_token=$T" "$B/admin/persons/$AIQ/delete" >/dev/null
    fi
    REM=$($PSQL "SELECT count(*) FROM persons WHERE full_name='AI Sweep Person'")
    check 0 "AI sweep fixtures cleaned up" "$REM"
else
    echo "SKIP  LLM end-to-end (model not ready: status=$LLM_READY)"
fi
```

- [ ] **Step 2: Run the sweep without a model loaded** (stop the server first so this exercises the non-LLM path)

Run:
```bash
cd /Users/zacheri/Documents/ATR && php -r 'require "bin/_bootstrap.php"; App\Services\LlmServer::stop();'
bash /var/folders/69/3nhlrb8s7m708ngjx1m9l9xm0000gr/T/opencode/web_sweep.sh 2>&1 | tail -20
```
Expected: the 7 new assistant/RBAC lines PASS, the LLM block prints `SKIP  LLM end-to-end`, and the final line is `RESULT: 64 passed, 0 failed` (was 56; +7 assistant/RBAC checks +1 checkout-fixture cleanup check). Zero `FAIL` lines anywhere. (Also amend the sweep's pre-existing checkout/transfer/check-in section to run on a dedicated fixture asset `SW-CHKOUT` — created via the UI at the section start, deleted in the final cleanup with its own `check 0` line — instead of asset id 1, which is the user's real asset; QR/sheet checks stay on /assets/1 since they are read-only.)

- [ ] **Step 3: Run the sweep with the model loaded**

Run:
```bash
bash /var/folders/69/3nhlrb8s7m708ngjx1m9l9xm0000gr/T/opencode/web_sweep.sh 2>&1 | tail -30
```
(First run after a stop will auto-start the model on the first `ensureRunning()` inside `/assistant/chat`; allow ~60–90 s for load. If it times out, start it explicitly first with the Task 7 start endpoint, then re-run.)
Expected: the LLM end-to-end block runs — `ai fixture checked out (pre)`, `LLM read query returns fixture tag`, `LLM returns check_in plan` (accepts `check_in_asset` OR `check_in_all_for_person` — both are valid tool choices for a 4B model), `NOT executed before confirm`, `executed after confirm`, `assistant audit rows written`, `AI sweep fixtures cleaned up` all PASS; final line `RESULT: 71 passed, 0 failed` (56 original + 7 assistant pages/RBAC + 1 checkout-fixture cleanup + 7 LLM end-to-end). Zero `FAIL` lines. (4B-model nondeterminism: if an LLM check fails while all non-LLM checks pass, re-run the sweep — up to 3 runs total; paste the raw JSON for any failure.)

- [ ] **Step 4: Confirm live DB is back to user-data-only**

Run:
```bash
export PGPASSWORD=testpass123
psql -h 127.0.0.1 -U atr -d atr -tAc "SELECT asset_tag,status FROM assets ORDER BY asset_tag; SELECT count(*) FROM persons; SELECT count(*) FROM photos"
```
Expected: the user's asset `00001` is present (status = whatever the user's real checkout state is — do NOT assert a specific status and never modify it); persons count reflects only real user data (no `AI Sweep Person`/`Smoke Person`/`Tessa Quickreturn`/`Christopher Baldolvsky`/`Fix Person One` rows remain); photos count unchanged from before this work. No leftover `ZZ-*`, `AIQ-*`, or other fixture rows.

- [ ] **Step 5: Final lint across all new/changed PHP**

Run:
```bash
cd /Users/zacheri/Documents/ATR
for f in app/Services/LlmServer.php app/Services/LlmClient.php app/Services/Assistant/Tools.php app/Controllers/AssistantController.php app/Controllers/AdminController.php app/Models/Asset.php templates/assistant/index.php templates/admin/system.php templates/layout.php; do php -l "$f"; done
```
Expected: `No syntax errors detected` for every file.
