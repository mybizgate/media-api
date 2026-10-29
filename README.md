# MyBizGate Media API

## Purpose

Shared Laravel 13 service that stores every tenant's uploads for the MyBizGate platform: image validation, WebP optimisation, thumbnails, safe document uploads, metadata, preview, download, replace, delete and listing. Business data stays in `client-api`; tenant frontends call both.

```text
Tenant frontend ──► client-api  (tenants, products, orders ...)
                └─► media-api   (files and images)
```

Storage is plain directories plus a `metadata.json` beside each file, isolated per tenant and never inside the web root. No database is used.

```text
tenants/{tenant}/images/{module}/YYYY/MM/{media-id}/image.webp | thumb.webp | metadata.json
tenants/{tenant}/files/{module}/YYYY/MM/{media-id}/file.ext   | metadata.json
```

## Requirements

- PHP 8.3 with `gd` (WebP support), `fileinfo`, `mbstring`
- Composer 2
- A writable media directory outside the application and outside `public_html`

## Local Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
```

## Local Storage Directory

```bash
mkdir -p /home/jul/Workspace/mybizgate/uploads/media-api/tenants
```

`.env` points `MEDIA_STORAGE_PATH` at `/home/jul/Workspace/mybizgate/uploads/media-api`.

## Run

```bash
php artisan serve --port=8001        # client-api uses 8000, media-api uses 8001
php artisan test                     # uses a faked disk, never the real uploads directory
php artisan route:list
```

The PHP CLI defaults (`upload_max_filesize=2M`) are lower than the API limits. For large local uploads run the built-in server with raised limits:

```bash
php -d upload_max_filesize=26M -d post_max_size=28M -S 127.0.0.1:8001 -t public
```

## Environment

```env
APP_NAME="MyBizGate Media API"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://127.0.0.1:8001

SESSION_DRIVER=file
CACHE_STORE=file
QUEUE_CONNECTION=sync

MEDIA_STORAGE_PATH=/home/jul/Workspace/mybizgate/uploads/media-api

MEDIA_IMAGE_MAX_WIDTH=1600
MEDIA_IMAGE_MAX_HEIGHT=1600
MEDIA_IMAGE_WEBP_QUALITY=80
MEDIA_THUMB_MAX_WIDTH=400
MEDIA_THUMB_MAX_HEIGHT=400
MEDIA_THUMB_WEBP_QUALITY=75
MEDIA_IMAGE_MAX_SIZE_KB=10240
MEDIA_FILE_MAX_SIZE_KB=25600
MEDIA_CORS_ORIGINS=              # optional extra origins, comma separated
```

Production:

```env
APP_NAME="MyBizGate Media API"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://media-api.mybizgate.com

SESSION_DRIVER=file
CACHE_STORE=file
QUEUE_CONNECTION=sync

MEDIA_STORAGE_PATH=/home/mybizgat/data/media-api/uploads
```

## Main API Endpoints

Base URL: `/api/v1`. Tenant identification is the `X-Client-Code` header (`^[a-z0-9-]{2,50}$`); it is required for listing, upload, replace and delete, and for reading `private` media. Public media can be viewed by URL alone.

| Method | Path | Purpose |
| --- | --- | --- |
| GET | `/health` | service, storage, GD and WebP status |
| GET | `/media?module=&entity_id=&media_type=&year=&month=&page=&per_page=` | list the tenant's media |
| POST | `/media` | upload (`file`, `module`, `entity_id`, `visibility`) |
| GET | `/media/{id}` | image / PDF preview (`Cache-Control: public, max-age=86400`), other files download |
| GET | `/media/{id}/thumbnail` | 400 px WebP thumbnail (images only) |
| GET | `/media/{id}/metadata` | JSON metadata and URLs |
| GET | `/media/{id}/download` | `Content-Disposition: attachment` with the original filename |
| PUT | `/media/{id}` | replace content, keep the id (POST with `_method=PUT` also works) |
| DELETE | `/media/{id}` | remove files, metadata and the media directory |

Images (JPEG, PNG, WebP; AVIF when GD supports it) are resized to fit 1600x1600, converted to WebP and the original is discarded. Documents (PDF, TXT, CSV, DOC, DOCX, XLS, XLSX) keep their extension; executables, scripts, HTML, SVG and archives are rejected after server-side MIME detection.

```bash
curl -X POST -H "Accept: application/json" -H "X-Client-Code: lzhub" \
  -F "file=@product.png" -F "module=products" -F "entity_id=PRD001" -F "visibility=public" \
  http://127.0.0.1:8001/api/v1/media
```

Responses use `{"success": true, "data": {...}}`; errors use `{"success": false, "message": "...", "errors": {...}}`. Responses only contain API URLs, never filesystem paths.

## Frontend Integration

```env
VITE_API_BASE_URL=https://client-api.mybizgate.com/api/v1
VITE_MEDIA_API_BASE_URL=https://media-api.mybizgate.com/api/v1
```

```ts
const MEDIA_API_BASE_URL = import.meta.env.VITE_MEDIA_API_BASE_URL
fetch(`${MEDIA_API_BASE_URL}/media`, { method: 'POST', headers: { 'X-Client-Code': 'lzhub' }, body: formData })
```

CORS allows `http://localhost:5173`, `https://lzhub.mybizgate.com` and any `https://*.mybizgate.com` origin with the `Accept`, `Content-Type` and `X-Client-Code` headers (`config/cors.php`).

## Production Structure

```text
/home/mybizgat/
├── app/media-api/                  Laravel application (this repository, composer install --no-dev)
├── data/media-api/uploads/         MEDIA_STORAGE_PATH
│   └── tenants/
└── public_html/media-api/          document root of media-api.mybizgate.com
    ├── index.php                   from deploy/public_html/index.php (loads ../../app/media-api)
    ├── .htaccess                   from deploy/public_html/.htaccess
    └── .user.ini                   PHP upload limits (deploy/public_html/.user.ini)
```

Only the files in `deploy/public_html/` go into the document root; the application never lives inside `public_html`.

## Production Deployment

```text
Production branch:       deploy            (main = development; never auto-deployed)
Push:                    git push origin deploy
GitHub Actions:          .github/workflows/deploy.yml
Production application:  /home/mybizgat/app/media-api
Public entry:            /home/mybizgat/public_html/media-api   (not touched by the workflow)
Persistent media:        /home/mybizgat/data/media-api/uploads   (not touched by the workflow)
Production health:       https://media-api.mybizgate.com/api/v1/health
```

The workflow installs PHP 8.3 + Composer, runs `php artisan test`, builds `vendor/` with `composer install --no-dev`,
packages the application into `release/` (no `.env`, no tests, no runtime caches) and uploads it over FTPS with
`SamKirkland/FTP-Deploy-Action` (`dangerous-clean-slate: false`, so nothing unknown on the server is deleted).
Composer is never run on the hosting account.

**`.env` is server-managed and is never deployed.** `/home/mybizgat/app/media-api/.env` (with `APP_KEY`,
`APP_ENV=production`, `MEDIA_STORAGE_PATH`) survives every deployment, as do `storage/logs/`,
`storage/framework/*` and `bootstrap/cache/config.php`.

Deployment identity: CI writes `bootstrap/deployment.json` (`commit`, `deployed_at`; no secrets) and the health
endpoint returns `"deployment": {"commit": "<8-char sha>", "deployed_at": "..."}` (`null` locally). The last
workflow step fails unless the live endpoint reports the commit that was just pushed and still reports
`"environment": "production"`. Set the repository variable `SMOKE_MEDIA_ID` to a public media id to also verify
that existing preview / thumbnail / metadata / download URLs keep working after each deploy.

GitHub secrets (repository or `production` environment):

| Secret | Value |
| --- | --- |
| `FTP_SERVER` | cPanel host (`baboon.awedns.com`) |
| `FTP_USERNAME` | dedicated cPanel FTP account `deploy-media-api@mybizgate.com`, home directory `/home/mybizgat/app/media-api` |
| `FTP_PASSWORD` | that account's password |

Because the FTP account is rooted at the application directory, the workflow uses `server-dir: ./`. If a differently
rooted credential is ever used, change `server-dir` so that the final path is still `/home/mybizgat/app/media-api/`;
never point it at `public_html/`.

Release flow:

```bash
git checkout main && git pull           # develop and commit on main
php artisan test
git checkout deploy && git merge --ff-only main
git push origin deploy                  # triggers the workflow
git checkout main
```

## Required Production Directories

```bash
mkdir -p /home/mybizgat/app/media-api
mkdir -p /home/mybizgat/data/media-api/uploads/tenants
mkdir -p /home/mybizgat/public_html/media-api
chmod -R u+rwX,go-rwx /home/mybizgat/data/media-api
```

First-time setup only: create `/home/mybizgat/app/media-api/.env` with the production values above (generate `APP_KEY` with `php artisan key:generate --show` locally) and make `storage/` and `bootstrap/cache/` writable. Code and `vendor/` then arrive through the deployment workflow below. Avoid `config:cache` / `route:cache` on the server: the cache files are deliberately left alone by deployments and would go stale.

## Backup

Back up the storage root; it contains optimised media, thumbnails, documents and every `metadata.json`:

```bash
tar -czf media-api-uploads.tgz -C /home/mybizgat/data/media-api uploads
tar -czf media-lzhub.tgz -C /home/mybizgat/data/media-api/uploads/tenants lzhub   # one tenant
```

No database backup is required.

## Restore / Server Migration

1. Deploy the Laravel application and the public entry files.
2. Create the media storage root.
3. Copy the `uploads` directory (or a single `tenants/<code>` directory) into it.
4. Set `MEDIA_STORAGE_PATH` in `.env` to the new root; no code change is needed.
5. Point the domain's document root at the public entry files.
6. Verify the storage root is writable by the PHP user.
7. Call `GET /api/v1/health` and expect `"status": "ok"`.
