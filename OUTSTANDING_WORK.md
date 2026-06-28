# Outstanding Work

**Last updated:** 2026-06-28

Status snapshot of what is done and what remains. For the full code review and rationale, see **[review.md](review.md)**.

---

## ✅ Done (test & quality hardening iteration)

- **Containerized test harness** — `composer.json`, `phpunit.xml.dist`, `bin/install-wp-tests.sh`, `setup/docker/Dockerfile.test`, `setup/docker/docker-compose.test.yml`.
- **Unit + integration suite — 70 tests green** (1 skipped: JPEG-XL, no Imagick delegate locally). Tests read back real **filesystem** and **database** outputs (not mocks).
- **Coverage tooling** — `bin/coverage.sh` + `bin/coverage-summary.py` (line **and** branch via Xdebug path coverage). Current: **~79% line / ~75% branch** (tiered: view-render methods + WP-CLI excluded from the gate).
- **Live end-to-end** — `bin/e2e.sh`: real WordPress + WP-CLI install → activate → seed media → convert → assert filesystem + database + rollback/commit lifecycle.
- **Background queue rewritten** — delegates to the real `process_attachment()` pipeline (removed the nonexistent `WebP_Migrator_Database` dependency); now functional, resumable, and tested (0% → ~83%).
- **CI** — `.github/workflows/ci.yml` matrix (PHP 7.4/8.1/8.3 × WP 6.5/latest).
- **5 real production bugs fixed** (caught by the new tests):
  1. `parse_filename_dimensions` regex crash on the multibyte `×`.
  2. Double-serialization of serialized options/postmeta in `replace_in_table_serialized_with_report` (data corruption on read).
  3. Malformed `uploads/./file.webp` paths when WP's month/year folders are disabled — also broke rollback's reverse mapping.
  4. WP-CLI `run` fatal — assignment to the private `$settings` property (CLI path was entirely non-functional).
  5. Unrunnable test bootstrap (missing helper, hardcoded DB creds, `setUp` vs `set_up`).

---

## 📌 Outstanding

### Coverage
- [ ] **Push the core tier to ≥90%** (converter 88→90, queue 83→90, main-file core 75→90). Remaining gaps are deep **error-injection** branches: unwritable target dir, `wp_mkdir_p`/`wp_update_post` failures, `convert_failed` / `metadata_failed` paths, `file_cleanup`/`url_mapping` exception handlers.
- [ ] **JS coverage for `admin/js/admin.js`** — currently unmeasured (needs Node). `package.json` + `tests/js/admin.test.cjs` exist; wire `npm run coverage` into CI or a node container.
- [ ] **Merged unit + integration + E2E coverage number** — instrument the E2E container (Xdebug + `SebastianBergmann\CodeCoverage` prepend/append → `.cov`) and `phpcov merge` with the PHPUnit `.cov`. This is the only way to get a true total and would pull the WP-CLI class into the measured %.

### Correctness / known limitations
- [ ] **JSON-in-postmeta slash escaping** — URLs inside `wp_json_encode`'d values stored in *core postmeta* are not rewritten (default JSON escapes slashes `http:\/\/`, and the postmeta path uses raw `str_replace`). Only serialized PHP arrays and custom-table JSON are handled. Fix or document for users.
- [ ] **Custom-table JSON rewrite (`replace_in_json_columns`)** — make it **opt-in per table** + validate/whitelist table & column identifiers, and log every table touched (see review.md SEC-2/SEC-3). Currently best-effort across the first 10 custom tables.
- [ ] **`admin.js` is not enqueued** and the AJAX actions it references aren't registered — decide: wire it up (`wp_enqueue_script` + `wp_localize_script` + register handlers) **or** remove it (dead code) and keep the inline batch UI.

### Architecture (from review.md)
- [ ] **Queue → Action Scheduler + durable `jobs`/`items` tables** for true resumable scale (the current rewrite is functional but still uses the homemade 30s WP-Cron). See review.md §5.
- [ ] **Extract the ~3,500-line monolith** (`src/webp-safe-migrator.php`) into Admin/Views, Settings, Database, Ajax, CLI classes; consolidate the duplicate converter logic. See review.md §9.
- [ ] **Admin onboarding / preflight** (capability probe gating AVIF/JXL, memory/disk/uploads checks) and **scoped, resumable batches with dry-run**. See review.md §4–§5.

### UX / polish
- [ ] Pagination for pending-commits, reports, and error lists (currently capped at 50/200).
- [ ] Move inline styles into `admin/css/admin.css`; protect/relocate the public JSON log files in `uploads/` (they leak server paths — review.md SEC-1/CRIT-6).

### Ops / environment
- [ ] **Corporate-network/WSL DNS:** the Podman VM can lose DNS (public resolvers blocked). Use `bin/fix-podman-dns.sh` before `bin/test.sh` / `bin/coverage.sh` / `bin/e2e.sh` if a run fails with "Could not resolve host". A permanent fix would pin the VM resolver or use an internal registry mirror.

---

## Feature → test coverage (current)

All listed features have tests that verify real FS and/or DB outputs:

| Feature | Test |
|---|---|
| WebP / AVIF conversion (+ thumbnails) | `test-conversion-and-fs`, `test-formats-and-resize`, E2E |
| JPEG-XL conversion | `test-formats-and-resize` (skipped without a JXL delegate) |
| DB rewrite: posts, postmeta (plain + serialized), options, comments, **usermeta/termmeta** | `test-db-rewrite`, `test-usermeta-termmeta-skip` |
| Custom-table JSON rewrite | `test-internal-logging-json` |
| Validation/backup, commit (single + all), rollback (single + all) | `test-conversion-and-fs`, `test-rollback-and-commit`, `test-core-edges`, E2E |
| Background queue (resumable) | `test-queue` |
| Batch + AJAX handlers | `test-ajax` |
| Scope selection, skip-MIME, **skip-folders**, animated-GIF skip | `test-scope-and-skip`, `test-usermeta-termmeta-skip` |
| Bounding-box resize (applied) | `test-formats-and-resize` |
| Error logging/tracking; dimension validation; statistics; cleanup | `test-internal-logging-json` |
| Error handlers (remove/retry/rollback-single) | `test-error-handlers-and-uninstall` |
| Logger (levels/export/stats) | `test-logger` |
| Settings save + maintenance actions | `test-actions` |
| `on_activate()` + `uninstall.php` cleanup | `test-error-handlers-and-uninstall` |
| WP-CLI `run` | E2E |
| Admin UI tabs (no-fatal smoke) | `test-admin-render-smoke` |
