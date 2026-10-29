# Deployment

How to run the API and the web app on one small Ubuntu server, keep it backed up, update it, and restore it. Everything here is done once, except [Updating](#updating) and [Restoring a backup](#restoring-a-backup).

The examples use `example.com`, with the API at `api.example.com` and the web app at `app.example.com`. Replace them with your domain everywhere.

## What you need

- **A server**: Ubuntu 24.04, 2 GB RAM and 1–2 CPUs are enough for one shop (Hetzner, DigitalOcean, Contabo, ...). You get its IP address and root login from the provider.
- **A domain** where you can add DNS records.
- **A bucket for backups** in an S3-compatible service, for example Backblaze B2 or Cloudflare R2. Create a private bucket and an application key that can read and write it. Note the endpoint URL, region, bucket name, key ID and secret.
- **An SMTP account** to send alert emails (any provider; a Gmail app password works for a start).

## 1. DNS

At your domain's DNS settings, add two `A` records pointing to the server's IP address:

| Name  | Type | Value        |
|-------|------|--------------|
| `api` | A    | server IP    |
| `app` | A    | server IP    |

The two must share a parent domain (`example.com`): the web app's login cookie is set for `.example.com`.

## 2. The server

Log in as root (`ssh root@SERVER_IP`), then:

```bash
apt update && apt upgrade -y
adduser deploy                      # the user that owns the code and runs deploys
usermod -aG sudo,www-data deploy
ufw allow OpenSSH && ufw allow 'Nginx Full' && ufw enable
timedatectl set-timezone UTC        # the schedule (backups at 23:30) is in UTC
```

Copy your SSH key to the new user (`ssh-copy-id deploy@SERVER_IP` from your computer) and log in as `deploy` from now on.

### Packages

```bash
sudo add-apt-repository ppa:ondrej/php -y && sudo apt update
sudo apt install -y nginx mysql-server git unzip p7zip-full \
  php8.5-fpm php8.5-cli php8.5-mysql php8.5-mbstring php8.5-xml php8.5-curl \
  php8.5-zip php8.5-gd php8.5-intl php8.5-bcmath
curl -sS https://getcomposer.org/installer | php && sudo mv composer.phar /usr/local/bin/composer
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo bash - && sudo apt install -y nodejs
sudo apt install -y certbot python3-certbot-nginx
```

### Database

```bash
sudo mysql_secure_installation          # answer yes to everything
sudo mysql -e "CREATE DATABASE inventory CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  CREATE USER 'inventory'@'localhost' IDENTIFIED BY 'A-LONG-RANDOM-PASSWORD';
  GRANT ALL ON inventory.* TO 'inventory'@'localhost';
  GRANT PROCESS ON *.* TO 'inventory'@'localhost';"
```

`PROCESS` lets the nightly backup dump the database.

## 3. The API

```bash
sudo mkdir -p /var/www && sudo chown deploy:www-data /var/www
cd /var/www
git clone https://github.com/yakob-zakariya/inventory-api.git
cd inventory-api
composer install --no-dev --optimize-autoloader
cp deploy/env.production.example .env
nano .env                                # fill in every value marked CHANGE
php artisan key:generate --force
chmod -R g+w storage bootstrap/cache
php artisan migrate --force
php artisan db:seed --class=RolesAndPermissionsSeeder --force
php artisan storage:link
php artisan optimize
php artisan inventory:create-owner       # the first user, with every permission
```

Save a copy of `.env` in your password manager. Backups do not include it, and the backup archive password is in it.

If the repository is private, add a deploy key first: `ssh-keygen -t ed25519` on the server, add the public key under the repository's Settings → Deploy keys, and clone with `git@github.com:yakob-zakariya/inventory-api.git`.

## 4. The web app

The web app's repository must be on GitHub first (from `~/dev/personal/projects/inventory-web`: create an empty `inventory-web` repository on GitHub, then `git remote add origin https://github.com/yakob-zakariya/inventory-web.git && git push -u origin main`).

```bash
cd /var/www
git clone https://github.com/yakob-zakariya/inventory-web.git
cd inventory-web
echo "VITE_API_URL=https://api.example.com" > .env.production.local
npm ci && npm run build                  # writes dist/
```

## 5. Nginx and HTTPS

```bash
sudo cp /var/www/inventory-api/deploy/nginx-api.conf /etc/nginx/sites-available/inventory-api
sudo cp /var/www/inventory-api/deploy/nginx-web.conf /etc/nginx/sites-available/inventory-web
sudo nano /etc/nginx/sites-available/inventory-api    # set server_name
sudo nano /etc/nginx/sites-available/inventory-web    # set server_name
sudo ln -s /etc/nginx/sites-available/inventory-api /etc/nginx/sites-enabled/
sudo ln -s /etc/nginx/sites-available/inventory-web /etc/nginx/sites-enabled/
sudo rm /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx
sudo certbot --nginx -d api.example.com -d app.example.com
```

Certbot renews the certificates automatically.

## 6. The scheduler (backups and checks)

The nightly backup, its cleanup and monitoring, and `inventory:verify` run from Laravel's scheduler. Add one cron line for the `deploy` user (`crontab -e`):

```
* * * * * cd /var/www/inventory-api && php artisan schedule:run >> /dev/null 2>&1
```

What runs, in server time (UTC; add 3 hours for Addis Ababa):

| Time  | Command            | What it does |
|-------|--------------------|--------------|
| 23:00 | `backup:clean`     | Deletes old backups: all are kept 7 days, then one a day for 7 more days, one a week for 4 weeks, one a month for 6 months, one a year for a year. |
| 23:30 | `backup:run`       | Zips the database and the uploaded images, encrypts the zip, uploads it to the bucket. |
| 00:30 | `backup:monitor`   | Emails `BACKUP_MAIL_TO` if the newest backup is more than a day old. |
| 01:00 | `inventory:verify` | Emails `BACKUP_MAIL_TO` the problems if the data does not match the ledgers. |

A failed backup is also emailed. Successful runs send nothing.

## 7. Let the deploy user reload PHP

`deploy/deploy.sh` reloads PHP-FPM so the new code is used. Allow that one command without a password:

```bash
echo "deploy ALL=NOPASSWD: /usr/bin/systemctl reload php8.5-fpm" | sudo tee /etc/sudoers.d/deploy
```

## 8. Check that it works

```bash
cd /var/www/inventory-api
php artisan backup:run          # the first backup, now; check the bucket for the zip
php artisan backup:list         # "Healthy" should be ✅
php artisan inventory:verify    # "Everything matches the ledgers."
```

Open `https://app.example.com`, log in as the owner, and add your accounts, categories and products.

## Updating

### The API

```bash
/var/www/inventory-api/deploy/deploy.sh
```

It backs up the database first, puts the API in maintenance mode, pulls `main`, installs, migrates, syncs permissions, rebuilds caches, reloads PHP, and runs `inventory:verify`. If any step fails the API comes back up and the script stops: read the error, fix it, run it again. The backup taken at the start is the newest zip in the bucket if you need to go back.

### The web app

```bash
cd /var/www/inventory-web && git pull --ff-only && npm ci && npm run build
```

## Restoring a backup

Use this when the database is lost or damaged, or to move to a new server (do sections 2–5 there first, then this). Tested on 2026-10-29: a backup was restored into an empty MySQL database (every row came back and `inventory:verify` passed), and a password-protected backup was opened with `7z`.

1. **Get the zip.** Download the backup you want from the bucket's web page (files are named by date and time, in UTC, under `inventory-api/`) and copy it to the server: `scp 2026-10-29-23-30-00.zip deploy@SERVER_IP:~`.
2. **Unpack it** with the archive password from `.env` (`BACKUP_ARCHIVE_PASSWORD`):
   ```bash
   mkdir ~/restore && cd ~/restore && 7z x ~/2026-10-29-23-30-00.zip
   ```
   You get `db-dumps/mysql-inventory.sql.gz` and `public/` (product photos and the logo).
3. **Stop the API** so nobody writes during the restore:
   ```bash
   cd /var/www/inventory-api && php artisan down
   ```
4. **Replace the database** with the backup:
   ```bash
   sudo mysql -e "DROP DATABASE inventory; CREATE DATABASE inventory CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
   gunzip -c ~/restore/db-dumps/mysql-inventory.sql.gz | mysql -u inventory -p inventory
   ```
5. **Put the images back:**
   ```bash
   rsync -a ~/restore/public/ /var/www/inventory-api/storage/app/public/
   ```
6. **Bring it back up and check:**
   ```bash
   php artisan migrate --force          # only if the code is newer than the backup
   php artisan optimize:clear && php artisan optimize && php artisan permission:cache-reset
   php artisan inventory:verify
   php artisan up
   ```
7. Delete `~/restore` and the zip. Anything recorded after the backup was taken is lost and must be entered again.

## Troubleshooting

- **The web app cannot log in (419 or CORS errors):** `FRONTEND_URL` must be exactly `https://app.example.com`, `SESSION_DOMAIN` must be `.example.com`, and both sites must use HTTPS. After changing `.env`, run `php artisan optimize`.
- **Images do not show:** run `php artisan storage:link`, and check that `APP_URL` is the API's HTTPS address.
- **A 500 error:** read `storage/logs/laravel-YYYY-MM-DD.log`. Never set `APP_DEBUG=true` on the server.
- **Backups fail:** run `php artisan backup:run` by hand to see the error; usually a wrong bucket key or endpoint in `.env`.
