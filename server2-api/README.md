# Woo Image Optimizer — Server 2 API

FastAPI service that the WordPress plugin calls to convert product images to WebP and store originals for restore.

## Endpoints

| Method | Path | Purpose |
|--------|------|---------|
| POST | `/optimize` | Convert image → WebP (returns base64) |
| POST | `/backup` | Store original file; returns `backup_key` |
| GET | `/backup/{key}` | Download original binary |
| DELETE | `/backup/{key}` | Delete stored backup |

All requests require `Authorization: Bearer <WOO_IMG_API_KEY>`.

---

## Setup

### 1. Install dependencies

```bash
cd server2-api/
python3 -m venv .venv
source .venv/bin/activate
pip install -r requirements.txt
```

### 2. Configure environment

Create a `.env` file (or export variables in your shell / systemd unit):

```bash
WOO_IMG_API_KEY=<generate with: python3 -c "import secrets; print(secrets.token_hex(32))">
WOO_IMG_BACKUP_DIR=/var/backups/woo-img-optimizer
WOO_IMG_HOST=127.0.0.1
WOO_IMG_PORT=7700
```

### 3. Run

```bash
# Development
source .venv/bin/activate
python main.py

# Production (directly)
source .venv/bin/activate
uvicorn main:app --host 127.0.0.1 --port 7700 --workers 2
```

---

## Systemd service (recommended for production)

Create `/etc/systemd/system/woo-img-optimizer.service`:

```ini
[Unit]
Description=Woo Image Optimizer API
After=network.target

[Service]
User=www-data
WorkingDirectory=/path/to/wordpress-image-optimizer/server2-api
EnvironmentFile=/path/to/wordpress-image-optimizer/server2-api/.env
ExecStart=/path/to/wordpress-image-optimizer/server2-api/.venv/bin/uvicorn main:app --host 127.0.0.1 --port 7700 --workers 2
Restart=on-failure
RestartSec=5

[Install]
WantedBy=multi-user.target
```

Enable and start:
```bash
systemctl daemon-reload
systemctl enable woo-img-optimizer
systemctl start woo-img-optimizer
```

---

## Nginx reverse proxy (optional — expose via HTTPS)

```nginx
location /img-optimizer/ {
    proxy_pass http://127.0.0.1:7700/;
    proxy_set_header Host $host;
    proxy_read_timeout 120s;
    client_max_body_size 20M;
}
```

Then set `WOO_IMG_API_URL = https://yourdomain.com/img-optimizer` in the WordPress plugin settings.

---

## Backup retention

To purge backups older than N days, run as a cron job:

```bash
# In crontab — runs daily at 03:00
0 3 * * * /path/to/server2-api/.venv/bin/python -c "
import os, backup
os.environ['WOO_IMG_BACKUP_DIR'] = '/var/backups/woo-img-optimizer'
n = backup.purge_older_than(30)
print(f'Purged {n} old backups')
"
```

Or call `backup.purge_older_than(days)` from your own management script.

---

## WordPress plugin settings

In WP Admin → Woo Image Optimizer → Settings:

| Setting | Value |
|---------|-------|
| API URL | `http://127.0.0.1:7700` (or your HTTPS proxy URL) |
| API Key | Value of `WOO_IMG_API_KEY` from your `.env` |
