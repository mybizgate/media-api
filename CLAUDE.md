# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

Laravel 13 / PHP 8.3 API that stores every MyBizGate tenant's uploads (images and documents). It is one of several
independent Git repositories inside the `mybizgate` workspace (see `../AGENTS.md`); business data lives in the
separate `client-api`, and tenant frontends call both. **There is no database**: the filesystem is the whole store,
and `metadata.json` beside each file is the record. `README.md` documents the HTTP API, environment variables,
production layout and release flow in detail; do not duplicate it here, keep it in sync when behaviour changes.

## Commands

```bash
composer setup                      # composer install + .env from .env.example + key:generate
php artisan serve --port=8001       # client-api uses 8000; media-api uses 8001
php artisan test                    # whole suite (Unit + Feature), runs on a faked disk
php artisan test --filter=MediaUploadTest                      # one class
php artisan test --filter='MediaAccessTest::test_thumbnail_is_served' # one method
php artisan test tests/Feature/MediaLifecycleTest.php          # one file
vendor/bin/pint                     # Laravel Pint formatter (no pint.json, default Laravel preset)
php artisan route:list
```

Local uploads above the PHP CLI default of 2 MB need the raised limits:
`php -d upload_max_filesize=26M -d post_max_size=28M -S 127.0.0.1:8001 -t public`.

Local `MEDIA_STORAGE_PATH` is `/home/jul/Workspace/mybizgate/uploads/media-api` (outside this repo). Tests never
touch it: `tests/TestCase.php` calls `Storage::fake('media')` and `phpunit.xml` points `MEDIA_STORAGE_PATH` at a
dummy path as a safety net.

## Architecture

Request flow for a write: `routes/api.php` → `tenant` middleware (`ResolveTenant`, requires `X-Client-Code`) →
`UploadMediaRequest`/`ReplaceMediaRequest` → `MediaController` → `MediaStorageService` → repository + filesystem.

Layers under `app/`:

- `Services/Media/MediaPathService` is the **only** place that knows the on-disk layout
  (`tenants/{tenant}/{images|files}/{module}/{YYYY}/{MM}/{ulid}/`) and the only place that validates path segments
  coming from requests (`assertTenant`, `assertModule`, `assertEntityId`, `assertMediaId`, `assertVisibility`).
  Every segment that reaches a glob or a path has already passed one of these. Add new request-derived path parts here, not in controllers.
- `Services/Media/MediaFileValidator` sniffs MIME from file bytes (never the browser's claim), applies the
  allow lists and `blocked_extensions` from `config/media.php`, and per-type size limits.
- `Services/Media/ImageProcessingService` is GD only (no ImageMagick; the shared host lacks it): decode → resize
  without upscaling → WebP. Originals of images are discarded; documents keep their extension.
- `Services/Media/MediaStorageService` orchestrates upload/replace/delete. New content is always built in a
  `<final>.staging-xxxx` sibling directory and `rename()`d into place; replace retires the old directory to
  `<final>.old-xxxx` first and only discards it after the new one is in place. Keep this atomic pattern when
  changing write paths. `MediaMetadataService` writes `metadata.json` via temp file + rename for the same reason.
- `Repositories/Media/MediaRepositoryInterface` is bound to `FilesystemMediaRepository` in `AppServiceProvider`.
  Lookups by id are bounded globs over the layout above; `findAnyTenant()` exists because public view URLs carry
  no tenant. A database-backed repository would be swapped in at the binding without touching controllers.
- `DTO/Media/MediaData` is an immutable record (`with([...])` returns a copy); `Http/Resources/MediaResource`
  is the only public shape and exposes API URLs only, never filesystem paths.

Cross-cutting rules the code relies on:

- **Access model**: write endpoints (`index`, `store`, `update`, `destroy`) require the header and are scoped to
  that tenant. Read endpoints are outside the middleware: with a header the lookup is tenant-scoped, without one
  only `public` media resolves (`MediaController::readable()`). Private media gets `Cache-Control: private, no-store`.
- **Errors**: throw `App\Exceptions\MediaException` (`badRequest`, `forbidden`, `notFound`, `validation`,
  `server`); `bootstrap/app.php` renders it and `ValidationException` into the `{"success": false, "message",
  "errors"}` envelope. Controllers never build error responses. 4xx are not logged; log messages pass through
  `MediaPathService::redact()` so the storage root never appears in logs.
- **Config, not code**: all limits, patterns, allowed MIME types and filenames live in `config/media.php` and are
  driven by `.env`. `APP_URL` is forced as the root URL (`AppServiceProvider::boot`) so generated URLs are correct
  behind the shared-hosting proxy; this is skipped in tests.
- `PUT /media/{id}` also accepts `POST` (optionally `_method=PUT`) because PHP only parses multipart for POST.

## Tests

`tests/TestCase.php` provides helpers used everywhere: `tenantHeaders()`, `imageFile()` (real GD-generated
images), `rawFile()`, `pdfFile()`, `upload()` (asserts 201, returns `data`), and `mediaDirectory()` to assert on
the faked disk. The default tenant is `lzhub`. Feature tests exercise HTTP end to end; unit tests cover
`MediaPathService`, `ImageProcessingService` and `DeploymentInfo`.

## Deployment (read before touching `.github/workflows/deploy.yml` or `deploy/`)

- `main` is development, `deploy` is production. Pushing `deploy` runs the workflow: tests, `composer install
  --no-dev`, package into `release/`, FTPS upload to `/home/mybizgat/app/media-api`, then a health check that
  **fails unless the live `/api/v1/health` reports the pushed commit** (via `bootstrap/deployment.json`, which
  CI writes and `App\Support\DeploymentInfo` reads). Release flow: `git checkout deploy && git merge --ff-only main && git push origin deploy`.
- The production `.env`, `storage/`, and `bootstrap/cache/config.php` are server-managed and excluded from both
  the package and the upload. Never add them, and never set `dangerous-clean-slate: true`.
- `deploy/public_html/` holds the only files that belong in the document root (`index.php` resolves the app at
  `../../app/media-api`, `.user.ini` sets upload limits). The workflow never touches `public_html/` or the uploads
  directory. Do not run `config:cache`/`route:cache` on the server; deployments leave those caches alone.
- `AUTO_DEPLOY_WF.md` is the original deployment spec; it is excluded from the release package.
