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

## Security model

Two independent layers block unauthorized access:

**Layer 1 — Nginx** (network level): Only the WordPress server IP is allowed. All other source IPs receive `403 Forbidden` before the request reaches uvicorn.

**Layer 2 — FastAPI middleware** (application level): Even if a request slips through nginx, the `IPAllowlistMiddleware` checks the originating IP against `WOO_IMG_ALLOWED_IP` and returns `403` before the auth check runs.

Both layers must have the same WordPress server IP configured.

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

Create `/etc/woo-img-optimizer.env` (kept outside the repo):

```bash
# Required
WOO_IMG_API_KEY=<generate: python3 -c "import secrets; print(secrets.token_hex(32))">

# Security: WordPress (Server 1) outbound IP — all other IPs get 403
# Find Server 1's IP with: curl -s ifconfig.me   (run on the WordPress server)
WOO_IMG_ALLOWED_IP=YOUR_SERVER1_IP

# Storage
WOO_IMG_BACKUP_DIR=/var/backups/woo-img-optimizer

# Bind — 0.0.0.0 lets nginx proxy reach uvicorn; nginx handles external access
WOO_IMG_HOST=127.0.0.1
WOO_IMG_PORT=7700
```

> **Note:** Set `WOO_IMG_HOST=127.0.0.1` when nginx is on the same machine (recommended).
> Set `WOO_IMG_HOST=0.0.0.0` only if uvicorn must be reachable directly across servers.

### 3. Create backup storage directory

```bash
mkdir -p /var/backups/woo-img-optimizer
chmod 700 /var/backups/woo-img-optimizer
```

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
EnvironmentFile=/etc/woo-img-optimizer.env
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

```bash
systemctl daemon-reload
systemctl enable woo-img-optimizer
systemctl start woo-img-optimizer
```

---

## SSL certificate via Let's Encrypt (certbot)

### Prerequisites

- Domain `imgoptimizer.behdashtik.ir` must point to this server's public IP (A record)
- Port 80 must be open temporarily during certificate issuance

### Issue the certificate

```bash
apt install certbot python3-certbot-nginx   # Debian/Ubuntu
certbot certonly --nginx -d imgoptimizer.behdashtik.ir
```

Certbot places the certificate at:
- `/etc/letsencrypt/live/imgoptimizer.behdashtik.ir/fullchain.pem`
- `/etc/letsencrypt/live/imgoptimizer.behdashtik.ir/privkey.pem`

Auto-renewal is set up by certbot automatically. Verify with:
```bash
certbot renew --dry-run
```

---

## Nginx configuration

Create `/etc/nginx/sites-available/woo-img-optimizer`:

```nginx
# -----------------------------------------------------------------------
# Redirect HTTP → HTTPS
# -----------------------------------------------------------------------
server {
    listen 80;
    server_name imgoptimizer.behdashtik.ir;
    return 301 https://$host$request_uri;
}

# -----------------------------------------------------------------------
# HTTPS — proxy to uvicorn on 127.0.0.1:7700
# -----------------------------------------------------------------------
server {
    listen 443 ssl;
    server_name imgoptimizer.behdashtik.ir;

    ssl_certificate     /etc/letsencrypt/live/imgoptimizer.behdashtik.ir/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/imgoptimizer.behdashtik.ir/privkey.pem;
    ssl_protocols       TLSv1.2 TLSv1.3;
    ssl_ciphers         HIGH:!aNULL:!MD5;

    # -----------------------------------------------------------------------
    # Layer 1 IP restriction — ONLY allow requests from Server 1 (WordPress).
    # Replace YOUR_SERVER1_IP with the actual public IP of the WordPress server.
    # Run `curl -s ifconfig.me` on the WordPress server to find it.
    # -----------------------------------------------------------------------
    allow YOUR_SERVER1_IP;
    deny  all;

    client_max_body_size 20M;

    location / {
        proxy_pass         http://127.0.0.1:7700;
        proxy_set_header   Host              $host;
        proxy_set_header   X-Real-IP         $remote_addr;
        # Pass the real client IP so the FastAPI middleware can verify it.
        proxy_set_header   X-Forwarded-For   $remote_addr;
        proxy_read_timeout 120s;
        proxy_send_timeout 120s;
    }
}
```

Enable and reload:
```bash
ln -s /etc/nginx/sites-available/woo-img-optimizer /etc/nginx/sites-enabled/
nginx -t
systemctl reload nginx
```

---

## WordPress plugin configuration

In **WP Admin → WooCommerce Image Optimizer → Settings**:

| Setting | Value |
|---------|-------|
| Server 2 API URL | `https://imgoptimizer.behdashtik.ir` |
| API Key | Value of `WOO_IMG_API_KEY` from the env file |
| This Server's Outbound IP | Your WordPress server's public IP (`curl -s ifconfig.me`) |

The outbound IP entered here is sent to Server 2 in `WOO_IMG_ALLOWED_IP` and is also shown in the settings for your reference — it is **not** transmitted in API requests.

---

## Backup retention

To purge backups older than N days, add to crontab on Server 2:

```bash
# Daily at 03:00
0 3 * * * WOO_IMG_BACKUP_DIR=/var/backups/woo-img-optimizer \
    /path/to/server2-api/.venv/bin/python -c \
    "import backup; n = backup.purge_older_than(30); print(f'Purged {n} backups')"
```

---

## Development / local testing

```bash
# Start without IP restriction (useful for local testing)
WOO_IMG_API_KEY=testkey \
WOO_IMG_HOST=127.0.0.1 \
WOO_IMG_PORT=7700 \
uvicorn main:app --reload
```

Set `WOO_IMG_ALLOWED_IP` only in production where you know Server 1's fixed IP.
