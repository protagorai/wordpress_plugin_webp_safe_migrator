# WebP Safe Migrator — Strengthened Code Review & Improvement Plan

**Reviewed:** 2026-06-27
**Plugin version:** 0.2.0
**Reviewer scope:** Full codebase — plugin runtime, admin UX, batch/queue model, logging, error tracking, security, **container-based integration testing**, and operational tooling (`setup/`, `bin/`, `docs/`).

> This document supersedes the first-pass review. It folds in deeper findings from the
> operational tooling (Docker/Podman, install scripts, config system) and adds concrete,
> implementable designs for the three priorities you called out:
> 1. **A real admin control panel** that governs how the plugin behaves, surfaced at install time.
> 2. **Resumable, selectively-scoped batch processing** (stop, walk away, come back, continue).
> 3. **A container harness that can fully test-run the whole thing** (WP + PHP + MySQL + plugin) and run integration tests in CI.

---

## Table of Contents

1. [Verdict at a Glance](#verdict)
2. [Reality vs. Documentation Gap](#reality-gap)
3. [Critical Issues (must fix before production)](#critical)
4. [Priority 1 — Admin Control Panel & First-Run Experience](#admin)
5. [Priority 2 — Resumable, Scoped Batch Processing](#resumable)
6. [Priority 3 — Container Integration Testing Harness](#containers)
7. [Security Review](#security)
8. [Logging & Error Tracking](#logging)
9. [Architecture & Code Quality](#architecture)
10. [Operational Tooling Review (setup/ & bin/)](#tooling)
11. [What Is Genuinely Good](#good)
12. [Consolidated Roadmap](#roadmap)

---

## 1. Verdict at a Glance <a id="verdict"></a>

| Dimension | Grade | One-line summary |
|---|---|---|
| Core conversion pipeline | B | Solid multi-format design with editor→GD→Imagick fallback; AVIF/JXL paths under-verified. |
| Safety model (convert→commit/rollback) | A− | The standout strength. Backup + reversible DB rewrite is genuinely well thought out. |
| DB reference rewriting | B | Comprehensive coverage (serialized/JSON), but zero test coverage on the riskiest code. |
| Admin UX / configurability | C+ | Functional 7-tab UI, but no first-run/preflight, no scope selector, settings don't fully *govern* behavior. |
| Batch / queue / resumability | D | Foreground batch works; **background queue is broken** (references a class that doesn't exist); no durable resume. |
| Logging & error tracking | B− | Good features, fragmented implementation, race conditions on JSON files, expensive option storage. |
| Security | B− | Good nonce/cap/escaping hygiene; public JSON logs leak server paths; custom-table scan under-sanitized. |
| Testing | D | ~10% coverage, broken bootstrap (missing file + hardcoded creds), **no runner, no CI, no way to execute**. |
| Container / dev tooling | B | Impressive breadth (Docker+Podman+native, configs), but **config drift** and **can't run tests**. |
| Docs accuracy | C | Polished but **overclaims** features that are broken or absent. |

**Overall: ~65–70% production-ready.** The architecture and safety model are strong enough to build on. The blockers are concentrated and fixable: a broken queue, untestable test suite, config drift, and an admin panel that doesn't yet give operators real control.

---

## 2. Reality vs. Documentation Gap <a id="reality-gap"></a>

The README and `COMPREHENSIVE_REVIEW_SUMMARY.md` describe a more finished product than the code delivers. This matters because operators will trust the docs and get surprised. Concrete mismatches:

| Doc claims | Actual state | Evidence |
|---|---|---|
| "Complete unit and integration test suite" | ~10 unit tests for one class; **bootstrap fatals** on a missing file; no integration tests; nothing can run them | `tests/bootstrap.php:84` requires `class-image-factory.php` which is not in the repo; no `phpunit.xml`/`composer.json` |
| "Background Processing — Async job queue ✅" | Background queue **fatals** when triggered | `includes/class-webp-migrator-queue.php:102` instantiates `WebP_Migrator_Database`, a class that exists nowhere |
| "Modern Admin UI" with live progress, previews | The JS that implements this (`admin/js/admin.js`) **is never enqueued** and calls AJAX actions that are **never registered** → dead code | No `admin_enqueue_scripts` hook in the constructor; actions `webp_migrator_start_background`, `webp_migrator_get_progress`, `webp_migrator_get_queue_status`, `webp_migrator_stop_background`, `webp_migrator_clear_queue`, `webp_migrator_preview_attachment` are not registered |
| Rollback "✅ Implemented" | Implemented and reasonably good — **accurate** | `process_attachment()` stores `url_map`; `rollback_conversion()` reverses it |
| AVIF/JXL supported | Offered in UI; AVIF likely **unavailable in the provided container** because GD isn't compiled `--with-avif` | `setup/docker/Dockerfile:35-43` configures GD `--with-webp` only; `imageavif()` needs GD built against libavif |

**Recommendation:** Treat the docs as a roadmap, not a description. Add a short, honest "Current Limitations" section to the README and gate feature claims behind passing tests.

---

## 3. Critical Issues <a id="critical"></a>

These block safe production use. Numbered for tracking.

### CRIT-1 — Background queue is dead code that fatals
`includes/class-webp-migrator-queue.php:102` does `new WebP_Migrator_Database($this->logger)`. No such class exists. Any path that actually runs `process_queue_batch()` throws a fatal `Error: Class "WebP_Migrator_Database" not found`. The queue's cron hook is registered, so a stray scheduled event can repeatedly fatal in the background.
**Fix:** Replace the entire homemade queue with the durable, Action-Scheduler-backed model in [§5](#resumable). Do not patch the missing class — the design is the problem.

### CRIT-2 — Admin JS/CSS never loaded; documented UI is non-functional
No `admin_enqueue_scripts` registration anywhere; `admin/js/admin.js` references `webpMigratorAdmin.nonce` (never localized) and posts to unregistered AJAX actions. The only working AJAX is the **inline** scripts inside `render_batch_tab()` / `render_reprocess_tab()`.
**Fix:** Either wire up `admin.js` properly (`wp_enqueue_script` + `wp_localize_script` + register the missing AJAX handlers) **or** delete it and consolidate on inline. Pick one; right now there are two parallel, contradictory UIs.

### CRIT-3 — Race conditions corrupt JSON logs
`log_conversion_error()` (`src/webp-safe-migrator.php:2028-2073`) and `log_dimension_inconsistency()` (`:2152-2191`) do read→decode→modify→write with `LOCK_EX` on the **write only**. Two concurrent requests (two AJAX batches, or AJAX + cron) read the same pre-state and the second write clobbers the first.
**Fix:** Move this state into the DB (custom tables from §5) **or** wrap the full read-modify-write in `flock()` over an `fopen('c+')` handle.

### CRIT-4 — `remaining` count does O(N) work on every batch tick
`ajax_process_batch()` returns `count($this->get_non_target_format_attachments(1000, true))` (`:2903`), and `get_non_target_format_attachments()` over-fetches `max($limit*10, 100)` rows then loops calling `get_post_meta`/`get_attached_file` per row. On a 50k-image library this scans up to 10k rows **per batch response**.
**Fix:** A single `SELECT COUNT(*)` against a precomputed scope (the job model in §5 makes this O(1): `total - processed`).

### CRIT-5 — Test bootstrap cannot run
`tests/bootstrap.php` hardcodes DB creds (`root`/`root123`, `:18-23`) and `require_once`s `tests/helpers/class-image-factory.php` (`:84`) which **does not exist**. The suite fatals before the first assertion.
**Fix:** Add the missing factory (or remove the require), parameterize creds via env, and add the runner/composer wiring in §6.

### CRIT-6 — Public, path-leaking log files
`webp-migrator-conversion-errors.json` and `webp-migrator-dimension-inconsistencies.json` live in `wp-content/uploads/` and the UI even links "Download JSON File". They contain absolute server paths (`full_path`).
**Fix:** Move logs under a protected dir with an `index.php` guard + `.htaccess`/`web.config` deny, or store in DB. Never expose absolute paths to unauthenticated URLs.

### CRIT-7 — Container config drift will break first-run for new users
- `setup/docker/docker-compose.yml` creates DB **`wordpress`**, user **`wpuser`/`wppass`**, and maps WordPress to **`80:80`/`443:443`**.
- `setup/mysql-init/01-webp-migrator-init.sql` runs `ALTER DATABASE wordpress_webp_test …` and creates user `webp_test` — **that database does not exist in this compose**, so the init script errors. It also `INSERT`s into `mysql.general_log`, which typically fails.
- The config YAMLs and README describe **`wordpress_webp_test`/`wordpress123`** and port **`8080`**.

Three sources of truth disagree. A new user following the README will hit a broken DB init and a wrong port.
**Fix:** Single source of truth for env (one `.env`), consumed by every compose file and the mysql-init; delete the `general_log` insert; align ports.

---

## 4. Priority 1 — Admin Control Panel & First-Run Experience <a id="admin"></a>

**Goal you stated:** "good representation in admin panel when install so things can be configured and controlled that govern how the plugin works."

### 4.1 What's missing today
- **No first-run / preflight.** Activation only checks "GD or Imagick exists" then dies or silently continues. The operator never sees *what is actually supported on this server* (WebP? AVIF? JXL? memory? execution time? uploads writable? free disk?).
- **Settings don't fully govern behavior.** Quality/format/batch exist, but there's no control over *scope* (what subset to process), *resource ceilings* (per-request time/memory budget, pause between items), *backup retention*, or *dry-run*.
- **No live scope feedback.** The "queue preview" shows 20 items but no total count, no estimated disk delta, no "you are about to touch N files / M MB."
- **AVIF/JXL are selectable even when the server can't produce them** → silent `convert_failed` later. Capability and UI are decoupled.

### 4.2 Recommended: a "Dashboard / Status" landing tab + a structured Settings panel

Add a **Status** tab as the default landing page that runs a preflight on every load and on activation. Proposed layout:

```
┌─ WebP Safe Migrator ──────────────────────────────────────────────┐
│ [ Status ] [ Settings ] [ Process ] [ Reports ] [ Errors ] [ Logs ]│
├────────────────────────────────────────────────────────────────────┤
│  SYSTEM PREFLIGHT                                          ⟳ Recheck │
│  ───────────────────────────────────────────────────────────────── │
│  Image engine        GD 2.3.3  +  Imagick 3.7.0            ✅        │
│  WebP encode         imagewebp() available                 ✅        │
│  AVIF encode         imageavif() NOT available             ⚠️  hide  │
│  JPEG XL encode      Imagick JXL delegate missing          ⚠️  hide  │
│  PHP memory_limit    512M                                  ✅        │
│  max_execution_time  300s                                  ✅        │
│  uploads writable    /wp-content/uploads (rwx)             ✅        │
│  Free disk           48 GB  (library ≈ 12 GB)              ✅        │
│  WP-Cron             enabled (Action Scheduler present)    ✅        │
│  ───────────────────────────────────────────────────────────────── │
│  LIBRARY OVERVIEW                                                    │
│  Total images 18,402 │ Already WebP 3,110 │ Eligible 15,292         │
│  Pending commit 240  │ Errors 12         │ Skipped (animated) 8     │
│  ───────────────────────────────────────────────────────────────── │
│  [ Start a migration run → ]   [ View pending commits (240) ]       │
└────────────────────────────────────────────────────────────────────┘
```

Implementation notes:
- Add `WebP_Migrator_Environment::probe()` returning a structured capability array (engines, per-format encode test using a 1×1 in-memory image, `ini_get` ceilings, `disk_free_space`, uploads writability, Action Scheduler presence).
- **Disable unsupported formats in the `<select>`** and show *why* (tooltip linking to the preflight row). This single change removes a whole class of `convert_failed` confusion.
- Run preflight in `on_activate()` and store a transient; surface a one-time admin notice with a "Review settings" CTA.

### 4.3 Settings reorganized into "control groups" that actually govern runs

| Group | Controls (new ones in **bold**) | Governs |
|---|---|---|
| **Output format** | target format (capability-gated), per-format quality, AVIF speed, JXL effort | What we produce |
| **Scope / Selection** | **process by: all / date range / specific folders / MIME types / min dimension / min file size / explicit attachment IDs / "only items with errors"**; **include-already-WebP for re-compression (on/off)** | *Which* resources get optimized — directly supports "only some resources" |
| **Resizing** | enable bounding box, mode (max/min), width/height | Dimensional normalization |
| **Safety** | validation mode (keep originals), **backup retention (days / keep until commit / never)**, **dry-run (report only, write nothing)** | Reversibility & blast radius |
| **Performance / Resource governance** | batch size, **per-request time budget (s)**, **memory ceiling guard (MB)**, **sleep between items (ms)**, **driver: AJAX foreground / background (Action Scheduler)** | How hard it hits the server, and resumability |
| **Filename hygiene** | check filename dimensions, tolerance px | Diagnostics |

- **Profiles/presets:** ship "High quality / Balanced / Max compression" presets that set quality+format in one click; advanced users override.
- **Dry-run** is the single highest-value addition for trust: it builds the URL map and *reports* what would change without touching files or the DB. Operators can preview blast radius before committing to a real run.
- Persist settings with `autoload=false` and validate/clamp (the current clamping in `update_settings_from_request()` is good — extend it to the new fields).

### 4.4 Onboarding flow at install
1. Activation → run preflight → store results.
2. Redirect (once) to the **Status** tab with a welcome panel: "Here's what your server supports. Recommended format: WebP. Start with validation mode ON and a small batch."
3. Inline "Backup your DB and uploads before large runs" reminder with a checkbox the operator must tick before the first non-validation (destructive) run.

---

## 5. Priority 2 — Resumable, Scoped Batch Processing <a id="resumable"></a>

**Goal you stated:** "allow batches to complete and return back to continue if only some resources should be processed and optimized."

### 5.1 Why the current model can't do this
- State lives in **one serialized option** (`webp_migrator_queue`) holding the entire ID list + processed/failed arrays. This grows unbounded, is rewritten in full on every tick (O(N) writes), and isn't autoloaded-off.
- There is **no durable per-item state machine** — you can't tell which specific items succeeded across interruptions, so "resume exactly where I stopped" and "re-run safely without redoing work" aren't reliably possible.
- The cron driver is broken (CRIT-1) and the AJAX driver depends on the browser tab staying open (close it → progress is lost, must restart).

### 5.2 Recommended architecture: durable jobs + items, one queue, two drivers

**Two custom tables** (created on activation via `dbDelta`):

```sql
CREATE TABLE {prefix}_webp_migrator_jobs (
  job_id        BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  status        ENUM('queued','running','paused','completed','cancelled','failed') NOT NULL DEFAULT 'queued',
  scope_json    LONGTEXT NULL,          -- the selection criteria used to build the job
  settings_json LONGTEXT NULL,          -- frozen settings snapshot for this run (format, quality, dry_run…)
  total         INT UNSIGNED NOT NULL DEFAULT 0,
  processed     INT UNSIGNED NOT NULL DEFAULT 0,
  failed        INT UNSIGNED NOT NULL DEFAULT 0,
  skipped       INT UNSIGNED NOT NULL DEFAULT 0,
  created_at    DATETIME NOT NULL,
  updated_at    DATETIME NOT NULL,
  KEY status (status)
);

CREATE TABLE {prefix}_webp_migrator_items (
  item_id       BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job_id        BIGINT UNSIGNED NOT NULL,
  attachment_id BIGINT UNSIGNED NOT NULL,
  state         ENUM('pending','in_progress','done','failed','skipped') NOT NULL DEFAULT 'pending',
  attempts      TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_error    TEXT NULL,
  bytes_before  BIGINT UNSIGNED NULL,
  bytes_after   BIGINT UNSIGNED NULL,
  updated_at    DATETIME NOT NULL,
  KEY job_state (job_id, state),
  UNIQUE KEY job_attachment (job_id, attachment_id)
);
```

**Why this shape:**
- **Resume = a query**, not bookkeeping: "give me the next K items where `state='pending'` for this job." Interruptions are irrelevant; the next tick just asks again.
- **Idempotent:** `UNIQUE(job_id, attachment_id)` + per-item `state` means re-running never double-processes. `done` items are skipped for free. This is exactly "come back and continue."
- **Selective scope:** the job is materialized from a scope query (date/folder/MIME/size/IDs). Processing "only some resources" is just building the item set from the operator's selection in §4.3.
- **O(1) progress:** `processed/total` are columns; the Status tab and progress bar read one row.
- **Per-item resilience:** `attempts`/`last_error` give bounded retries and a real "Errors" view without a separate JSON file.

**One worker, two drivers** (both call the same `process_next_chunk($job_id, $budget)`):
- **Foreground (AJAX keep-alive):** browser posts, server processes until a **time budget** (e.g., 20s) or **memory guard** is hit, persists item states, returns `{processed, remaining}`; JS loops. Closing the tab just pauses — state is durable, resume later.
- **Background:** use **Action Scheduler** (bundled, battle-tested, used by WooCommerce on millions of sites) instead of the homemade 30s `cron_schedules` interval. Schedule one recurring action that processes a chunk and re-enqueues itself until the job is drained. This survives tab closes, deploys, and PHP restarts.

**Worker chunk loop (pseudocode):**
```php
function process_next_chunk(int $job_id, int $time_budget_s = 20): array {
    $deadline = microtime(true) + $time_budget_s;
    $mem_ceiling = $this->settings['mem_ceiling_mb'] * 1024 * 1024;
    while (microtime(true) < $deadline && memory_get_usage(true) < $mem_ceiling) {
        $item = $this->claim_next_item($job_id);     // atomic UPDATE … SET state='in_progress' … LIMIT 1
        if (!$item) { $this->maybe_complete_job($job_id); break; }
        try {
            $ok = $this->process_attachment($item->attachment_id, …, $dry_run);
            $this->mark_item($item, $ok ? 'done' : 'failed');
        } catch (Throwable $e) {
            $this->mark_item($item, 'failed', $e->getMessage());
        }
        if ($this->settings['sleep_ms']) usleep($this->settings['sleep_ms'] * 1000);
    }
    return $this->job_progress($job_id);
}
```
`claim_next_item()` uses a conditional `UPDATE … WHERE state='pending' LIMIT 1` then reads the claimed row, so two concurrent workers never grab the same item (replaces the JSON race entirely).

**Controls the operator gets (wired into §4.3):** Start / Pause / Resume / Cancel, a live progress bar fed by the jobs row, and "Resume last job" if one is `paused`. This is the literal "complete a batch, walk away, come back and continue" behavior.

### 5.3 Migration path
1. Add the two tables + `WebP_Migrator_Job_Store`.
2. Rewrite `WebP_Migrator_Queue` to operate on jobs/items; delete the broken `WebP_Migrator_Database` reference.
3. Point both the inline AJAX batch UI and the background driver at `process_next_chunk()`.
4. Keep `process_attachment()` essentially as-is (it's the unit of work) but add a `$dry_run` short-circuit.

---

## 6. Priority 3 — Container Integration Testing Harness <a id="containers"></a>

**Goal you stated:** "explore if there is container setup that can test run entire thing fully, install WordPress, php, mysql … and spin up full integration testing."

### 6.1 Verdict
**A container setup to *run the app* exists and is fairly good. A container setup to *test* the app does not.** You can stand up WordPress + MySQL + phpMyAdmin (Docker or Podman) with the plugin volume-mounted, and `wp-auto-install.sh` will install WP and activate the plugin. But:

- There is **no `composer.json`, no `phpunit.xml`, no WP PHPUnit scaffold**, so the `tests/` suite cannot execute anywhere — not locally, not in a container, not in CI.
- The test bootstrap **fatals** (CRIT-5).
- **No CI** (`.github/workflows`, etc.) — nothing runs on push.
- Config drift (CRIT-7) means even the run-the-app path is fragile for a new user.
- AVIF in the container is likely non-functional (GD not built `--with-avif`).

So the honest answer: *the pieces to build full integration testing are present, but the harness itself is missing.* Below is a concrete, drop-in proposal.

### 6.2 Proposed test harness (concrete files)

**(a) `composer.json`** — pull in WP's test framework via `wp-phpunit` + Yoast polyfills:
```json
{
  "name": "okvir/webp-safe-migrator",
  "require-dev": {
    "phpunit/phpunit": "^9.6",
    "wp-phpunit/wp-phpunit": "^6.5",
    "yoast/phpunit-polyfills": "^2.0",
    "wp-cli/wp-cli-bundle": "^2.10"
  },
  "scripts": {
    "test:unit": "phpunit --testsuite unit",
    "test:integration": "phpunit --testsuite integration"
  }
}
```

**(b) `phpunit.xml.dist`** — two suites:
```xml
<phpunit bootstrap="tests/bootstrap.php" colors="true" failOnWarning="true">
  <testsuites>
    <testsuite name="unit"><directory>tests/unit</directory></testsuite>
    <testsuite name="integration"><directory>tests/integration</directory></testsuite>
  </testsuites>
</phpunit>
```

**(c) `setup/docker/docker-compose.test.yml`** — ephemeral, isolated test stack (separate DB, no host ports needed):
```yaml
services:
  test-db:
    image: mariadb:11
    environment:
      MARIADB_ROOT_PASSWORD: root
      MARIADB_DATABASE: wp_test
    tmpfs: [/var/lib/mysql]              # fast, throwaway
    healthcheck:
      test: ["CMD","healthcheck.sh","--connect"]
      interval: 5s
      retries: 20

  tests:
    build: { context: ../.., dockerfile: setup/docker/Dockerfile.test }
    depends_on:
      test-db: { condition: service_healthy }
    environment:
      WP_TESTS_DB_NAME: wp_test
      WP_TESTS_DB_USER: root
      WP_TESTS_DB_PASS: root
      WP_TESTS_DB_HOST: test-db
    volumes:
      - ../..:/app
    working_dir: /app
    command: >
      bash -lc "composer install --no-interaction &&
                bin/install-wp-tests.sh &&
                vendor/bin/phpunit"
```

**(d) `setup/docker/Dockerfile.test`** — PHP-CLI with the *full* image stack so AVIF/JXL paths are actually exercised:
```dockerfile
FROM php:8.3-cli
RUN apt-get update && apt-get install -y \
      git unzip subversion default-mysql-client \
      libwebp-dev libavif-dev libjpeg-dev libpng-dev libfreetype6-dev libheif-dev \
 && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp --with-avif \
 && docker-php-ext-install -j"$(nproc)" gd exif \
 && pecl install imagick && docker-php-ext-enable imagick
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
```
> Note the `--with-avif` that the **production** `setup/docker/Dockerfile` is missing — apply it there too so AVIF actually works at runtime, not just in tests.

**(e) Integration tests to add under `tests/integration/`** (the high-value, currently-absent coverage):
- `test-full-conversion.php` — create attachment + a post referencing it → run `process_attachment()` → assert: file is now WebP, all sizes exist, post content rewritten, `_wp_attached_file` updated, MIME updated, backup dir created.
- `test-db-rewrite.php` — seed serialized option (theme mods), JSON postmeta (page-builder data), comment with `<img>` → run rewrite → assert every reference updated and **nothing else** mutated.
- `test-rollback.php` — convert, then rollback → assert original restored, DB references reverted via stored `url_map`, converted files removed.
- `test-resume.php` (after §5) — build a job of 10 items, process a 3-item chunk, assert exactly 3 `done` + 7 `pending`; process again → 6 done; assert idempotency (no double-processing).
- `test-scope-selection.php` — assert a date/MIME/size-scoped job materializes exactly the intended attachment set.

**(f) `bin/install-wp-tests.sh`** — the standard WP test-suite installer (svn/git checkout of `wordpress-develop` test lib + `wp-tests-config.php` from env). This is what makes `tests/bootstrap.php` actually find `…/includes/functions.php` (today that path is unmet).

**(g) `.github/workflows/ci.yml`** — matrix CI:
```yaml
name: CI
on: [push, pull_request]
jobs:
  test:
    runs-on: ubuntu-latest
    strategy:
      matrix:
        php: ['7.4','8.1','8.3']
        wp:  ['6.5','latest']
    services:
      mysql:
        image: mariadb:11
        env: { MARIADB_ROOT_PASSWORD: root, MARIADB_DATABASE: wp_test }
        ports: ['3306:3306']
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: '${{ matrix.php }}', extensions: gd, imagick, exif, mysqli }
      - run: composer install --no-interaction
      - run: bash bin/install-wp-tests.sh wp_test root root 127.0.0.1 ${{ matrix.wp }}
      - run: vendor/bin/phpunit
```

**(h) `Makefile` (or `bin/test.sh`)** so it's one command:
```make
test:            ## unit + integration in containers
	docker compose -f setup/docker/docker-compose.test.yml up --build --abort-on-container-exit --exit-code-from tests
e2e:             ## wp-cli smoke test against the running app stack
	bash setup/scripts/e2e-smoke.sh
```

### 6.3 End-to-end smoke test (uses the *existing* app stack + WP-CLI)
Beyond PHPUnit, add `setup/scripts/e2e-smoke.sh` that drives the real running container the way a user would — this is the "test run the entire thing fully" you asked for:
```bash
# inside the wordpress container, via compose exec
wp media import sample1.jpg sample2.png --porcelain          # seed library
wp webp-migrator run --batch=10                              # run the real plugin
wp eval 'echo (new WP_Query(["post_type"=>"attachment","post_mime_type"=>"image/webp"]))->found_posts;'  # assert conversions
wp webp-migrator status                                       # (after adding the subcommand)
```
Wire it into `podman-setup.sh`/`container-setup.sh` as a `test` action so `./setup/container-setup.sh test` does: up → install → seed → migrate → assert → teardown.

### 6.4 Fixes required to make any of this run
1. Create `tests/helpers/class-image-factory.php` (or drop the require) — **CRIT-5**.
2. Replace hardcoded creds in `tests/bootstrap.php` with `getenv()` reads.
3. Reconcile the test DB name across `docker-compose*.yml`, `mysql-init`, and configs — **CRIT-7**.
4. Add `--with-avif` to the GD build (test + prod Dockerfiles) so AVIF tests are meaningful.

---

## 7. Security Review <a id="security"></a>

### Passing
Nonces on every form action; `manage_options` capability checks throughout; settings clamped/whitelisted; consistent `esc_html/esc_attr/esc_url` output escaping; `check_ajax_referer()` + capability on AJAX; `ABSPATH` guards; `WP_UNINSTALL_PLUGIN` guard on uninstall.

### Issues
| ID | Severity | Finding | Fix |
|---|---|---|---|
| SEC-1 | High | Public JSON logs leak absolute server paths (CRIT-6) | Protect dir / move to DB |
| SEC-2 | Medium | Custom-table scan uses table/column names from `SHOW TABLES`/`DESCRIBE` directly in raw SQL and `$wpdb->update()` (`:2796-2853`) without identifier validation | Whitelist `^[A-Za-z0-9_]+$`; backtick-escape; cap rows |
| SEC-3 | Medium | `replace_in_json_columns()` rewrites arbitrary custom tables (WooCommerce, etc.) heuristically with `LIMIT 100`/first-5-URLs — silent partial updates and potential corruption of non-image text that happens to contain a matching substring | Make it opt-in per-table in settings; log every table touched; never run by default |
| SEC-4 | Low | `$_GET['tab']`/`attachment_id` validated by whitelist/cast but not run through `sanitize_text_field`/`absint` | Use `absint()` / `sanitize_key()` for consistency |
| SEC-5 | Low | Pervasive `@` error suppression hides real failures | Replace with explicit checks + logging |

**Substring-replacement hazard (correctness ∩ security):** `deep_replace()` does raw `str_replace` of old→new URL across *all* matched values. A short upload URL can be a substring of an unrelated string, causing unintended edits. Anchor replacements to full URLs/paths and prefer exact-key maps over blind substring replace where possible.

---

## 8. Logging & Error Tracking <a id="logging"></a>

**Good:** leveled logger (debug→critical) with DB+file+`WP_DEBUG_LOG` sinks; error-count aggregation with first/last timestamps (prevents flooding); per-attachment error in postmeta *and* central JSON; memory tracking in entries; export to JSON/CSV/TXT.

**Problems:**
1. **Two logging systems.** The main class uses `error_log()` + custom `log_conversion_error()`; `WebP_Migrator_Logger` is only used by the (broken) queue/converter. Unify on the Logger.
2. **DB log is a single autoloaded option** holding up to 1,000 serialized entries (`get_option`+`update_option` per write = O(N), likely `autoload=yes` → loaded on every request). Move to a custom table (you'll already have the schema habit from §5) or at minimum `autoload=false` + cap.
3. **JSON file races** (CRIT-3).
4. **No rotation** of daily log files — they accumulate forever.
5. **Unstructured messages** — can't filter by attachment/format/step without parsing strings. Add structured columns.

**Recommendation:** fold error/dimension tracking into the same DB-backed store as jobs/items; keep file logging for `WP_DEBUG`, add 30-day rotation, protect the directory.

---

## 9. Architecture & Code Quality <a id="architecture"></a>

- **Monolith:** `src/webp-safe-migrator.php` is ~3,580 lines doing settings, 7 tabs of HTML, conversion, DB rewrite, file ops, AJAX, stats, and WP-CLI. Extract: `…_Settings`, `…_Admin` (views), `…_Converter` (consolidate — see below), `…_Database`, `…_JobStore`, `…_Logger`, `…_Ajax`, `…_CLI`. Target: main file just wires things together.
- **Converter duplication:** `includes/class-webp-migrator-converter.php` exists but the main class **doesn't use it** and re-implements conversion (and only it supports AVIF/JXL). The standalone converter is WebP-only and is used solely by the broken queue. Consolidate into one multi-format converter; delete the duplicate path.
- **Private property reached as public:** the WP-CLI handler sets `$plugin->settings[...]` (`:3543-3549`) on a `private` property; works only by same-file scoping. Add a real setter.
- **Two contradictory UIs** (inline AJAX vs. unenqueued `admin.js`) — see CRIT-2.
- **Inline styles everywhere** instead of the existing `admin.css`.

---

## 10. Operational Tooling Review (setup/ & bin/) <a id="tooling"></a>

**Strengths (genuinely impressive breadth):**
- Docker **and** Podman compose stacks, Apache **and** Nginx variants, plus native LAMP installers for Linux/macOS/Windows.
- `plugin-manager.{sh,ps1}` is full-lifecycle: install/update/uninstall/backup/restore/status/cleanup/setup-db, with WP-CLI-or-direct-MySQL fallbacks and config-preserving updates.
- A flexible YAML config system (simple + comprehensive templates) with a custom PowerShell reader.
- `wp-auto-install.sh` is idempotent and does health-gated WP install + plugin activation + test content.

**Weaknesses:**
- **Config drift** across compose files / mysql-init / YAML / README (CRIT-7) — the single most likely thing to break a new user's first run.
- **mysql-init bugs:** targets a non-existent DB and `INSERT`s into `mysql.general_log` (likely errors).
- **No test execution anywhere** — none of this rich tooling runs `phpunit` (§6).
- **bash/PowerShell duplication** is a maintenance tax; consider generating both from the YAML or consolidating on WP-CLI.
- **Archive sprawl:** `setup/archive/` has ~12 older deploy scripts; prune to avoid confusion about the canonical path.
- Production Dockerfile GD lacks `--with-avif` while the UI offers AVIF.

---

## 11. What Is Genuinely Good <a id="good"></a>

- **The convert→validate→commit/rollback safety model.** Storing the full `url_map` in the report so a rollback can *reverse the exact DB edits* is excellent and rare in plugins of this type.
- **Breadth of DB coverage** — posts, postmeta, options, usermeta, termmeta, comments, custom tables, with serialized + JSON handling.
- **Multi-format abstraction** (`SUPPORTED_TARGET_FORMATS` + per-format options + editor/GD/Imagick fallback chain).
- **Input validation** in settings (clamping + whitelist).
- **Error aggregation** with counts and first/last timestamps.
- **Operational ambition** — the container/native/Podman tooling and config system are well beyond what most plugins ship.
- **Thorough uninstall** cleanup.

Keep all of this. The work below is about making the strong parts trustworthy and the weak parts real.

---

## 12. Consolidated Roadmap <a id="roadmap"></a>

### Phase 0 — Make it honest & runnable (0.5–1 wk)
| # | Task | Maps to |
|---|---|---|
| 0.1 | Reconcile container env (one `.env`; fix mysql-init; align ports) | CRIT-7 |
| 0.2 | Add `composer.json`, `phpunit.xml.dist`, `bin/install-wp-tests.sh`, `Dockerfile.test`, `docker-compose.test.yml` | §6 |
| 0.3 | Fix test bootstrap (missing factory, env creds) | CRIT-5 |
| 0.4 | Add `--with-avif` to GD builds; capability-gate AVIF/JXL in UI | §4.2, Reality-gap |
| 0.5 | Trim doc overclaims; add "Current Limitations" | §2 |

### Phase 1 — Critical correctness (1–1.5 wk)
| # | Task | Maps to |
|---|---|---|
| 1.1 | Replace broken queue with durable jobs/items + Action Scheduler | CRIT-1, §5 |
| 1.2 | Resolve the dual-UI problem (enqueue `admin.js` + register AJAX, or delete it) | CRIT-2 |
| 1.3 | Kill JSON-log races (DB-backed store or `flock`) | CRIT-3 |
| 1.4 | O(1) remaining-count via job columns | CRIT-4 |
| 1.5 | Protect/relocate log files; stop leaking absolute paths | CRIT-6, SEC-1 |

### Phase 2 — The three priorities, fully landed (2–3 wk)
| # | Task | Maps to |
|---|---|---|
| 2.1 | Status/preflight tab + capability probe + activation onboarding | §4.2–4.4 |
| 2.2 | Scope/selection controls + dry-run + resource governance settings | §4.3 |
| 2.3 | Resumable Start/Pause/Resume/Cancel over jobs/items, both drivers | §5.2 |
| 2.4 | Integration tests (full conversion, db-rewrite, rollback, resume, scope) + CI matrix + e2e smoke | §6.2–6.3 |

### Phase 3 — Hardening & quality (2–3 wk)
| # | Task | Maps to |
|---|---|---|
| 3.1 | Consolidate converter; remove duplication | §9 |
| 3.2 | Extract admin/views, settings, db, logger, ajax, cli classes | §9 |
| 3.3 | Unify logging on `WebP_Migrator_Logger`; DB store; rotation; autoload=false | §8 |
| 3.4 | Make custom-table rewrite opt-in + sanitized + anchored replacements | SEC-2/3, §7 |
| 3.5 | Pagination for commits/reports/errors; move inline styles to `admin.css` | §9 |

### Phase 4 — Nice-to-have
WP-CLI `status`/`stats`/`rollback`/`commit` subcommands; REST endpoints; multisite; before/after visual diff; prune `setup/archive/`.

---

### Bottom line
The plugin's **safety model is its crown jewel** and the **operational tooling shows real ambition** — but three things currently undermine trust: a **broken background queue**, an **admin panel that doesn't yet give operators real control or a preflight**, and a **test/CI story that cannot actually execute**. Phases 0–2 turn this from "promising prototype with strong bones" into a genuinely deployable, resumable, testable migration tool. The designs in §4–§6 are concrete enough to implement directly.
