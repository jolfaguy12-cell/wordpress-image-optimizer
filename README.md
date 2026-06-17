# WordPress Image Optimizer

Lightweight, self-hosted WordPress plugin for image compression and WebP conversion. No external API, no subscription, no file size limits. Works entirely on your server using PHP's built-in Imagick and GD libraries.

---

## Architecture

```
┌─────────────────────────────────────────────────────────────────────────┐
│                        ENTRY POINTS                                     │
│                                                                         │
│   ┌──────────────┐   ┌──────────────┐   ┌──────────────────────────┐   │
│   │  Image Upload │   │  Admin UI    │   │  WP-CLI                  │   │
│   │  (wp-admin)  │   │  Bulk Button │   │  wp bdsk-optimizer bulk  │   │
│   └──────┬───────┘   └──────┬───────┘   └────────────┬─────────────┘   │
│          │                  │                         │                 │
└──────────┼──────────────────┼─────────────────────────┼─────────────────┘
           │                  │                         │
           ▼                  ▼                         ▼
┌─────────────────────────────────────────────────────────────────────────┐
│                        PLUGIN CORE                                      │
│                                                                         │
│  ┌──────────────────────────────────────────────────────────────────┐   │
│  │  class-bulk.php                                                  │   │
│  │                                                                  │   │
│  │  • wp_generate_attachment_metadata  →  auto_optimize()           │   │
│  │  • wp_ajax_bdsk_optimizer_bulk      →  ajax_bulk()               │   │
│  │  • wp_ajax_bdsk_optimizer_single    →  ajax_single()             │   │
│  │  • wp_ajax_bdsk_optimizer_restore   →  ajax_restore()            │   │
│  └────────────────────────────┬─────────────────────────────────────┘   │
│                               │                                         │
│                               ▼                                         │
│  ┌──────────────────────────────────────────────────────────────────┐   │
│  │  class-engine.php  (core optimizer)                              │   │
│  │                                                                  │   │
│  │  optimize_attachment( $id )                                      │   │
│  │       │                                                          │   │
│  │       ├── 1. Validate file + MIME type                           │   │
│  │       ├── 2. Backup original  ──────────────────────────────┐    │   │
│  │       ├── 3. optimize_file( original )                      │    │   │
│  │       │       └── optimize_file( each thumbnail size )      │    │   │
│  │       └── 4. Save results to postmeta (_bdsk_optimizer)     │    │   │
│  │                                                             │    │   │
│  │  ┌──────────────────────────┐  ┌─────────────────────────┐ │    │   │
│  │  │  process_imagick()       │  │  process_gd()  fallback │ │    │   │
│  │  │  (primary engine)        │  │                         │ │    │   │
│  │  │                          │  │  • imagecreatefromjpeg  │ │    │   │
│  │  │  • autoOrient()          │  │  • imagecreatefrompng   │ │    │   │
│  │  │  • stripImage() (EXIF)   │  │  • imagejpeg( quality ) │ │    │   │
│  │  │  • thumbnailImage()      │  │  • imagepng( compress ) │ │    │   │
│  │  │  • setCompression()      │  │  • imagewebp()          │ │    │   │
│  │  │  • setInterlace (prog.)  │  │  • imagedestroy()       │ │    │   │
│  │  │  • writeImage()          │  └─────────────────────────┘ │    │   │
│  │  │  • clone → WebP          │                               │    │   │
│  │  └──────────────────────────┘                               │    │   │
│  └─────────────────────────────────────────────────────────────┼────┘   │
│                                                                │        │
└────────────────────────────────────────────────────────────────┼────────┘
                                                                 │
           ┌─────────────────────────────────────────────────────┘
           │
           ▼
┌─────────────────────────────────────────────────────────────────────────┐
│                        FILE SYSTEM OUTPUT                               │
│                                                                         │
│  wp-content/uploads/2026/06/                                            │
│  ├── product.jpg              ← compressed original (EXIF stripped)     │
│  ├── product.webp             ← WebP version (50–70% smaller)           │
│  ├── product-300x300.jpg      ← thumbnail compressed                    │
│  ├── product-300x300.webp     ← thumbnail WebP                          │
│  ├── product-600x600.jpg                                                │
│  ├── product-600x600.webp                                               │
│  └── ... (all registered sizes)                                         │
│                                                                         │
│  wp-content/uploads/bdsk-optimizer-backups/                             │
│  └── 5968_product.jpg         ← original backup (restorable)           │
└─────────────────────────────────────────────────────────────────────────┘
           │
           ▼
┌─────────────────────────────────────────────────────────────────────────┐
│                        SERVING LAYER                                    │
│                                                                         │
│  Browser requests: <img src="product.jpg">                              │
│                                                                         │
│  class-webp.php intercepts WordPress image filters:                     │
│                                                                         │
│  ┌────────────────────────────────────────────────────────────────┐     │
│  │  Does browser send "Accept: image/webp"?                       │     │
│  │                                                                │     │
│  │  YES → Does product.webp exist on disk?                        │     │
│  │          YES → serve product.webp  (50–70% smaller)            │     │
│  │          NO  → serve product.jpg   (compressed)                │     │
│  │                                                                │     │
│  │  NO  → serve product.jpg  (compressed original)               │     │
│  └────────────────────────────────────────────────────────────────┘     │
│                                                                         │
│  Filters hooked:                                                        │
│  • wp_get_attachment_image_src    (single image)                        │
│  • wp_get_attachment_url          (direct URL)                          │
│  • wp_calculate_image_srcset      (responsive srcset)                   │
└─────────────────────────────────────────────────────────────────────────┘
```

---

## How It Works

### On Image Upload (automatic)

1. WordPress uploads the image and generates thumbnails
2. The `wp_generate_attachment_metadata` hook fires
3. The plugin reads your settings (quality, max dimensions, WebP on/off)
4. The engine backs up the original file
5. Imagick (or GD if Imagick is unavailable) compresses the original and every thumbnail
6. A `.webp` copy is generated for each file
7. Results (bytes saved, engine used, timestamp) are stored in the attachment's postmeta

### On Bulk Optimization

The bulk optimizer works through small AJAX batches to avoid PHP timeouts:

```
Browser                    WordPress (AJAX)              Engine
   │                            │                           │
   │── click "Optimize" ──────►│                           │
   │                            │── get 5 unoptimized IDs  │
   │                            │── optimize each ────────►│
   │                            │◄─ results ───────────────│
   │◄─ progress update ─────────│                           │
   │                            │                           │
   │── (200ms delay) ──────────►│                           │
   │                            │── next batch ────────────►│
   │                            │◄─ results ────────────────│
   │◄─ progress update ─────────│                           │
   │                            │                           │
   │   ... repeats until done ...                           │
   │                            │                           │
   │◄─ "All images optimized!" ─│                           │
```

### On Page Load (WebP serving)

```
Visitor browser                WordPress PHP              Disk
      │                             │                      │
      │── GET /product-page ───────►│                      │
      │                             │── build image URLs   │
      │                             │── check Accept header│
      │                             │   "image/webp" ✓     │
      │                             │── check .webp exists ►│
      │                             │◄─ YES ───────────────│
      │◄── HTML with .webp URLs ────│                      │
      │                             │                      │
      │── GET product.webp ────────────────────────────────►
      │◄── 174KB instead of 574KB ──────────────────────────
```

---

## Features

| Feature | Details |
|---------|---------|
| **Compression engine** | Imagick (primary) with GD fallback |
| **Formats** | JPEG, PNG, GIF |
| **WebP conversion** | Generates `.webp` alongside original for every size |
| **WebP serving** | PHP filter swaps URLs for supporting browsers automatically |
| **Thumbnail optimization** | Compresses all WordPress-registered sizes (thumbnail, medium, large, WooCommerce sizes, etc.) |
| **Auto-optimize on upload** | Optional — fires on `wp_generate_attachment_metadata` |
| **Bulk optimizer** | AJAX batch processing with live progress bar, pause/resume |
| **Backup & restore** | Originals saved to `bdsk-optimizer-backups/`, restorable per-image |
| **EXIF stripping** | Removes metadata (camera model, GPS, etc.) to reduce file size |
| **Resize on upload** | Optional max width/height limit applied before compression |
| **Compression modes** | Lossy (default) / Lossless / Ultra-Lossy |
| **Media library column** | Shows per-image savings and one-click optimize button |
| **WP-CLI** | Full CLI support for server-side bulk processing |
| **No external API** | Runs entirely on your server — no account, no limits, no cost |

---

## Compression Modes

| Mode | JPEG Quality | Best For |
|------|-------------|----------|
| **Lossy** (default) | 82 | Best balance — visually identical to original, 10–30% smaller |
| **Ultra-Lossy** | ~62 | Maximum size reduction — slight quality drop visible on close inspection |
| **Lossless** | 100 | No quality loss — files may be larger than original, use for product images where accuracy matters |

WebP quality is set separately (default 80). WebP at 80 is visually equivalent to JPEG at ~88.

---

## Requirements

- WordPress 6.0+
- PHP 8.1+
- **Imagick** PHP extension (recommended) OR **GD** with WebP support
- Write permission to `wp-content/uploads/`

To check what's available on your server:

```bash
php -r "echo class_exists('Imagick') ? 'Imagick: YES' : 'Imagick: NO'; echo PHP_EOL; echo function_exists('imagewebp') ? 'GD WebP: YES' : 'GD WebP: NO';"
```

---

## Installation

1. Upload the `wordpress-image-optimizer` folder to `wp-content/plugins/`
2. Activate via **WP Admin → Plugins**
3. Go to **Media → WordPress Image Optimizer**
4. Click **"Optimize X Remaining Images"** to bulk-process existing images

New images uploaded after activation are optimized automatically.

---

## WP-CLI Commands

```bash
# Show stats
wp bdsk-optimizer stats

# Bulk optimize all remaining images
wp bdsk-optimizer bulk

# Bulk optimize with custom batch size
wp bdsk-optimizer bulk --batch=10

# Preview what would be processed (no changes)
wp bdsk-optimizer bulk --dry-run

# Optimize a single image by attachment ID
wp bdsk-optimizer single 1234

# Restore a single image from backup
wp bdsk-optimizer restore 1234

# Run stress test and measure throughput
wp bdsk-optimizer stress-test --images=100 --batch=5
```

---

## File Structure

```
wordpress-image-optimizer/
├── wordpress-image-optimizer.php   ← plugin bootstrap, constants, loader
├── includes/
│   ├── class-settings.php          ← settings storage and validation
│   ├── class-engine.php            ← core compression engine (Imagick + GD)
│   ├── class-admin.php             ← admin UI, settings page, media column
│   ├── class-bulk.php              ← AJAX bulk processor + auto-upload hook
│   ├── class-webp.php              ← WebP URL rewriting filters
│   └── class-cli.php               ← WP-CLI command definitions
└── assets/
    ├── css/admin.css               ← admin page styles
    └── js/admin.js                 ← bulk optimizer UI (progress bar, log)
```

---

## Settings Reference

| Setting | Default | Description |
|---------|---------|-------------|
| Compression Mode | Lossy | Lossy / Lossless / Ultra-Lossy |
| JPEG Quality | 82 | 1–100. Below 70 shows visible degradation |
| WebP Quality | 80 | 1–100. Separate from JPEG quality |
| PNG Compression | 6 | 0–9 (lossless algorithm, higher = smaller file, slower) |
| Max Width / Height | 2048px | Images larger than this are resized before compression. Set 0 to disable |
| Batch Size | 5 | Images per AJAX request during bulk. Lower if you hit PHP timeouts |
| Generate WebP | On | Create `.webp` alongside every JPEG/PNG |
| Serve WebP | On | Swap image URLs to `.webp` for supporting browsers |
| Auto-optimize on upload | On | Compress automatically when new images are uploaded |
| Optimize thumbnails | On | Also compress all WordPress thumbnail sizes |
| Backup originals | On | Save original file before compressing (enables restore) |
| Strip metadata | On | Remove EXIF/IPTC data (GPS, camera info, copyright) |
| Excluded paths | — | Path fragments to skip (one per line) |

---

## Performance Benchmarks

Tested on Ubuntu 24.04, PHP 8.3, Imagick 6.9.12, batch size 5:

| Metric | Result |
|--------|--------|
| Throughput | ~2.9 images/sec |
| Average batch time | ~1,700ms per 5 images |
| Peak memory | 214 MB (stable, no growth) |
| Errors on 50-image test | 0 |
| WebP vs original JPEG | 50–70% smaller |
| JPEG savings (lossy 82) | 10–30% |

---

## Backup & Restore

Originals are saved to:
```
wp-content/uploads/bdsk-optimizer-backups/{attachment_id}_{filename}
```

This directory is protected from direct web access via `.htaccess`.

To restore an image from the Media Library: click **Restore** on the image detail page.
To restore via CLI: `wp bdsk-optimizer restore {id}`

---

## License

GPL-2.0+
