# DEPLOY — VPS / Laravel Cloud

> **Target:** VPS Linux (Ubuntu/Debian) dengan PHP 8.3+, Nginx, Supervisor.
> **Database:** Supabase Postgres yang sudah ada (`.env` saat ini) — server tidak perlu Postgres lokal.
> **Setup sekali (±15 menit), deploy berikutnya hanya `./deploy/deploy.sh fresh`.**

---

## 1. Prasyarat server (sekali)

```bash
# PHP 8.3 + ekstensi yang dibutuhkan Laravel/pgsql + tooling
sudo apt update
sudo apt install -y nginx git curl software-properties-common supervisor
sudo add-apt-repository -y ppa:ondrej/php
sudo apt install -y php8.3-fpm php8.3-cli php8.3-pgsql php8.3-mbstring \
    php8.3-intl php8.3-curl php8.3-bcmath php8.3-xml php8.3-zip php8.3-gd

# Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# Node 20+ (untuk build asset saja)
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt install -y nodejs

php -v && composer --version && node -v
```

## 2. Ambil kode

```bash
sudo mkdir -p /var/www/tata-sales
sudo chown -R $USER /var/www/tata-sales
cd /var/www/tata-sales
git clone https://github.com/ridwanhaniff/tata-sales.git .
```

## 3. Environment

```bash
cp deploy/.env.production.example .env
sudo nano .env        # isi: APP_URL, DB_URL (Supabase), LLM_API_KEY, dll.
php artisan key:generate
```

> **Supabase:** pakai kredensial yang sama dengan dev (username `postgres` = owner → RLS ter-bypass, isolasi tetap di lapisan aplikasi — konsisten dengan dev).
> **WA number untuk CTA landing:** set nanti di `tenants.settings.whatsapp` (belum ada endpoint admin).

## 4. Nginx

```bash
sudo cp deploy/nginx.conf /etc/nginx/sites-available/tata-sales
sudo nano /etc/nginx/sites-available/tata-sales   # ganti your-domain.com & path PHP-FPM
sudo ln -s /etc/nginx/sites-available/tata-sales /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx
```

## 5. HTTPS (certbot)

```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d your-domain.com -d www.your-domain.com
```

## 6. Queue worker + scheduler

```bash
sudo cp deploy/supervisor-worker.conf /etc/supervisor/conf.d/tata-sales-worker.conf
# cek versi php-fpm di file, lalu:
sudo supervisorctl reread && sudo supervisorctl update
sudo supervisorctl status tata-sales-worker   # → RUNNING

# Scheduler (follow-ups, quotation expire) via cron www-data:
sudo crontab -u www-data -e
# * * * * * cd /var/www/tata-sales && php artisan schedule:run >> /dev/null 2>&1
```

## 7. Deploy (migrate pertama + asset)

```bash
cd /var/www/tata-sales
sudo chown -R www-data:www-data storage bootstrap/cache
sudo ./deploy/deploy.sh fresh
```

Verifikasi:

```bash
curl -sI https://your-domain.com          # → 200
curl -s https://your-domain.com/l/home    # halaman landing (butuh tenant + domain tenant, lihat bawah)
sudo tail -f /var/www/tata-sales/storage/logs/laravel.log
```

## 8. Setelah deploy

| Item | Langkah |
|---|---|
| Tenant pertama | Insert via API/auth atau langsung di Supabase: `tenants` (slug, domain=<domain-anda>, settings `{"whatsapp":"62812xxxxx"}`) + user owner |
| Landing /l/home | Ikuti pola seeder `LandingPageSeeder` (page + sections status active) atau seed demo: `sudo -u www-data php artisan db:seed --force` |
| WhatsApp CTA | `tenants.settings.whatsapp` → tombol "Hubungi Sales" + floating button aktif |
| Webhook keluar/masuk | Via API `PUT /admin/settings/webhook` (HMAC secret otomatis) |
| LLM/AI | sudah via `LLM_API_KEY` di `.env` |

## Deploy rutin berikutnya

```bash
cd /var/www/tata-sales
sudo ./deploy/deploy.sh fresh      # pull + build + optimize + migrate
```

Gunakan `./deploy/deploy.sh deploy` (tanpa `fresh`) jika tidak ada perubahan migration.

## Rollback

```bash
cd /var/www/tata-sales
git log --oneline -5
git checkout <commit-sebelumnya> -- .
sudo ./deploy/deploy.sh deploy
# migration rollback terakhir hanya jika perlu:
sudo -u www-data php artisan migrate:rollback --step=1 --force
```