# TATA Sales — Multi-Tenant Sales & CRM Engine

Sistem penjualan multi-tenant (SaaS) dengan pipeline lead, landing page builder, WhatsApp integration, agent AI (intent/qualification/follow-up/handoff), quotation engine, voucher & promo, analytics, dan webhook in/out — berbasis Laravel + PostgreSQL (Row-Level Security untuk isolasi tenant).

## Fitur utama

- **Lead pipeline**: capture (form/WA/chat/API/CRM), scoring otomatis, assignment otomatis, state machine `NEW → CONTACTED → QUALIFIED → PROPOSAL → NEGOTIATION → WON/LOST` (+ custom pipeline per tenant).
- **Landing page builder**: section blocks (hero, banner, produk, FAQ), render publik via subdomain tenant (`/l/{slug}`), SEO, event tracking.
- **WhatsApp**: abstraction provider (`echo` dev / `meta` Cloud API), webhook pesan masuk & status keluar, CTA `wa.me`.
- **Agent AI**: Intent, Qualification, Follow-up, Handoff — tool-based, tenant context di-inject dari server (bukan dari LLM).
- **Quotation engine**: draft → sent → viewed → accepted/rejected/expired, link publik per token, integrasi state machine lead.
- **Webhook**: inbound (WA/payment/CRM) dengan HMAC + idempotency; outbound (`lead.created`, `lead.updated`, `deal.won`, `deal.lost`, `quotation.*`, `notification.sent`) via queue + retry/backoff.
- **Penunjang**: voucher, promo, kalkulator, campaign + UTM attribution, follow-up otomatis, workflow, knowledge base, sales team & target, dashboard & analytics.

## Persyaratan

| Tool | Versi |
|---|---|
| PHP | 8.3+ (dikembangkan di 8.5) |
| Composer | 2.x |
| PostgreSQL | 14+ (RLS membutuhkan superuser saat setup awal) |
| Node.js | 20+ |

## Setup (lokal)

```bash
# 1. Dependensi
composer install
npm install

# 2. Environment
cp .env.example .env
php artisan key:generate
```

Konfigurasi `.env` (contoh):

```env
APP_URL=http://localhost
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=tata_sales
DB_USERNAME=tata_app
DB_PASSWORD=tata_app_dev
QUEUE_CONNECTION=database
```

### Database & RLS

Isolasi tenant memakai PostgreSQL **Row-Level Security**. Setup sekali lewat superuser Postgres:

```sql
CREATE DATABASE tata_sales;
-- role & grant dibuat otomatis oleh migration 000019/000020,
-- tapi pastikan user migrasi punya CREATEROLE
```

> **Dev lokal**: untuk queue worker / scheduler / seeding CLI, role aplikasi butuh akses penuh tanpa RLS (context tenant hanya di-set per-request HTTP oleh middleware):
> ```sql
> ALTER ROLE tata_app BYPASSRLS;   -- hanya untuk development!
> ```

### Migrasi, seed, build, jalankan

```bash
php artisan migrate --seed          # seeder: tenant demo-auto + tenant-b, user demo, pipeline, landing, kalkulator
npm run build                       # atau npm run dev

php artisan serve --port=8000       # web server
php artisan queue:work --queue=default   # worker (webhook, follow-up)
php artisan schedule:work           # scheduler: followups:send, quotations:expire
```

### Kredensial demo

- Owner: `owner@demo.tatasales.test` / `password`
- Sales: `sales@demo.tatasales.test` / `password`

Landing page demo: `http://127.0.0.1:8000/l/home` (tenant `demo-auto` diset `domain=127.0.0.1` di seeder agar resolusi tenant lokal berfungsi).

## Testing

```bash
php artisan test        # 394+ test (Feature + Unit)
vendor/bin/pint         # code style
```

## Arsitektur

- Multi-tenant: tabel tenant-scoped + RLS `app.tenant_id` (per-request via middleware), Eloquent global scope `tenant`.
- Kontrak API: `docs/03-api-contract.md`. State machine: `docs/06-lead-state-machine.md`. Sprint plan: `docs/07-sprint-plan.md`.
- Webhook keluar dikonfigurasi per tenant via `tenants.settings.webhook.{url,secret}`; masuk via `tenants.settings.webhook.inbound_secret`.

## Lisensi

Proprietary — hak cipta pemilik proyek.
