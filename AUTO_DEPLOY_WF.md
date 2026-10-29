# MEDIA_API_DEPLOY.md

## Objective

Implement automatic production deployment for the MyBizGate Media API.

Source project:

```text
/home/jul/Workspace/mybizgate/media-api/
```

Git repository:

```text
https://github.com/mybizgate/media-api.git
```

Production Laravel application directory:

```text
/home/mybizgat/app/media-api/
```

Production public entry:

```text
/home/mybizgat/public_html/media-api/
```

Production URL:

```text
https://media-api.mybizgate.com/
```

Production health endpoint:

```text
https://media-api.mybizgate.com/api/v1/health
```

Persistent media storage:

```text
/home/mybizgat/data/media-api/uploads/
```

The deployment must use GitHub Actions + FTPS because SSH is not available.

Pushing to the `deploy` branch must automatically deploy the Laravel application to production.

---

# 1. IMPORTANT RULES

Do not redesign the Media API.

Do not change application business/media functionality unless required for deployment.

Do not deploy or overwrite production:

```text
.env
storage/logs/
storage/framework/sessions/
storage/framework/cache/data/
storage/framework/views/
bootstrap/cache/config.php
/home/mybizgat/data/media-api/uploads/
```

Never include:

```text
APP_KEY
FTP credentials
database credentials
production secrets
```

inside Git or VITE/public configuration.

Production `.env` already exists on the server:

```text
/home/mybizgat/app/media-api/.env
```

It must survive every deployment.

Persistent uploaded files must remain completely independent from code deployment:

```text
/home/mybizgat/data/media-api/uploads/
```

---

# 2. BRANCH STRATEGY

Use:

```text
main
  |
  | development
  |
  v
deploy
  |
  | push triggers GitHub Actions
  v
production
```

Workflow trigger:

```yaml
on:
  push:
    branches:
      - deploy
  workflow_dispatch:
```

Do not deploy automatically from `main`.

---

# 3. CREATE DEPLOYMENT WORKFLOW

Create:

```text
/home/jul/Workspace/mybizgate/media-api/.github/workflows/deploy.yml
```

Use GitHub Actions with:

```text
PHP 8.3
Composer
FTPS
```

The workflow must:

1. checkout source
2. setup PHP 8.3
3. enable required extensions
4. validate Composer
5. install production dependencies
6. validate Laravel
7. create clean deployment package
8. exclude secrets/runtime files
9. deploy to `/app/media-api/`
10. verify the live production API
11. fail deployment if production verification fails

---

# 4. REQUIRED PHP EXTENSIONS

Use `shivammathur/setup-php`.

Required:

```text
gd
mbstring
xml
curl
zip
fileinfo
```

Example:

```yaml
- name: Setup PHP 8.3
  uses: shivammathur/setup-php@v2
  with:
    php-version: '8.3'
    extensions: gd, mbstring, xml, curl, zip, fileinfo
    coverage: none
```

---

# 5. COMPOSER INSTALL

Run:

```bash
composer install \
  --no-dev \
  --prefer-dist \
  --no-interaction \
  --no-progress \
  --optimize-autoloader
```

Do not run Composer on production hosting.

`vendor/` must be built by GitHub Actions and uploaded with the application.

---

# 6. VALIDATE LARAVEL BEFORE DEPLOYMENT

Before deployment run:

```bash
php artisan --version
php artisan route:list
```

Also run available automated tests where practical:

```bash
php artisan test
```

If the full test suite requires environment-specific dependencies, configure a safe CI environment.

Do not use the production `.env`.

---

# 7. DEPLOYMENT PACKAGE

Create a temporary directory such as:

```text
deploy/
```

Copy the Laravel application into it while excluding:

```text
.git/
.github/
deploy/
.env
.env.*
tests/
node_modules/
storage/logs/*
storage/framework/cache/data/*
storage/framework/sessions/*
storage/framework/views/*
bootstrap/cache/*.php
```

Do not include cached Laravel configuration generated locally.

Make sure required runtime directories still exist:

```text
storage/logs/
storage/framework/cache/data/
storage/framework/sessions/
storage/framework/views/
bootstrap/cache/
```

Create empty directories where necessary.

---

# 8. CRITICAL: DO NOT DEPLOY `.env`

The production server already contains:

```text
/home/mybizgat/app/media-api/.env
```

Deployment must never overwrite or remove it.

Do not include:

```text
.env
.env.production
.env.local
```

in the FTPS deployment package.

GitHub Actions must not need the production `APP_KEY`.

---

# 9. CRITICAL: DO NOT TOUCH MEDIA STORAGE

Never upload to or delete from:

```text
/home/mybizgat/data/media-api/uploads/
```

GitHub deployment targets only:

```text
/home/mybizgat/app/media-api/
```

The public web entry remains independently configured at:

```text
/home/mybizgat/public_html/media-api/
```

Do not redeploy or overwrite the public `index.php` unless explicitly required.

---

# 10. FTP DEPLOYMENT

Use:

```yaml
SamKirkland/FTP-Deploy-Action@v4.4.0
```

Required repository/environment secrets:

```text
FTP_SERVER
FTP_USERNAME
FTP_PASSWORD
```

Use:

```text
protocol: ftps
port: 21
```

Target directory:

```text
app/media-api/
```

If the FTP account is already rooted differently, inspect the existing MyBizGate FTP convention before hardcoding the target.

The final server path must be:

```text
/home/mybizgat/app/media-api/
```

Do not accidentally deploy to:

```text
/public_html/media-api
```

because only the public Laravel entry belongs there.

---

# 11. SAFE DEPLOYMENT

Use:

```yaml
dangerous-clean-slate: false
```

Do not delete unknown server-side files automatically.

Production `.env` must survive.

Runtime files must survive.

Uploaded tenant media must survive.

---

# 12. DEPLOYMENT WORKFLOW TARGET

The final workflow should be broadly equivalent to:

```yaml
name: Deploy Production

on:
  push:
    branches:
      - deploy
  workflow_dispatch:

concurrency:
  group: production-${{ github.repository }}
  cancel-in-progress: false

permissions:
  contents: read

jobs:
  deploy:
    runs-on: ubuntu-latest
    environment: production

    steps:
      - name: Checkout
        uses: actions/checkout@v4

      - name: Setup PHP 8.3
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          extensions: gd, mbstring, xml, curl, zip, fileinfo
          coverage: none

      - name: Validate Composer
        run: composer validate --no-check-publish

      - name: Install production dependencies
        run: |
          composer install \
            --no-dev \
            --prefer-dist \
            --no-interaction \
            --no-progress \
            --optimize-autoloader

      - name: Run Laravel validation
        run: |
          php artisan --version
          php artisan route:list
          php artisan test

      - name: Prepare deployment package
        run: |
          mkdir -p deploy

          rsync -a ./ deploy/ \
            --exclude='.git/' \
            --exclude='.github/' \
            --exclude='deploy/' \
            --exclude='.env' \
            --exclude='.env.*' \
            --exclude='tests/' \
            --exclude='node_modules/' \
            --exclude='storage/logs/*' \
            --exclude='storage/framework/cache/data/*' \
            --exclude='storage/framework/sessions/*' \
            --exclude='storage/framework/views/*' \
            --exclude='bootstrap/cache/*.php'

          mkdir -p deploy/storage/logs
          mkdir -p deploy/storage/framework/cache/data
          mkdir -p deploy/storage/framework/sessions
          mkdir -p deploy/storage/framework/views
          mkdir -p deploy/bootstrap/cache

      - name: Verify deployment package
        run: |
          test -f deploy/artisan
          test -f deploy/composer.json
          test -f deploy/vendor/autoload.php
          test -f deploy/bootstrap/app.php
          test -f deploy/routes/api.php

          test ! -f deploy/.env
          test ! -f deploy/bootstrap/cache/config.php

          du -sh deploy

      - name: Deploy Laravel via FTPS
        uses: SamKirkland/FTP-Deploy-Action@v4.4.0
        with:
          server: ${{ secrets.FTP_SERVER }}
          username: ${{ secrets.FTP_USERNAME }}
          password: ${{ secrets.FTP_PASSWORD }}
          protocol: ftps
          port: 21
          local-dir: ./deploy/
          server-dir: app/media-api/
          dangerous-clean-slate: false
          exclude: |
            **/.git*
            **/.git*/**
            **/.env
            **/.env.*
            **/storage/logs/**
            **/storage/framework/cache/data/**
            **/storage/framework/sessions/**
            **/storage/framework/views/**
            **/bootstrap/cache/config.php

      - name: Verify production
        run: |
          RESPONSE=$(curl -fsSL \
            "https://media-api.mybizgate.com/api/v1/health?deploy=${GITHUB_SHA}")

          echo "$RESPONSE"

          echo "$RESPONSE" | grep -q '"status":"ok"'
          echo "$RESPONSE" | grep -q '"service":"MyBizGate Media API"'
          echo "$RESPONSE" | grep -q '"environment":"production"'
          echo "$RESPONSE" | grep -q '"storage":true'
          echo "$RESPONSE" | grep -q '"gd":true'
          echo "$RESPONSE" | grep -q '"webp":true'
```

Improve this implementation if the existing project structure requires it.

---

# 13. VERIFY THAT `deploy` REALLY CHANGES PRODUCTION

This is mandatory.

Do not finish merely because GitHub Actions reports success.

Claude must prove:

```text
git push origin deploy
```

causes the live production application at:

```text
https://media-api.mybizgate.com/
```

to use the newly deployed code.

Implement a safe deployment identity mechanism.

Preferred approach:

Add a non-secret application build identifier exposed by the health endpoint.

For example:

```json
{
  "status": "ok",
  "service": "MyBizGate Media API",
  "version": "1.0.0",
  "deployment": "abc12345",
  "environment": "production"
}
```

The deployment identifier should come from Git commit SHA.

Do not hardcode it manually.

One possible design:

During GitHub Actions, generate a non-secret deployment file:

```text
storage/app/deployment.json
```

or another appropriate non-runtime application location.

Example:

```json
{
  "commit": "abcdef1234567890",
  "deployed_at": "2026-10-29T08:00:00Z"
}
```

However, choose a location that will safely deploy and will not conflict with runtime persistence rules.

A better option may be:

```text
config/deployment.php
```

generated during CI, or:

```text
bootstrap/deployment.json
```

as long as it is safe and contains no secrets.

The health endpoint should expose only:

```text
commit short SHA
deployment timestamp
```

Example:

```json
{
  "status": "ok",
  "service": "MyBizGate Media API",
  "version": "1.0.0",
  "environment": "production",
  "deployment": {
    "commit": "abc12345",
    "deployed_at": "2026-10-29T08:00:00Z"
  },
  "storage": true,
  "gd": true,
  "webp": true
}
```

---

# 14. DEPLOYMENT VERIFICATION MUST MATCH GITHUB SHA

After FTPS deployment, the workflow must call:

```text
https://media-api.mybizgate.com/api/v1/health
```

Extract the deployment commit from the response.

Compare it with:

```text
${GITHUB_SHA}
```

or its short equivalent.

Example logic:

```bash
EXPECTED="${GITHUB_SHA::8}"

RESPONSE=$(curl -fsSL \
  "https://media-api.mybizgate.com/api/v1/health?nocache=${GITHUB_SHA}")

echo "$RESPONSE"

echo "$RESPONSE" | grep -q "\"commit\":\"$EXPECTED\""
```

The workflow must fail if the live server does not report the commit that was just pushed.

This proves:

```text
deploy branch push
        ↓
GitHub Actions
        ↓
FTPS deployment
        ↓
/home/mybizgat/app/media-api
        ↓
public_html/media-api/index.php
        ↓
https://media-api.mybizgate.com
        ↓
new Git commit running
```

---

# 15. CACHE-BUST PRODUCTION VERIFICATION

When calling the health endpoint use:

```text
?nocache=${GITHUB_SHA}
```

and send headers if useful:

```bash
curl -fsSL \
  -H "Cache-Control: no-cache" \
  -H "Pragma: no-cache" \
  "https://media-api.mybizgate.com/api/v1/health?nocache=${GITHUB_SHA}"
```

This prevents browser/proxy/CDN caching from giving a false deployment result.

---

# 16. VERIFY PUBLIC ENTRY

Do not modify the current working server entry unless necessary:

```text
/home/mybizgat/public_html/media-api/index.php
```

It currently points to:

```text
/home/mybizgat/app/media-api
```

The deployment must preserve this architecture:

```text
media-api.mybizgate.com
        ↓
/public_html/media-api/index.php
        ↓
/app/media-api/bootstrap/app.php
```

---

# 17. VERIFY PERSISTENT STORAGE SURVIVES DEPLOYMENT

Before deployment, the Media API already has tenant media under:

```text
/home/mybizgat/data/media-api/uploads/tenants/
```

After deployment:

- existing uploaded media must still work
- existing thumbnail URLs must still work
- existing download URLs must still work
- existing metadata must still work

The workflow must never clean:

```text
/home/mybizgat/data/media-api/uploads/
```

---

# 18. VERIFY PRODUCTION ENVIRONMENT SURVIVES

After deployment, health must still report:

```json
{
  "environment": "production"
}
```

This proves the server `.env` remains active.

Do not deploy a local `.env` that changes production to:

```text
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost
```

---

# 19. REQUIRED GITHUB CONFIGURATION

Document these required GitHub secrets:

```text
FTP_SERVER
FTP_USERNAME
FTP_PASSWORD
```

If `server-dir` depends on FTP account root, document the exact expected configuration.

Do not put FTP credentials in source.

---

# 20. README UPDATE

Update:

```text
/home/jul/Workspace/mybizgate/media-api/README.md
```

Keep it concise.

Add:

```text
## Production Deployment
```

Document:

```text
Production branch: deploy

Push:
git push origin deploy

GitHub Actions:
.github/workflows/deploy.yml

Production application:
/home/mybizgat/app/media-api

Public entry:
/home/mybizgat/public_html/media-api

Persistent media:
/home/mybizgat/data/media-api/uploads

Production health:
https://media-api.mybizgate.com/api/v1/health
```

Also clearly state:

```text
.env is server-managed and is never deployed.
```

---

# 21. INITIAL GIT SETUP

Verify the local repository points to:

```text
https://github.com/mybizgate/media-api.git
```

Check:

```bash
git remote -v
```

Ensure source is committed.

If `deploy` does not exist, create it appropriately.

Do not destroy local work.

Recommended flow:

```bash
git checkout main
git add .
git commit -m "configure media api deployment"
git push origin main

git checkout -B deploy
git push -u origin deploy
```

If branches already exist, preserve their history and merge safely.

---

# 22. DO NOT PUSH UNTIL VALIDATION PASSES

Before pushing `deploy`, run locally:

```bash
composer install
php artisan test
php artisan route:list
```

Verify:

```text
media upload
preview
thumbnail
metadata
download
delete
health
```

still work locally.

---

# 23. FIRST REAL DEPLOYMENT TEST

Once workflow implementation is complete:

1. commit changes
2. push to `main`
3. merge or update `deploy`
4. push `deploy`
5. monitor GitHub Action
6. wait for FTPS step
7. call live health endpoint
8. verify live deployment SHA matches pushed SHA

The live endpoint:

```text
https://media-api.mybizgate.com/api/v1/health
```

must report the new deployment identifier.

Do not simply say:

```text
workflow succeeded
```

Claude must verify that the actual running production endpoint changed.

---

# 24. SECOND DEPLOYMENT PROOF

Perform one additional harmless deployment verification.

For example:

1. make a harmless version/health metadata change
2. commit it
3. push it to `deploy`
4. verify GitHub Action executes
5. verify live health deployment SHA changes
6. confirm existing uploaded media still works

This verifies that deployment is repeatable and not a one-time success.

Do not change business/media behavior merely for testing.

---

# 25. FAILURE HANDLING

If deployment fails:

- inspect GitHub Action logs
- fix the workflow
- retry
- do not manually overwrite production unless required for recovery
- do not delete production media
- do not delete production `.env`

If the health endpoint continues returning an old SHA:

investigate:

```text
wrong FTP server-dir
FTP account root
deployment package location
public index app path
server-side cache
PHP OPcache
stale deployment files
```

Do not declare success until the live SHA matches.

---

# 26. SECURITY

Do not commit:

```text
.env
APP_KEY
FTP passwords
FTP usernames if sensitive
production secrets
tenant private files
uploaded production media
```

Check `.gitignore`.

Ensure at minimum:

```gitignore
.env
.env.*
!.env.example

/vendor/
/node_modules/

storage/logs/*
storage/framework/cache/*
storage/framework/sessions/*
storage/framework/views/*
```

Do not accidentally exclude `.env.example`.

---

# 27. EXPECTED FINAL ARCHITECTURE

```text
GitHub
mybizgate/media-api
       |
       | push deploy
       v
GitHub Actions
       |
       | PHP 8.3
       | composer install --no-dev
       | tests
       | package
       | FTPS
       v
/home/mybizgat/app/media-api
       |
       | loaded by
       v
/home/mybizgat/public_html/media-api/index.php
       |
       v
https://media-api.mybizgate.com
```

Persistent data remains:

```text
/home/mybizgat/data/media-api/uploads/
```

independent of Git deployment.

---

# 28. COMPLETION CRITERIA

Do not stop until all are true:

```text
[ ] deploy.yml created
[ ] Composer production install works
[ ] Laravel tests pass
[ ] deployment package excludes .env
[ ] deployment package excludes local caches
[ ] FTPS target resolves to /home/mybizgat/app/media-api
[ ] deploy branch triggers workflow
[ ] GitHub workflow succeeds
[ ] live health endpoint returns status ok
[ ] live health endpoint returns environment production
[ ] live health endpoint reports deployed Git SHA
[ ] reported SHA matches the deploy branch commit
[ ] second deploy changes live SHA
[ ] existing media survives deploy
[ ] existing preview still works
[ ] existing thumbnail still works
[ ] existing download still works
[ ] /data/media-api/uploads remains untouched
[ ] production .env remains untouched
[ ] README updated
```

---

# 29. FINAL REPORT

At the end provide only a concise report containing:

```text
Files created
Files modified
GitHub workflow path
GitHub secrets required
Deployment branch
Production FTP target
Live health URL
Expected commit SHA
Live reported commit SHA
Deployment verification result
Persistent storage verification result
```

Explicitly state whether:

```text
git push origin deploy
```

was proven to update:

```text
https://media-api.mybizgate.com/
```

Do not claim production deployment is verified unless the live endpoint reports the commit from the pushed `deploy` branch.
