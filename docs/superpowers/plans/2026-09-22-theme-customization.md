# Plan: Theme customization (global default + per-user override)

Approved design (brainstormed with the user, 2026-09-22). Bounded feature: the CSS
token palette, Admin → Settings page, and layout all already exist. No spec file —
this plan IS the approved design.

## Global Constraints

- Vanilla PHP 8.4 + PostgreSQL, no frameworks, no JS build step. PSR-4 `app/`, SQL in
  models, thin controllers, `e()` escaping, `url()` links, CSRF on every POST,
  vanilla JS IIFEs.
- All schema changes idempotent (`IF NOT EXISTS` / guarded ALTER) in `db/schema.sql` —
  the entrypoint applies it on every boot with `ON_ERROR_STOP=1`.
- Every color in the UI comes from the 28 `:root` tokens in `public/theme/css/app.css`
  (lines 2–31). Theme switching works by overriding tokens via an inline `<style>` in
  the `<head>` that loads AFTER app.css (same specificity, later wins). No new CSS
  file, no cache-busting.
- Settings persist in the `settings` table via `Setting::set` / `Setting::setMany`
  (string values). Per-user state persists in the `users` table.
- No automated test suite. Verification bar: `php -l` on changed files +
  `node --check` on changed JS + `docker compose up --build` + live curl flows.
- `e()` everywhere user/theme data is rendered; theme values are validated server-side
  before storage (preset whitelist + `#rrggbb`), so rendered values are trusted — but
  still escape.

## Design

### 1. Presets — `app/Themes.php` (new)

Final class `Themes` with:

- `presets(): array` — map of preset name → token overrides. Four presets:
  - `default` — the current blue palette (empty override map; app.css `:root` is the
    source of truth, so `default` can be `[]`).
  - `forest` — green primary family: `--primary`, `--primary-2`, `--accent` (and
    `--green`/`--green-bg` may stay; pick a coherent green palette).
  - `violet` — purple primary family: `--primary`, `--primary-2`, `--accent`.
  - `dark` — dark mode: `--bg`, `--surface`, `--ink`, `--ink-2`, `--muted`, `--line`,
    `--slate-bg`, `--sidebar`, `--sidebar-2`, `--shadow-*` (lighter alpha), plus
    `--green-bg`/`--amber-bg`/`--red-bg`/`--purple-bg`/`--teal-bg`/`--pink-bg`
    (darker tints so badges stay readable). Implementer must grep app.css + templates
    for hardcoded hex/rgba colors outside the tokens and fix any that break dark mode
    (move them to tokens or adjust the preset).
- `OVERRIDABLE = ['--primary', '--accent', '--bg', '--sidebar']` — the only tokens a
  custom color may set.
- `resolve(?array $userTheme, string $defaultPreset): array` — returns the final
  token→color map for rendering: start from the preset's overrides, layer the user's
  custom colors on top (validated subset of OVERRIDABLE). Invalid/unknown preset names
  fall back to `default`.
- `normalizeUserTheme(string $raw): ?array` — parse `users.theme` JSON
  (`{"preset":"forest","colors":{"--primary":"#0d9488"}}`); `''`/invalid JSON → null
  (follow company default). Validates preset name + each color `#rrggbb` + each token
  in OVERRIDABLE; drops invalid entries.
- `normalizeDefault(string $raw): string` — preset name or `default`.
- `normalizeAvailable(string $raw): array` — comma list of preset names; empty → all
  presets.

Preset color values: implementer picks coherent values (WCAG-reasonable contrast for
`--primary` on white and for `--ink` on `--bg` in dark). Document chosen values in a
comment in Themes.php.

### 2. Storage

- `db/schema.sql`: `ALTER TABLE users ADD COLUMN IF NOT EXISTS theme text NOT NULL DEFAULT '';`
  (in the existing migration block style, after the photos/portraits ALTERs).
- `settings.theme.default` — preset name (default `default`).
- `settings.theme.available` — comma list of preset names available to users (default
  `''` = all).
- `users.theme` — `''` = follow company default; else JSON `{"preset":..., "colors":{...}}`.

### 3. Models / controllers

- `app/Models/User.php`: persist `theme` on a new `User::setTheme(int $id, string $raw)`
  (validates via `Themes::normalizeUserTheme` round-trip: only store what parses;
  `''` clears). Add `theme` to `find()`/`all()` select if they enumerate columns
  (check how User.php selects).
- `app/Controllers/AccountController.php` (new): single action `theme()` —
  `POST /account/theme` (any logged-in role). Body: `preset` (string, optional) +
  `colors` (JSON string or individual `color_<token>` fields — implementer picks the
  simpler one; document it). `preset=default` + no colors, or explicit
  `follow_default=1`, stores `''`. Responds with a redirect back (same pattern as
  other POST endpoints: `Response::back()` or redirect to referer — check how the
  app does it; simplest is redirect to `/`).
- `app/Controllers/AdminController.php`:
  - `settings()` — pass `themeDefault`, `themeAvailable`, `presets` (name + a
    representative swatch color, e.g. its `--primary` or `--bg`) to the view.
  - `settingsUpdate()` — persist `settings.theme.default` (whitelisted) and
    `settings.theme.available` (whitelisted names, comma-joined).
- `config/routes.php`: `['method' => 'POST', 'path' => '/account/theme', 'controller' => 'Account', 'action' => 'theme']`
  (no role restriction — any logged-in user; check how routes express "logged in" —
  look at existing routes without a `roles` key).

### 4. Rendering

- `app/Core/View.php` (layout scope, where `logo_photo` is computed): compute
  `$theme = Themes::resolve(User::current theme, Setting theme.default)` and pass
  `theme_css` (the rendered `--token: value;` lines, or the map) to the layout.
- `templates/layout.php` `<head>`: after the app.css `<link>`, emit
  `<style>:root{ ... }</style>` when the resolved map is non-empty.
- `app/Controllers/AuthController.php` `login()`: pass the same `theme_css` (global
  default only — no user) and `templates/auth/login.php` emits the same `<style>`
  block (check login.php's head structure; it links app.css).

### 5. UI

- **Topbar theme dropdown** (all users, `templates/layout.php`): a palette-icon button
  next to the bell (reuse `.dropdown` / `.dropdown-toggle` / `.dropdown-menu`
  patterns), menu contains:
  - "Follow company default" option (stores `''`)
  - One row per AVAILABLE preset (from `settings.theme.available`; the user's current
    choice highlighted) — each row shows a small swatch (preset's primary color) + name
  - "Custom colors" area: 4 `<input type="color">` for `--primary`, `--accent`,
    `--bg`, `--sidebar` (prefilled with current resolved values) + a Save button
  - Preset rows are a form POST to `/account/theme` (radio `preset` + hidden
    `follow_default` when "follow" is picked) — same-page forms or JS-driven;
    implementer picks the simplest robust option consistent with existing dropdown
    patterns (the alerts dropdown is a plain div; the action modal in app.js exists —
    a small form per row is fine and needs no JS).
  - Custom colors: one small form with the 4 color inputs + hidden current preset +
    submit → `/account/theme`.
  - After save, redirect back; the new `<style>` renders on the returned page.
- **Admin → Settings → "Theme" section** (`templates/admin/settings.php`, below
  Branding): default-theme `<select>` (name="settings[theme_default]") + available
  presets as checkboxes (name="settings[theme_available][]" — note: settingsUpdate
  reads `Request::post('settings')`; checkbox lists arrive as arrays; handle in
  settingsUpdate).
- `public/theme/css/app.css`: styles for `.theme-menu` (swatch dots, rows, color
  input sizing).
- `public/theme/js/app.js`: only if the dropdown needs the existing
  `.dropdown-toggle` behavior (it already exists — reuse; likely zero new JS).

### 6. Validation summary

- Preset names: whitelist from `Themes::presets()` keys.
- Custom colors: `^#[0-9a-fA-F]{6}$`; tokens restricted to OVERRIDABLE.
- `settings.theme.available`: filter to known presets.
- All stored values pass through `Themes::normalize*` on the way in AND on the way
  out (resolve) — a tampered DB value degrades to `default`, never errors.

## Task 1 — Implement the theme system

All of the above, one cohesive change. Files:

- `db/schema.sql` (users.theme ALTER)
- `app/Themes.php` (new)
- `app/Models/User.php`
- `app/Controllers/AccountController.php` (new)
- `app/Controllers/AdminController.php`
- `app/Controllers/AuthController.php`
- `app/Core/View.php`
- `config/routes.php`
- `templates/layout.php`
- `templates/auth/login.php`
- `templates/admin/settings.php`
- `public/theme/css/app.css`
- `public/theme/js/app.js` (only if needed)

Verification: `php -l` all changed PHP; `node --check` if JS changed;
`docker compose up --build` in the worktree is NOT possible (single port) — the
controller rebuilds on main after merge. In-worktree checks: `php -l`, and a quick
CLI smoke: `php -r` requiring the autoloader + calling `Themes::resolve` /
`normalizeUserTheme` with valid + tampered inputs.
