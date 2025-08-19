# WebP Safe Migrator

Convert all non-WebP images in your WordPress media library to WebP at a fixed quality, safely update all usages and attachment metadata, and (optionally) remove originals after validation. Includes skip rules, reports, and a WP-CLI runner.

## Installation
- Create `wp-content/plugins/webp-safe-migrator/`
- Save main file as `wp-content/plugins/webp-safe-migrator/webp-safe-migrator.php`
- Activate in **WP Admin → Plugins**

## Quickstart
1) Open **Media → WebP Migrator**  
2) Configure **Quality**, **Batch size**, **Validation mode**, optional **skip rules**  
3) Click **Process next batch**  
4) Review, then **Commit** to delete originals (validation mode)

## WP-CLI
- Validate on (keep originals): `wp webp-migrator run --batch=100`
- No validation (delete originals immediately): `wp webp-migrator run --batch=100 --no-validate`

## Reports
See **Media → WebP Reports** for per-attachment changes (posts, postmeta, options).

## Docs
See `ARCHITECTURE.md` and diagrams in `diagrams/`.
