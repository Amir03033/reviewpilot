# Deploying ReviewPilot with Plesk + Git (reviewspilot.nl)

## Paths at a glance

| What | Path / value |
|------|--------------|
| Domain folder | `/var/www/vhosts/reviewspilot.nl/` |
| App (Git deploy target) | `/var/www/vhosts/reviewspilot.nl/httpdocs` |
| **Document root** | `httpdocs/public` |
| Config file | `/var/www/vhosts/reviewspilot.nl/httpdocs/.env` |
| Logs | `/var/www/vhosts/reviewspilot.nl/httpdocs/storage/logs/` |
| PHP 8.4 (CLI) | `/opt/plesk/php/8.4/bin/php` |
| Node 24 (Plesk Node.js extension) | `/opt/plesk/node/24/bin/npm` |
| Git branch | `claude/charming-darwin-ky7wda` (switch to `main` after merging) |
| Repo | `git@github.com:amir03033/reviewpilot.git` |
| System user | the subscription's user, shown under *Websites & Domains → Hosting & DNS → Hosting* (called `SYSUSER` below) |

## 1. Plesk components (once, as admin)
*Tools & Settings → Updates → Add/Remove Components*. Make sure these are installed:
- **PHP 8.4** (Plesk PHP)
- **PostgreSQL server**
- **Git**
- Extensions (*Extensions* menu): **Node.js** and **Laravel Toolkit** (optional but handy)

*Tools & Settings → PHP Settings → 8.4 FPM → Extensions*: enable `pdo_pgsql`, `pgsql`, `intl`, `bcmath`, `zip`, `mbstring`, `curl`.

## 2. Domain
*Websites & Domains → Add Domain* → `reviewspilot.nl`. DNS A records for `reviewspilot.nl` and `www` must point to the server.

*Hosting & DNS → Hosting*:
- PHP version **8.4**, served by **FPM**
- **Document root: `httpdocs/public`**

## 3. Database (PostgreSQL)
*Websites & Domains → Databases → Add Database*:
- Type: **PostgreSQL**
- Name: `reviewpilot`, user `reviewpilot`, strong password
- Server: `localhost`

## 4. Git
*Websites & Domains → Git → Add Repository*:
- Remote repository: `git@github.com:amir03033/reviewpilot.git`
- Plesk shows an **SSH public key**. Add it in GitHub → repo → *Settings → Deploy keys* (read-only).
- Branch: `claude/charming-darwin-ky7wda`
- Deployment mode: **Automatic** (or Manual)
- Server path: `httpdocs`
- Optional: copy Plesk's **webhook URL** into GitHub → *Settings → Webhooks* so every push deploys automatically.

**Enable additional deployment actions** and paste:
```
/opt/plesk/php/8.4/bin/php /usr/lib/plesk-9.0/composer.phar install --no-dev --optimize-autoloader --no-interaction
export PATH=/opt/plesk/node/24/bin:$PATH && npm ci && npm run build
/opt/plesk/php/8.4/bin/php artisan migrate --force
/opt/plesk/php/8.4/bin/php artisan optimize
/opt/plesk/php/8.4/bin/php artisan queue:restart
```
(If Composer is somewhere else on your server, run `find / -name "composer*" -path "*plesk*" 2>/dev/null` to locate it, or use the Laravel Toolkit, which runs Composer and npm for you.)

## 5. First-time setup (SSH, once)
```bash
cd /var/www/vhosts/reviewspilot.nl/httpdocs && cp .env.example .env && /opt/plesk/php/8.4/bin/php artisan key:generate
```
Edit `.env` (Plesk *File Manager* or `nano .env`):
```
APP_NAME=ReviewPilot
APP_ENV=production
APP_DEBUG=false
APP_URL=https://reviewspilot.nl

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=reviewpilot
DB_USERNAME=reviewpilot
DB_PASSWORD=...

# No Redis needed on Plesk: the database works for queue + cache at pilot scale.
QUEUE_CONNECTION=database
CACHE_STORE=database
SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
SESSION_ENCRYPT=true
LOG_STACK=daily

AI_PROVIDER=anthropic
ANTHROPIC_API_KEY=...
GOOGLE_CLIENT_ID=...
GOOGLE_CLIENT_SECRET=...
MOLLIE_KEY=test_...        # live_... when you go live

# Plesk mail: create mailbox noreply@reviewspilot.nl under Mail first
MAIL_MAILER=smtp
MAIL_HOST=localhost
MAIL_PORT=587
MAIL_USERNAME=noreply@reviewspilot.nl
MAIL_PASSWORD=...
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@reviewspilot.nl
MAIL_FROM_NAME=ReviewPilot
```
⚠️ Never change `APP_KEY` after going live: the Google logins are encrypted with it.

Then:
```bash
/opt/plesk/php/8.4/bin/php artisan migrate --force && /opt/plesk/php/8.4/bin/php artisan db:seed --force && /opt/plesk/php/8.4/bin/php artisan optimize
```
After changing `.env` later, always run `/opt/plesk/php/8.4/bin/php artisan optimize` again.

## 6. HTTPS
*Websites & Domains → SSL/TLS Certificates → Let's Encrypt*. Include `www`, then turn on **Redirect from HTTP to HTTPS**.

## 7. Scheduler (every minute)
*Websites & Domains → Scheduled Tasks → Add Task*:
- Task type: **Run a command**
- Command: `/opt/plesk/php/8.4/bin/php /var/www/vhosts/reviewspilot.nl/httpdocs/artisan schedule:run`
- Run: **Cron style** `* * * * *`
- Notify: *Errors only*

(Or toggle *Scheduler* on in the Laravel Toolkit.)

## 8. Queue worker (publishes replies, runs the AI, syncs Google)
**Recommended (root SSH + Supervisor):**
```bash
sudo apt install -y supervisor
```
```bash
sudo tee /etc/supervisor/conf.d/reviewpilot-worker.conf > /dev/null <<'EOF'
[program:reviewpilot-worker]
command=/opt/plesk/php/8.4/bin/php /var/www/vhosts/reviewspilot.nl/httpdocs/artisan queue:work database --queue=realtime,ai,sync,notifications,backfill,default --sleep=3 --max-time=3600
user=SYSUSER
numprocs=2
process_name=%(program_name)s_%(process_num)02d
autostart=true
autorestart=true
stopwaitsecs=120
redirect_stderr=true
stdout_logfile=/var/www/vhosts/reviewspilot.nl/httpdocs/storage/logs/worker.log
EOF
```
Replace `SYSUSER` with the subscription's system user, then:
```bash
sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl status
```
**Alternative without Supervisor:** add a second Scheduled Task, every minute:
`/opt/plesk/php/8.4/bin/php /var/www/vhosts/reviewspilot.nl/httpdocs/artisan queue:work database --queue=realtime,ai,sync,notifications,backfill,default --stop-when-empty --max-time=55`

## 9. Check
```bash
cd /var/www/vhosts/reviewspilot.nl/httpdocs && /opt/plesk/php/8.4/bin/php artisan reviewpilot:doctor
```
Fix everything marked ✗, then open https://reviewspilot.nl and register. The Mollie `test_` key is flagged until you switch to `live_`; that's expected.

## 10. External dashboards
- Google OAuth redirect URI: `https://reviewspilot.nl/integrations/google/callback`
- Google Pub/Sub push URL (optional): `https://reviewspilot.nl/webhooks/google/pubsub`
- Mollie: nothing to set (`https://reviewspilot.nl/webhooks/mollie` is sent with every payment)

## 11. Backups
*Tools & Settings → Backup Manager* (or per domain): schedule a **daily** backup including **databases**, stored **remotely** (FTP/S3). Test a restore once.

## Emergency stop
```bash
/opt/plesk/php/8.4/bin/php /var/www/vhosts/reviewspilot.nl/httpdocs/artisan reviewpilot:automation pause
```
