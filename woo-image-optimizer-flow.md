# WooCommerce Image Optimizer — System Flow Document

**Version:** 2.1.0  
**Last updated:** 2026-06-17

---

## 1. Overview

A two-component system:

| Component | Location | Responsibility |
|-----------|----------|---------------|
| WordPress plugin (`includes/`) | Server 1 (WordPress host) | Queue management, WP-Cron dispatch, DB updates, admin UI |
| FastAPI service (`server2-api/`) | Server 2 (API host) | WebP conversion, backup storage |

**Production API URL:** `https://imgoptimizer.behdashtik.ir`

All image compression happens on Server 2. WordPress only enqueues jobs, sends files over HTTPS, writes the returned WebP to disk, and updates metadata. Zero processing overhead on page loads.

---

## 2. Scope — WooCommerce images only

Only attachments referenced by WooCommerce product posts are processed:

| Meta key | Post type |
|----------|-----------|
| `_thumbnail_id` | `product`, `product_variation` |
| `_product_image_gallery` | `product` |

Images in the WordPress media library that are not linked to any WooCommerce product are **never** queued or touched.

---

## 3. Skip rules

An attachment is skipped (never queued) if **any** of the following is true:

1. `mime_type = 'image/avif'` OR file extension is `.avif`
2. `_woo_optimizer_status = 'done'` already stored on the attachment
3. Not referenced by any WooCommerce product or variation

---

## 4. Queue table

```sql
CREATE TABLE {prefix}woo_optimizer_queue (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    attachment_id BIGINT UNSIGNED NOT NULL,
    product_id    BIGINT UNSIGNED NOT NULL,
    status        ENUM('pending','processing','done','failed') DEFAULT 'pending',
    attempts      TINYINT UNSIGNED DEFAULT 0,
    error_msg     TEXT NULL,
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_attachment (attachment_id)
);
```

Status transitions:

```
pending → processing → done
pending → processing → failed  (after 3 attempts)
failed  → pending              (via Retry Failed UI button or CLI)
processing → pending           (stale reset: stuck > 5 min with attempts < 3)
```

---

## 5. Auto-queue on product image change

`class-woocommerce.php` hooks into:
- `updated_post_meta` / `added_post_meta` for `_thumbnail_id`
- `updated_post_meta` / `added_post_meta` for `_product_image_gallery`

On each hook fire: apply skip rules → if not skipped → `queue->enqueue(attachment_id, product_id)`.

---

## 6. WP-Cron processing

- **Hook:** `woo_optimizer_cron_tick`
- **Interval:** every 60 seconds (custom WP-Cron schedule)
- **Lock:** `set_transient('woo_optimizer_lock', 1, 25)` — prevents overlapping runs if a batch takes longer than expected

On each tick (`class-cron.php → class-processor.php`):
1. `queue->reset_stale()` — resets jobs stuck in `processing` > 5 min back to `pending`
2. `queue->dequeue_batch($batch_size)` — atomically marks N `pending` rows as `processing`
3. For each job: `process_job($job)`

---

## 7. Job processing flow (one image)

```
class-processor.php::process_job($job)

1. Get absolute file path from attachment_id
   └─ validate file exists on disk

2. Check _woo_optimizer_backup_key postmeta
   ├─ ALREADY SET → skip /backup call (retry-safe: avoids duplicate backups)
   └─ NOT SET →
      POST /backup  (file + attachment_id)
      └─ store backup_key in _woo_optimizer_backup_key immediately

3. POST /optimize  (file + attachment_id + quality + max_width + max_height)
   └─ receives: { webp_file (base64), original_size, optimized_size, saved_bytes, width, height }

4. base64_decode(webp_file) → write .webp file next to original

5. Store in postmeta: _woo_optimizer_original_file, _woo_optimizer_original_mime

6. Delete original .jpg / .png from disk

7. class-db-updater.php::update_all()
   ├─ update_post_meta: _wp_attached_file → relative .webp path
   ├─ wp_generate_attachment_metadata() + wp_update_attachment_metadata()
   ├─ wp_update_post: post_mime_type = 'image/webp'
   ├─ store _woo_optimizer_* postmeta (status, backup_key, sizes, saved_bytes, etc.)
   └─ delete_old_thumbnail_files(): removes old .jpg/.png size files listed in
      the original $meta['sizes'] array (e.g. product-300x300.jpg)

8. queue->mark_done($job->id)
```

On any failure:
- `attempts < 3` → `queue->retry($id)` (back to `pending`)
- `attempts >= 3` → `queue->mark_failed($id, $error_message)`

---

## 8. File upload method (memory-safe)

`class-api-client.php::build_multipart()` reads image files using `WP_Filesystem->get_contents()` instead of `file_get_contents()`. This avoids loading the full binary into a second PHP string while the original is still on disk, preventing double memory usage on large images.

```php
global $wp_filesystem;
if ( empty( $wp_filesystem ) ) {
    require_once ABSPATH . 'wp-admin/includes/file.php';
    WP_Filesystem();
}
$data = $wp_filesystem->get_contents( $file_path );
```

---

## 9. Retry failed jobs

Failed jobs (status = `failed`) can be reset to `pending` with `attempts = 0` via:

- **Admin UI:** "Retry Failed (N)" button appears on the dashboard whenever failed > 0
- **WP-CLI:** `wp woo-optimizer retry-failed`

Both report how many jobs were reset.

---

## 10. Restore flow

```
class-restore.php::restore($attachment_id)

1. Read postmeta: _woo_optimizer_backup_key, _woo_optimizer_original_file,
   _woo_optimizer_original_mime

2. GET /backup/{backup_key}  → binary original file data

3. Write original file back to filesystem at its original path

4. Collect current WebP thumbnail filenames from current attachment metadata

5. wp_generate_attachment_metadata() on original + wp_update_attachment_metadata()

6. Update _wp_attached_file to original relative path

7. wp_update_post: post_mime_type = original MIME type

8. Delete current .webp file from disk

9. Delete WebP thumbnail size files

10. delete_post_meta() for all _woo_optimizer_* keys

11. $wpdb->delete() queue row for this attachment
```

---

## 11. Server 2 API specification

**Base URL:** `https://imgoptimizer.behdashtik.ir`  
**Auth header:** `Authorization: Bearer {WOO_IMG_API_KEY}`

### POST /optimize

**Request:** `multipart/form-data`

| Field | Type | Description |
|-------|------|-------------|
| `file` | file | Original image (JPEG, PNG, etc.) |
| `attachment_id` | int | WordPress attachment post ID |
| `quality` | int (1–100) | WebP quality, default 82 |
| `max_width` | int | Downscale limit, default 2048 |
| `max_height` | int | Downscale limit, default 2048 |

**Response:**
```json
{
  "success": true,
  "webp_file": "<base64-encoded WebP binary>",
  "original_size": 204800,
  "optimized_size": 61440,
  "saved_bytes": 143360,
  "width": 1200,
  "height": 900
}
```

### POST /backup

**Request:** `multipart/form-data`

| Field | Type | Description |
|-------|------|-------------|
| `file` | file | Original image to store permanently |
| `attachment_id` | int | WordPress attachment post ID |

**Response:**
```json
{ "success": true, "backup_key": "550e8400-e29b-41d4-a716-446655440000" }
```

### GET /backup/{key}

Returns original image binary (`application/octet-stream`).

### DELETE /backup/{key}

```json
{ "success": true }
```

---

## 12. Security — access restriction

Access to the Server 2 API is restricted to Server 1 (WordPress host) only via two independent layers:

### Layer 1 — Nginx (network level)

```nginx
allow YOUR_SERVER1_IP;
deny  all;
```

Requests from any IP other than the WordPress server are rejected with `403` at the Nginx level, before reaching the application.

### Layer 2 — FastAPI middleware (application level)

`IPAllowlistMiddleware` in `main.py` reads `WOO_IMG_ALLOWED_IP` from the environment. On every request it checks the source IP (honoring `X-Forwarded-For` set by the local nginx proxy). If the IP does not match, returns `403` before the Bearer token auth check.

```bash
# Required env var on Server 2
WOO_IMG_ALLOWED_IP=<WordPress server public IP>
```

The WordPress server's outbound IP is entered in **WP Admin → WooCommerce Image Optimizer → Settings → This Server's Outbound IP**. This value is stored locally for reference only — it is not sent in API requests.

---

## 13. SSL — Let's Encrypt

Certificate is obtained via certbot for `imgoptimizer.behdashtik.ir`:

```bash
certbot certonly --nginx -d imgoptimizer.behdashtik.ir
```

Auto-renewal is handled by certbot's systemd timer. Nginx listens on 443 with the issued certificate and redirects port 80 → 443.

---

## 14. Admin UI

**Location:** WP Admin → Media → WooCommerce Image Optimizer

### Dashboard stats bar

Shows: Total Queued | Done | Pending | Failed | Bytes Saved

### Bulk action buttons

| Button | Condition | Action |
|--------|-----------|--------|
| Optimize All Product Images | API configured | Enqueues all unoptimized product images, then processes via batch loop |
| Pause / Resume | Running | Pauses/resumes the batch loop |
| Retry Failed (N) | failed > 0 | Resets all failed jobs to pending with attempts = 0 |

### Settings fields

| Field | Key | Notes |
|-------|-----|-------|
| Server 2 API URL | `api_url` | `https://imgoptimizer.behdashtik.ir` |
| API Key | `api_key` | `WOO_IMG_API_KEY` value from Server 2 env |
| This Server's Outbound IP | `server1_ip` | Stored for reference; used to configure Server 2 |
| WebP Quality | `webp_quality` | 1–100, default 82 |
| Max Dimensions | `max_width`, `max_height` | Default 2048×2048 |
| Batch Size | `batch_size` | Jobs per cron run, default 5 |
| Auto-optimize | `auto_optimize` | Queue on product image assignment |
| Backup Retention | `backup_retention_enabled`, `backup_retention_days` | Triggers DELETE on Server 2 after N days |

### Media library column

Per-attachment status:
- **Done:** ✓ {KB saved} [↩ Restore button]
- **Queued:** ⌛ Queued
- **Failed:** ✗ Failed
- **Not processed:** —

---

## 15. WP-CLI commands

```bash
wp woo-optimizer run              # process one batch of pending jobs
wp woo-optimizer stats            # queue health table
wp woo-optimizer queue-all        # enqueue all unoptimized product images
wp woo-optimizer queue-all --dry-run
wp woo-optimizer restore <id>     # restore single attachment from Server 2
wp woo-optimizer retry-failed     # reset all failed jobs to pending (attempts = 0)
```

---

## 16. Postmeta written per attachment

| Key | Value |
|-----|-------|
| `_woo_optimizer_status` | `done` |
| `_woo_optimizer_backup_key` | UUID from Server 2 `/backup` |
| `_woo_optimizer_original_file` | Original relative path (e.g. `2026/06/photo.jpg`) |
| `_woo_optimizer_original_mime` | Original MIME (e.g. `image/jpeg`) |
| `_woo_optimizer_original_size` | Original file size in bytes |
| `_woo_optimizer_optimized_size` | WebP file size in bytes |
| `_woo_optimizer_saved_bytes` | Bytes saved |
| `_woo_optimizer_optimized_at` | ISO 8601 timestamp |

---

## 17. File structure

```
wordpress-image-optimizer/
├── wordpress-image-optimizer.php      ← bootstrap, WOO_IMG_OPT_* constants, singleton
├── uninstall.php                      ← drops queue table + deletes option
├── woo-image-optimizer-flow.md        ← this document
├── includes/
│   ├── class-settings.php             ← woo_optimizer_settings option
│   ├── class-queue.php                ← MySQL queue CRUD + retry_all_failed()
│   ├── class-api-client.php           ← HTTP client (WP_Filesystem reads, multipart)
│   ├── class-woocommerce.php          ← product image detection + skip rules
│   ├── class-db-updater.php           ← all WP DB writes post-optimization
│   ├── class-processor.php            ← job orchestration + retry logic
│   ├── class-cron.php                 ← 60s schedule + transient lock
│   ├── class-restore.php              ← restore flow
│   ├── class-admin.php                ← settings page + AJAX + media column
│   └── class-cli.php                  ← WP-CLI commands
├── assets/
│   ├── css/admin.css                  ← .wio-* styles
│   └── js/admin.js                    ← bulk UI + retry-failed handler
└── server2-api/
    ├── main.py                        ← FastAPI app: all 4 endpoints + IP middleware
    ├── optimizer.py                   ← Pillow WebP conversion
    ├── backup.py                      ← UUID-keyed storage + purge_older_than()
    ├── requirements.txt
    └── README.md                      ← Server 2 deployment: SSL, nginx, systemd
```
