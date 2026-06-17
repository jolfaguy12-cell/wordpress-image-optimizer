# Woo Image Optimizer — Server 2 API

FastAPI service that the WordPress plugin calls to convert product images to WebP and store originals for restore.

**Production URL:** `https://imgoptimizer.behdashtik.ir`

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

Create `server2-api/.env` inside the project folder:

```bash
# Required — Bearer token the WordPress plugin sends
# Generate with: python3 -c "import secrets; print(secrets.token_hex(32))"
WOO_IMG_API_KEY=<your-generated-key>

# Optional — backup storage path (default: server2-api/backups/)
# WOO_IMG_BACKUP_DIR=/custom/path/to/backups

# Optional — for development (python main.py). Not used by systemd/uvicorn.
WOO_IMG_HOST=127.0.0.1
WOO_IMG_PORT=7700
```

The app loads this file automatically via python-dotenv at startup. No other files need to be created outside the project folder.

### 3. Verify backups directory

The default backup storage is `server2-api/backups/` — created automatically on first backup. No manual setup needed.

---

## Systemd service

Create `/etc/systemd/system/woo-img-optimizer.service`:

```ini
[Unit]
Description=Woo Image Optimizer API
After=network.target

[Service]
User=www-data
WorkingDirectory=/path/to/wordpress-image-optimizer/server2-api
ExecStart=/path/to/wordpress-image-optimizer/server2-api/.venv/bin/uvicorn \
    main:app \
    --host 127.0.0.1 \
    --port 7700 \
    --workers 2
Restart=on-failure
RestartSec=5

[Install]
WantedBy=multi-user.target
```

The service reads config from `server2-api/.env` automatically via python-dotenv — no `EnvironmentFile` directive needed in the unit.

```bash
systemctl daemon-reload
systemctl enable woo-img-optimizer
systemctl start woo-img-optimizer
```

---

## SSL certificate via Let's Encrypt (certbot)

### Prerequisites

- Domain `imgoptimizer.behdashtik.ir` must resolve to this server's public IP
- Port 80 must be open during certificate issuance

### Issue the certificate

```bash
apt install certbot python3-certbot-nginx   # Debian/Ubuntu
certbot certonly --nginx -d imgoptimizer.behdashtik.ir
```

Certificate paths:
- `/etc/letsencrypt/live/imgoptimizer.behdashtik.ir/fullchain.pem`
- `/etc/letsencrypt/live/imgoptimizer.behdashtik.ir/privkey.pem`

Verify auto-renewal:
```bash
certbot renew --dry-run
```

---

## Nginx configuration

Create `/etc/nginx/sites-available/imgoptimizer.behdashtik.ir`:

```nginx
# Redirect HTTP → HTTPS
server {
    listen 80;
    server_name imgoptimizer.behdashtik.ir;
    return 301 https://$host$request_uri;
}

# HTTPS — proxy to uvicorn on 127.0.0.1:7700
server {
    listen 443 ssl;
    server_name imgoptimizer.behdashtik.ir;

    ssl_certificate     /etc/letsencrypt/live/imgoptimizer.behdashtik.ir/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/imgoptimizer.behdashtik.ir/privkey.pem;
    ssl_protocols       TLSv1.2 TLSv1.3;
    ssl_ciphers         HIGH:!aNULL:!MD5;

    client_max_body_size 20M;

    location / {
        proxy_pass         http://127.0.0.1:7700;
        proxy_set_header   Host            $host;
        proxy_set_header   X-Real-IP       $remote_addr;
        proxy_read_timeout 120s;
        proxy_send_timeout 120s;
    }
}
```

```bash
ln -s /etc/nginx/sites-available/imgoptimizer.behdashtik.ir /etc/nginx/sites-enabled/
nginx -t
systemctl reload nginx
```

---

## WordPress plugin configuration

In **WP Admin → WooCommerce Image Optimizer → Settings**:

| Setting | Value |
|---------|-------|
| Server 2 API URL | `https://imgoptimizer.behdashtik.ir` |
| API Key | Value of `WOO_IMG_API_KEY` from `server2-api/.env` |

---

## Backup retention

Add to crontab on Server 2 to purge backups older than N days:

```bash
# Daily at 03:00
0 3 * * * /path/to/server2-api/.venv/bin/python -c \
    "import backup; n = backup.purge_older_than(30); print(f'Purged {n} backups')"
```

---

## Development / local testing

```bash
cd server2-api/
cp .env.example .env   # if provided, else create manually
source .venv/bin/activate
uvicorn main:app --reload --host 127.0.0.1 --port 7700
```
