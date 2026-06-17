# WooCommerce Image Optimizer

WordPress plugin that converts WooCommerce product images to WebP using a remote processing server. Queue-based and async — zero processing overhead on page loads. No server-side PHP extensions required.

**Version:** 2.0.0 | **Requires:** WordPress 6.0+, WooCommerce 8.0+, PHP 8.1+

---

## Architecture

```
┌─────────────────────────────────────────────────────────────────────────┐
│                         WORDPRESS (Server 1)                            │
│                                                                         │
│   Image upload / product save                                           │
│          │                                                              │
│          ▼                                                              │
│   class-woocommerce.php                                                 │
│   • hooks: updated_post_meta(_thumbnail_id, _product_image_gallery)     │
│   • skip rules: AVIF, already-done, non-product images                  │
│          │                                                              │
│          ▼                                                              │
│   {prefix}woo_optimizer_queue   ← MySQL queue table                    │
│   ┌────────────┬───────────┬──────────┬──────────┬──────────────────┐  │
│   │ attachment │ product   │ status   │ attempts │ error_msg        │  │
│   │ _id        │ _id       │ pending  │ 0–3      │ null             │  │
│   └────────────┴───────────┴──────────┴──────────┴──────────────────┘  │
│          │                                                              │
│          ▼  (every 60 seconds, transient lock)                          │
│   class-cron.php → class-processor.php                                 │
│          │                                                              │
│          │  1. POST /backup  ─────────────────────────────────────────► │
│          │  2. POST /optimize ────────────────────────────────────────► │
│          │  3. Decode base64 WebP → write to disk                       │
│          │  4. Delete original .jpg/.png                                │
│          │  5. class-db-updater.php → update all WP metadata            │
│          │  6. Mark queue row: done                                     │
│                                                                         │
│   Retry: attempts < 3 → back to pending | attempts = 3 → failed        │
└─────────────────────────────────────────────────────────────────────────┘
                    │ HTTP (Bearer token)
                    ▼
┌─────────────────────────────────────────────────────────────────────────┐
│                    SERVER 2 API  (server2-api/)                         │
│                                                                         │
│   POST /backup          Store original file → return backup_key (UUID)  │
│   POST /optimize        Convert to WebP (Pillow) → return base64        │
│   GET  /backup/{key}    Download original binary (for restore)          │
│   DELETE /backup/{key}  Remove stored backup                            │
│                                                                         │
│   Auth: Authorization: Bearer {WOO_IMG_API_KEY}                        │
│   Storage: configurable directory, UUID-keyed, path-traversal safe      │
└─────────────────────────────────────────────────────────────────────────┘
                    │
                    ▼
┌─────────────────────────────────────────────────────────────────────────┐
│                         FILE SYSTEM (Server 1)                          │
│                                                                         │
│   wp-content/uploads/2026/06/                                           │
│   ├── product.webp             ← replaces original                      │
│   ├── product-300x300.webp     ← regenerated thumbnail                  │
│   └── product-600x600.webp                                              │
│                                                                         │
│   Original .jpg/.png deleted after successful optimization.             │
│   Originals backed up permanently on Server 2 (restorable any time).   │
└─────────────────────────────────────────────────────────────────────────┘
```

---

## How It Works

### On Image Upload or Product Save (automatic)

1. `_thumbnail_id` or `_product_image_gallery` postmeta is updated
2. `class-woocommerce.php` checks skip rules (AVIF, already done, non-product)
3. Attachment is added to the MySQL queue as `pending`

### Queue Processing (WP-Cron, every 60 seconds)

1. Cron tick acquires a 25-second transient lock (prevents overlapping runs)
2. Batch of `batch_size` pending jobs is picked up
3. For each job:
   - Original file POSTed to `/backup` → `backup_key` stored in postmeta immediately (retry-safe)
   - Original file POSTed to `/optimize` → base64 WebP returned
   - WebP decoded and written to disk alongside original
   - Original `.jpg`/`.png` deleted
   - All WordPress metadata updated: `_wp_attached_file`, `post_mime_type`, attachment sizes
   - Old `.jpg`/`.png` thumbnail size files deleted
   - Queue row marked `done`

### Restore Flow

1. Admin clicks Restore (media library column) or runs `wp woo-optimizer restore <id>`
2. Plugin calls `GET /backup/{key}` → downloads original binary
3. Original written back to filesystem; WebP and WebP thumbnails deleted
4. All WordPress metadata reset to original values; `_woo_optimizer_*` postmeta cleared
5. Queue row deleted

---

## Skip Rules

An attachment is never queued if:
- MIME type is `image/avif` or file extension is `.avif`
- `_woo_optimizer_status = done` already set on the attachment
- Not referenced by any WooCommerce product (`_thumbnail_id` on product/variation, or `_product_image_gallery`)

---

## Features

| Feature | Details |
|---------|---------|
| **Scope** | WooCommerce product images only (featured image, gallery, variation thumbnails) |
| **Processing** | Remote — all compression on Server 2, zero PHP load on WordPress |
| **Queue** | MySQL table, no Redis required |
| **Retry logic** | Up to 3 attempts per image before marking failed |
| **Restore** | One-click restore from Server 2 backup at any time |
| **Auto-queue on upload** | Queues new product images as soon as they're attached |
| **Bulk enqueue** | Admin UI or CLI enqueues all unoptimized product images at once |
| **Media library column** | Shows savings (KB), status badge, Restore button per image |
| **WP-CLI** | Full CLI for manual processing and scripting |
| **AVIF safe** | AVIF images are always skipped |

---

## Requirements

- WordPress 6.0+
- WooCommerce 8.0+
- PHP 8.1+
- MySQL queue table (created automatically on plugin activation)
- Server 2 API running and reachable (see `server2-api/`)

---

## Installation

### 1. Deploy the WordPress plugin

```bash
cp -r wordpress-image-optimizer /var/www/yoursite/wp-content/plugins/
```

Activate via **WP Admin → Plugins → WooCommerce Image Optimizer**.

On activation the plugin creates the `{prefix}woo_optimizer_queue` table and registers the WP-Cron schedule.

### 2. Set up the Server 2 API

```bash
cd server2-api/
python3 -m venv .venv
source .venv/bin/activate
pip install -r requirements.txt

# Generate an API key
python3 -c "import secrets; print(secrets.token_hex(32))"

# Start (replace YOUR_KEY)
WOO_IMG_API_KEY=YOUR_KEY uvicorn main:app --host 127.0.0.1 --port 7700
```

See `server2-api/README.md` for systemd and nginx setup.

### 3. Configure the plugin

**WP Admin → WooCommerce Image Optimizer → Settings:**

| Setting | Value |
|---------|-------|
| API URL | `http://127.0.0.1:7700` (or HTTPS proxy URL) |
| API Key | Value of `WOO_IMG_API_KEY` |
| WebP Quality | 82 (default) |
| Max Dimensions | 2048 × 2048 |
| Batch Size | 5 |
| Auto-optimize | Enabled |

---

## WP-CLI Commands

```bash
# Process the next batch of pending queue jobs
wp woo-optimizer run

# Show queue health (total / done / pending / failed / saved)
wp woo-optimizer stats

# Enqueue all unoptimized WooCommerce product images
wp woo-optimizer queue-all

# Preview what would be enqueued (no DB writes)
wp woo-optimizer queue-all --dry-run

# Restore a single attachment from Server 2 backup
wp woo-optimizer restore <attachment_id>
```

---

## File Structure

```
wordpress-image-optimizer/
├── wordpress-image-optimizer.php   ← bootstrap, constants (WOO_IMG_OPT_*), singleton
├── uninstall.php                   ← drops queue table and option on plugin delete
├── includes/
│   ├── class-settings.php          ← woo_optimizer_settings option storage
│   ├── class-queue.php             ← MySQL queue CRUD (enqueue, dequeue, mark done/failed)
│   ├── class-api-client.php        ← HTTP client for all 4 Server 2 endpoints
│   ├── class-woocommerce.php       ← product image detection + skip rules + auto-queue hook
│   ├── class-db-updater.php        ← all WP DB writes after optimization
│   ├── class-processor.php         ← job orchestration: backup → optimize → write → update
│   ├── class-cron.php              ← 60s WP-Cron schedule + transient lock
│   ├── class-restore.php           ← restore flow: download → write back → reset meta
│   ├── class-admin.php             ← settings page, queue dashboard, AJAX handlers
│   └── class-cli.php               ← WP-CLI command definitions
├── assets/
│   ├── css/admin.css               ← admin styles (.wio-* classes)
│   └── js/admin.js                 ← bulk optimizer UI (queue-all → batch loop)
└── server2-api/
    ├── main.py                     ← FastAPI app: /optimize, /backup, GET/DELETE /backup/{key}
    ├── optimizer.py                ← Pillow WebP conversion (RGB/RGBA, downscale-only)
    ├── backup.py                   ← UUID-keyed file storage with path-traversal protection
    ├── requirements.txt
    └── README.md                   ← Server 2 deployment guide (systemd, nginx, retention)
```

---

## Postmeta Written Per Attachment

| Key | Value |
|-----|-------|
| `_woo_optimizer_status` | `done` |
| `_woo_optimizer_backup_key` | UUID from Server 2 `/backup` |
| `_woo_optimizer_original_file` | Original relative path (e.g. `2026/06/photo.jpg`) |
| `_woo_optimizer_original_mime` | Original MIME type (e.g. `image/jpeg`) |
| `_woo_optimizer_original_size` | Original file size in bytes |
| `_woo_optimizer_optimized_size` | WebP file size in bytes |
| `_woo_optimizer_saved_bytes` | Bytes saved |

---

## Queue Table Schema

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

---

## License

GPL-2.0+
