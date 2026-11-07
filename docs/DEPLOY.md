# Deploying ReviewPilot on an Ubuntu server

This guide covers Ubuntu 24.04 with Nginx, PHP 8.4, PostgreSQL, Redis, Supervisor and Let's Encrypt, for the domain `reviewspilot.nl`. Replace the parts in CAPITALS with your own values.

## 0. DNS
At your domain registrar, add **A records** pointing to the server's IP address:
- `reviewspilot.nl` → `SERVER_IP`
- `www.reviewspilot.nl` → `SERVER_IP`

## 1. Install the software
```bash
sudo apt update && sudo apt upgrade -y
```
```bash
sudo add-apt-repository ppa:ondrej/php -y && sudo apt update
```
```bash
sudo apt install -y nginx postgresql redis-server supervisor git unzip certbot python3-certbot-nginx \
  php8.4-fpm php8.4-cli php8.4-pgsql php8.4-redis php8.4-mbstring php8.4-xml php8.4-curl php8.4-zip php8.4-bcmath php8.4-intl
```
```bash
curl -sS https://getcomposer.org/installer | sudo php -- --install-dir=/usr/local/bin --filename=composer
```
```bash
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash - && sudo apt install -y nodejs
```

## 2. Database
```bash
sudo -u postgres psql -c "CREATE USER reviewpilot WITH PASSWORD 'STRONG_DB_PASSWORD';" -c "CREATE DATABASE reviewpilot OWNER reviewpilot;"
```

## 3. Get the code
The repository is private, so the server needs read access to it: either a GitHub deploy key, or a personal access token used when cloning.
```bash
sudo mkdir -p /var/www && sudo chown $USER:www-data /var/www
```
```bash
git clone --branch claude/charming-darwin-ky7wda https://github.com/amir03033/reviewpilot.git /var/www/reviewpilot
```
(Once the work is merged into `main`, clone `main` instead.)

## 4. Install and configure
```bash
cd /var/www/reviewpilot && composer install --no-dev --optimize-autoloader
```
```bash
npm ci && npm run build
```
```bash
cp .env.example .env && php artisan key:generate
```
Open `.env` (`nano .env`) and set at least:
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
DB_PASSWORD=STRONG_DB_PASSWORD

QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=redis
SESSION_SECURE_COOKIE=true
SESSION_ENCRYPT=true
LOG_STACK=daily

AI_PROVIDER=anthropic
ANTHROPIC_API_KEY=...
GOOGLE_CLIENT_ID=...
GOOGLE_CLIENT_SECRET=...
MOLLIE_KEY=test_...   # switch to live_... when going live

MAIL_MAILER=smtp
MAIL_HOST=...
MAIL_PORT=587
MAIL_USERNAME=...
MAIL_PASSWORD=...
MAIL_FROM_ADDRESS=noreply@reviewspilot.nl
```
⚠️ Never change `APP_KEY` after going live: the Google logins are encrypted with it.

```bash
php artisan migrate --force && php artisan db:seed --force
```
```bash
php artisan optimize
```
```bash
sudo chown -R $USER:www-data storage bootstrap/cache && sudo chmod -R 775 storage bootstrap/cache
```

## 5. Nginx
```bash
sudo tee /etc/nginx/sites-available/reviewspilot > /dev/null <<'EOF'
server {
    listen 80;
    server_name reviewspilot.nl www.reviewspilot.nl;
    root /var/www/reviewpilot/public;
    index index.php;
    client_max_body_size 10M;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
EOF
```
```bash
sudo ln -sf /etc/nginx/sites-available/reviewspilot /etc/nginx/sites-enabled/ && sudo rm -f /etc/nginx/sites-enabled/default && sudo nginx -t && sudo systemctl reload nginx
```

## 6. HTTPS (free Let's Encrypt certificate)
```bash
sudo certbot --nginx -d reviewspilot.nl -d www.reviewspilot.nl --redirect
```

## 7. Background worker (publishes replies, runs AI, syncs Google)
```bash
sudo tee /etc/supervisor/conf.d/reviewpilot-worker.conf > /dev/null <<'EOF'
[program:reviewpilot-worker]
command=php /var/www/reviewpilot/artisan queue:work redis --queue=realtime,ai,sync,notifications,backfill,default --sleep=3 --max-time=3600
user=www-data
numprocs=2
process_name=%(program_name)s_%(process_num)02d
autostart=true
autorestart=true
stopwaitsecs=120
redirect_stderr=true
stdout_logfile=/var/www/reviewpilot/storage/logs/worker.log
EOF
```
```bash
sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl status
```

## 8. Scheduler (auto-replies, Google polling, billing checks, digests)
```bash
echo "* * * * * www-data cd /var/www/reviewpilot && php artisan schedule:run >> /dev/null 2>&1" | sudo tee /etc/cron.d/reviewpilot
```

## 9. Firewall
```bash
sudo ufw allow OpenSSH && sudo ufw allow 'Nginx Full' && sudo ufw enable
```
PostgreSQL and Redis listen on localhost only by default. Keep it that way.

## 10. Check everything
```bash
cd /var/www/reviewpilot && php artisan reviewpilot:doctor
```
Fix whatever it marks with ✗, then open https://reviewspilot.nl and register.

## 11. Settings in external dashboards
- **Google Cloud Console** → OAuth client → Authorised redirect URI: `https://reviewspilot.nl/integrations/google/callback`
- **Google Pub/Sub** (optional) → push endpoint: `https://reviewspilot.nl/webhooks/google/pubsub`
- **Mollie**: nothing to set. The webhook URL `https://reviewspilot.nl/webhooks/mollie` is sent with each payment.
- **Mail**: add the SPF/DKIM records your mail provider gives you to the DNS of reviewspilot.nl.

## 12. Daily database backup
```bash
sudo mkdir -p /var/backups/reviewpilot && sudo chown postgres /var/backups/reviewpilot
```
```bash
echo '30 3 * * * postgres pg_dump reviewpilot | gzip > /var/backups/reviewpilot/db-$(date +\%F).sql.gz && find /var/backups/reviewpilot -mtime +14 -delete' | sudo tee /etc/cron.d/reviewpilot-backup
```
Also copy these backups off the server regularly (e.g. to another machine or cloud storage), and test a restore once.

## 13. Updating to a new version
```bash
cd /var/www/reviewpilot && php artisan down && git pull && composer install --no-dev --optimize-autoloader && npm ci && npm run build && php artisan migrate --force && php artisan optimize && php artisan queue:restart && php artisan up
```

## Emergency stop
Stop all automatic publishing immediately:
```bash
cd /var/www/reviewpilot && php artisan reviewpilot:automation pause
```
