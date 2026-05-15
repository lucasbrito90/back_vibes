# Staging Deploy — Setup Guide

Automatic deployment runs every time a commit is pushed to the `staging` branch.  
The workflow file lives at `.github/workflows/deploy-staging.yml`.

**Target environment**

| Item | Value |
|---|---|
| URL | https://staging-api.ixora-app.app |
| Droplet IP | 165.245.224.57 |
| Server path | /var/www/back_vibes |
| Web server | Caddy + PHP-FPM 8.4 |
| Database | DigitalOcean Managed PostgreSQL |

---

## 1 — Generate a dedicated SSH key for GitHub Actions

Run this **locally** (never upload the private key anywhere except GitHub Secrets):

```bash
ssh-keygen -t ed25519 -C "github-actions-staging" -f ~/.ssh/github_actions_staging -N ""
```

This produces two files:
- `~/.ssh/github_actions_staging` — **private key** (goes into GitHub Secrets)
- `~/.ssh/github_actions_staging.pub` — **public key** (goes onto the Droplet)

---

## 2 — Add the public key to the Droplet

SSH into the server as the deploy user (e.g. `root` or `deploy`):

```bash
ssh root@165.245.224.57
```

Then append the public key to the authorized list:

```bash
echo "$(cat ~/.ssh/github_actions_staging.pub)" >> ~/.ssh/authorized_keys
chmod 600 ~/.ssh/authorized_keys
```

Verify the key was added:

```bash
tail -1 ~/.ssh/authorized_keys
```

---

## 3 — Configure sudo without password (if using a non-root user)

If your deploy user is not `root`, grant passwordless sudo for the two service
commands used by the workflow:

```bash
# On the server, as root:
visudo -f /etc/sudoers.d/deploy-staging
```

Add these lines (replace `deploy` with your actual username):

```
deploy ALL=(ALL) NOPASSWD: /bin/systemctl restart php8.4-fpm
deploy ALL=(ALL) NOPASSWD: /bin/systemctl reload caddy
```

If you SSH as `root`, skip this step — `sudo` is a no-op for root.

---

## 4 — Add GitHub Secrets

Go to:  
**GitHub → Repository → Settings → Secrets and variables → Actions → New repository secret**

Create the following four secrets:

| Secret name | Value |
|---|---|
| `STAGING_HOST` | `165.245.224.57` |
| `STAGING_USER` | `root` (or your deploy username) |
| `STAGING_SSH_KEY` | Contents of `~/.ssh/github_actions_staging` (the **private** key, including `-----BEGIN...` and `-----END...` lines) |
| `STAGING_PATH` | `/var/www/back_vibes` |

To copy the private key to your clipboard:

```bash
# macOS
cat ~/.ssh/github_actions_staging | pbcopy

# Linux
cat ~/.ssh/github_actions_staging | xclip -selection clipboard
# or just print and copy manually:
cat ~/.ssh/github_actions_staging
```

---

## 5 — Trigger a deploy

Any push to the `staging` branch triggers the workflow:

```bash
git checkout staging
git merge develop          # or cherry-pick specific commits
git push origin staging
```

The workflow starts automatically. You can also trigger it manually:  
**GitHub → Actions → Deploy — Staging → Run workflow → Branch: staging → Run workflow**

---

## 6 — Monitor the deploy

### GitHub Actions logs

Go to: **GitHub → Actions → Deploy — Staging → (latest run)**

Each step is numbered and labelled in the workflow output:
```
[1/7] Fetching origin/staging...
[2/7] Installing Composer dependencies...
[3/7] Running migrations...
...
Deploy completed successfully.
```

### Server logs

```bash
# PHP-FPM errors
sudo tail -f /var/log/php8.4-fpm.log

# Caddy access / error logs
sudo journalctl -u caddy -f

# Laravel application log
tail -f /var/www/back_vibes/storage/logs/laravel.log
```

### Health check

```bash
curl -I https://staging-api.ixora-app.app
# Expect: HTTP/2 200 (or 302 for root redirect)
```

---

## 7 — Rollback

If a deploy breaks the staging environment, roll back to the previous commit:

```bash
# On the server
cd /var/www/back_vibes
git log --oneline -5           # find the last good commit hash
git reset --hard <commit-hash>
php artisan migrate:rollback   # only if the bad deploy ran new migrations
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
sudo systemctl restart php8.4-fpm
sudo systemctl reload caddy
```

---

## 8 — Environment variables (.env)

The `.env` file on the server is **not managed by Git** (correctly excluded via `.gitignore`).  
After the first deploy, create or update it manually:

```bash
ssh root@165.245.224.57
cp /var/www/back_vibes/.env.example /var/www/back_vibes/.env
nano /var/www/back_vibes/.env
```

Key variables to configure:

```env
APP_ENV=staging
APP_DEBUG=false
APP_URL=https://staging-api.ixora-app.app

DB_CONNECTION=pgsql
DB_HOST=<do-managed-postgres-host>
DB_PORT=25061          # Use 25061 (direct PostgreSQL) for migrations; 25060 is PgBouncer
DB_DATABASE=defaultdb
DB_USERNAME=<user>
DB_PASSWORD=<password>
DB_SSLMODE=require

FIREBASE_CREDENTIALS=<path-to-service-account.json>
```

After editing `.env`, run:

```bash
cd /var/www/back_vibes
php artisan key:generate --force   # only if APP_KEY is empty
php artisan config:cache
```
