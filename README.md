# WooCommerce Image Optimizer

WordPress plugin that converts WooCommerce product images to WebP using a remote processing server. Queue-based and async — zero processing overhead on page loads. No server-side PHP extensions required.

**Version:** 2.3.0 | **Requires:** WordPress 6.0+, WooCommerce 8.0+, PHP 8.1+  
**Production API:** `https://imgoptimizer.behdashtik.ir`

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
│   status: pending → processing → done | failed                          │
│   failed → pending  (Retry Failed button or wp woo-optimizer            │
│                      retry-failed)                                      │
│          │                                                              │
│          ▼  (every 60 seconds, transient lock)                          │
│   class-cron.php → class-processor.php                                 │
│          │                                                              │
│          │  1. POST /backup  (WP_Filesystem read — memory-safe) ──────► │
│          │  2. POST /optimize ────────────────────────────────────────► │
│          │  3. Decode base64 WebP → write to disk                       │
│          │  4. Delete original .jpg/.png + old thumbnail size files     │
│          │  5. class-db-updater.php → update all WP metadata            │
│          │  6. Mark queue row: done                                     │
│                                                                         │
│   Retry: attempts < 3 → back to pending | attempts = 3 → failed        │
└─────────────────────────────────────────────────────────────────────────┘
              │ HTTPS + Bearer token
              ▼
┌─────────────────────────────────────────────────────────────────────────┐
│           SERVER 2 API  (server2-api/)                                  │
│           https://imgoptimizer.behdashtik.ir                            │
│                                                                         │
│   Auth: Authorization: Bearer {WOO_IMG_API_KEY}  (sole layer)          │
│   Config: server2-api/.env  (loaded via python-dotenv)                 │
│   Backups: server2-api/backups/  (default; stays inside project)       │
│                                                                         │
│   POST /backup          Store original file → return backup_key (UUID)  │
│   POST /optimize        Pillow WebP convert → return base64             │
│   GET  /backup/{key}    Download original binary (for restore)          │
│   DELETE /backup/{key}  Remove stored backup                            │
│                                                                         │
│   SSL:  Let's Encrypt via certbot                                       │
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
│   Original .jpg/.png deleted. Backed up permanently on Server 2.        │
└─────────────────────────────────────────────────────────────────────────┘
```

---

## Security

| Layer | Where | Mechanism |
|-------|-------|-----------|
| Auth | FastAPI | `Authorization: Bearer {WOO_IMG_API_KEY}` required on every request |
| Transport | Nginx | TLS 1.2/1.3 via Let's Encrypt certificate for `imgoptimizer.behdashtik.ir` |

---

## Features

| Feature | Details |
|---------|---------|
| **Scope** | WooCommerce product images only (featured image, gallery, variation thumbnails) |
| **Processing** | Remote — all compression on Server 2, zero PHP load on WordPress |
| **Memory-safe upload** | `WP_Filesystem` reads image files — no double-buffering in PHP memory |
| **Queue** | MySQL table, no Redis required |
| **Retry logic** | Up to 3 attempts per image before marking failed |
| **Retry Failed** | One-click reset of all failed jobs (UI button + `wp woo-optimizer retry-failed`) |
| **Restore** | One-click restore from Server 2 backup at any time |
| **Auto-queue on upload** | Queues new product images as soon as they're attached |
| **Bulk enqueue** | Admin UI or CLI enqueues all unoptimized product images at once |
| **Media library column** | Shows savings (KB), status badge, Restore button per image |
| **WP-CLI** | Full CLI for manual processing and scripting |
| **AVIF safe** | AVIF images are always skipped |
| **Self-contained** | All config in `server2-api/.env`; backups in `server2-api/backups/` |
| **No WebP serving layer** | No `.htaccess` rules — all modern browsers support WebP natively |

---

## Requirements

- WordPress 6.0+
- WooCommerce 8.0+
- PHP 8.1+
- Server 2 API running at `https://imgoptimizer.behdashtik.ir`

---

## Installation

### 1. Deploy the WordPress plugin

```bash
cp -r wordpress-image-optimizer /var/www/yoursite/wp-content/plugins/
```

Activate via **WP Admin → Plugins → WooCommerce Image Optimizer**.

The plugin creates `{prefix}woo_optimizer_queue` table and registers the WP-Cron schedule on activation.

### 2. Set up the Server 2 API

See `server2-api/README.md` for the full deployment guide including:
- Python venv + pip install
- `server2-api/.env` configuration (API key, optional backup dir)
- SSL certificate via Let's Encrypt / certbot
- Nginx HTTPS proxy config
- Systemd service unit

### 3. Configure the plugin

**WP Admin → WooCommerce Image Optimizer → Settings:**

| Setting | Value |
|---------|-------|
| API URL | `https://imgoptimizer.behdashtik.ir` |
| API Key | `WOO_IMG_API_KEY` from `server2-api/.env` |
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

# Reset all failed jobs back to pending (attempts = 0)
wp woo-optimizer retry-failed
```

---

## File Structure

```
wordpress-image-optimizer/
├── wordpress-image-optimizer.php   ← bootstrap, constants (WOO_IMG_OPT_*), singleton
├── uninstall.php                   ← drops queue table and option on plugin delete
├── woo-image-optimizer-flow.md     ← full system flow reference document
├── includes/
│   ├── class-settings.php          ← woo_optimizer_settings option
│   ├── class-queue.php             ← MySQL queue CRUD + retry_all_failed()
│   ├── class-api-client.php        ← HTTP client (WP_Filesystem reads, multipart)
│   ├── class-woocommerce.php       ← product image detection + skip rules + auto-queue
│   ├── class-db-updater.php        ← all WP DB writes after optimization
│   ├── class-processor.php         ← job orchestration: backup → optimize → write → update
│   ├── class-cron.php              ← 60s WP-Cron schedule + transient lock
│   ├── class-restore.php           ← restore flow: download → write back → reset meta
│   ├── class-admin.php             ← settings page, queue dashboard, AJAX handlers
│   └── class-cli.php               ← WP-CLI command definitions
├── assets/
│   ├── css/admin.css               ← admin styles (.wio-* stat cards, savings box, grid)
│   └── js/admin.js                 ← bulk optimizer UI + retry-failed handler
└── server2-api/
    ├── .env                        ← WOO_IMG_API_KEY + optional overrides (not committed)
    ├── backups/                    ← original file backups (created at runtime, not committed)
    ├── main.py                     ← FastAPI app: 4 endpoints, loads .env via python-dotenv
    ├── optimizer.py                ← Pillow WebP conversion
    ├── backup.py                   ← UUID-keyed storage, path-traversal safe
    ├── requirements.txt
    └── README.md                   ← SSL, nginx, systemd, .env setup guide
```

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
