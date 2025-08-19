# WebP Safe Migrator

Convert all non-WebP images in your WordPress media library to WebP at a fixed quality, safely update all usages (posts, pages, custom post types, postmeta, and options) and attachment metadata, and remove originals only after validation (optional). Includes skip rules, reports, and a WP-CLI runner.

---

## Features

- Converts original images **and all registered sizes** (e.g. `-300x200`) to **WebP** at a chosen quality.
- Updates **attachment metadata**:
  - `_wp_attachment_metadata`
  - `_wp_attached_file`
  - `post_mime_type` → `image/webp`
  - `guid` → WebP URL
- Rewrites **all usages** of the old URLs:
  - `post_content` (posts/pages/CPTs)
  - `postmeta` (safe for serialized data)
  - `options` (safe for serialized data)
- **Validation mode** (default): keep originals in a backup folder until you press **Commit**.
- **Skip rules**: skip specific folders and/or MIME types.
- **Reports UI**: per-attachment report shows where replacements occurred.
- **WP-CLI**: batch runner (`wp webp-migrator run`) respecting your skip rules and validation setting.

---

## Requirements

- WordPress 5.8+ (recommended 6.x)
- PHP 7.4+ (recommended 8.1+)
- **WebP support** via either:
  - GD with `imagewebp()` available, or
  - Imagick with `WEBP` format support
- WP-CLI (optional, for command line runs)
- Filesystem permissions that allow writing to `wp-content/uploads/`

---

## Installation

1) Create the plugin directory:

    wp-content/plugins/webp-safe-migrator/

2) Save the plugin file as:

    wp-content/plugins/webp-safe-migrator/webp-safe-migrator.php

   (Use the complete plugin code you generated in this project.)

3) Activate the plugin in **WP Admin → Plugins**.

4) Ensure your PHP image library supports WebP:
   - If activation fails with a WebP support message, enable GD/Imagick WebP on your server.

---

## Quickstart (Dashboard)

1) Go to **Media → WebP Migrator**.
2) Set:
   - **Quality** (0–100; typical: 59 or 75)
   - **Batch size** (how many attachments to process per click/run)
   - **Validation mode** (checked = keep originals; uncheck to delete originals immediately)
   - Optional **Skip folders** and **Skip MIME types**
3) Click **Process next batch**.
4) Browse critical pages/posts on your site to verify images load in WebP.
5) In **Pending commits**, click **Commit delete** for individual items or **Commit ALL** when satisfied.

**Where backups go in validation mode**

- Originals are moved to:

    wp-content/uploads/webp-migrator-backup/<YYYYMMDD-HHMMSS>/att-<ATT_ID>/

- They are deleted only when you **Commit**.

---

## Skip Rules

- **Skip folders** (one per line, case-insensitive, substring match; paths are relative to `wp-content/uploads/`):
  
    cache
    private-uploads
    temp

  The above will skip any file whose relative uploads path contains `cache`, `private-uploads`, or `temp`.

- **Skip MIME types** (comma or space separated):

    image/gif, image/png

  The above will skip GIFs and PNGs (JPEGs will still be converted).

---

## Reports (What Changed Where)

- Navigate to **Media → WebP Reports**.
- The list shows recent processed attachments and counts of:
  - Posts updated
  - Postmeta entries updated
  - Options updated
- Click **View** for a per-attachment report with:
  - Timestamp
  - URL map count
  - Exact post IDs (with Edit links)
  - Exact postmeta keys changed (per post)
  - Exact option names changed

Internal storage:
- The report payload is saved in post meta under `_webp_migrator_report`.

---

## WP-CLI Usage

From the WordPress root (where `wp-cli.yml` typically lives):

- Run a batch with **validation ON** (keep originals in backup until you commit in the UI):

    wp webp-migrator run --batch=100

- Run a batch with **no validation** (delete originals immediately after relinking):

    wp webp-migrator run --batch=100 --no-validate

Notes:
- The CLI respects your **skip folders** and **skip mimes** settings from the dashboard.
- You can run the command multiple times; it processes the next eligible batch each time.

---

## How It Works (Summary)

- Finds non-WebP attachments (`image/jpeg`, `image/png`, `image/gif`) minus any MIME types you skip.
- Converts the **original** to WebP at the configured quality, then regenerates sizes from the WebP.
- Builds an **old→new URL map** for original and each size (and common filesystem path variants).
- Rewrites:
  - `post_content` via string replacement
  - `postmeta` and `options` via safe **deserialize → recursive replace → re-serialize**
- Updates attachment:
  - `_wp_attachment_metadata`, `_wp_attached_file`, `post_mime_type`, `guid`
- In validation mode, moves originals into a dated backup folder; otherwise, deletes them immediately.
- Stores a **report** per attachment.

**Animated GIFs**  
- Skipped by default (marked `skipped_animated_gif`). Add animated WebP handling later if needed.

---

## Configuration Reference (Dashboard)

- **Quality**: Integer 1–100. Lower = smaller files; typical values 59–85.
- **Batch size**: How many attachments to process per run/click (tune for server resources).
- **Validation mode**:
  - ON (default): safer; originals are kept in `webp-migrator-backup/` until you commit.
  - OFF: faster; originals are deleted immediately.
- **Skip folders**: One per line; relative to `wp-content/uploads/`; substring match.
- **Skip MIME types**: Comma/space separated (e.g. `image/gif, image/png`).

---

## Best Practices

- **Backup** your database and uploads before large migrations.
- **Purge caches/CDN** after conversion so visitors see the new WebP assets.
- Start with a **small batch size**; increase once you validate performance and results.
- Use **validation mode** initially, then commit after spot-checks.

---

## Troubleshooting

- *Activation failure: “requires GD or Imagick with WEBP support”*  
  Enable GD WebP (`imagewebp`) or install Imagick with WebP support on your server.

- *Some pages still show old URLs*  
  Flush any page builders’ caches and your site cache/CDN. The plugin rewrites DB content; cached HTML may still reference old URLs.

- *A plugin stores images by attachment ID, not URL*  
  You’re covered. The attachment’s metadata and MIME are updated to WebP; consumers resolving by ID will serve the new WebP URLs.

- *Time-outs on large media libraries*  
  Lower **Batch size** or use WP-CLI in smaller repeated runs.

---

## Safety & Data Integrity

- Serialized data in `postmeta` and `options` is handled safely (deserialize/replace/re-serialize).
- Validation mode keeps originals until you confirm via **Commit**.
- Reports preserve a record of what was changed for each attachment.

---

## Uninstall / Rollback

- If you used **validation mode**, uncommitted originals reside in:

    wp-content/uploads/webp-migrator-backup/<timestamp>/att-<ATT_ID>/

  You can manually restore files from there.

- For full rollback, restore from your **site backup** (database + uploads).

---

## Paths & Files

- Plugin main file:

    wp-content/plugins/webp-safe-migrator/webp-safe-migrator.php

- Backup folder (validation mode):

    wp-content/uploads/webp-migrator-backup/

---

## License

GPL-2.0+ (matches standard WordPress plugin licensing).
