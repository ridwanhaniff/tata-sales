# TATA Sales — Technical Delivery Package

Paket ini menerjemahkan blueprint produk **TATA Sales v1.0** menjadi artefak teknis yang bisa langsung dipakai tim engineering. Semua desain mengikuti prinsip inti blueprint:

- **Industry-agnostic core** — core engine hanya tahu `Product / Offer / Customer / Lead / Campaign / Conversation / Sales / Workflow / Transaction`. Kekhususan vertikal (mobil, properti, wedding, dst) hidup di `product_attributes`, template, dan config — bukan di skema baru.
- **Configuration over custom code** — field khusus industri = custom fields, bukan tabel baru.
- **Modular monolith** — satu codebase Laravel, dipecah per modul (Product, Lead, Promo, Calculator, Sales, Workflow, AI, Analytics), bukan microservices sejak awal.
- **Rules first, AI second** — semua perhitungan finansial, validasi stok/promo, dan CRUD sederhana pakai deterministic code. AI dipakai untuk intent, qualification, summarization, dan follow-up copy — selalu lewat tool call yang di-log, tidak pernah mengarang data.
- **Tenant isolation wajib di server** — tidak boleh mengandalkan frontend.

## Isi paket

| File | Isi |
|---|---|
| `01-database-schema.sql` | DDL lengkap PostgreSQL/Supabase — 49 tabel, index, RLS policy, trigger `updated_at`. Sumber kebenaran untuk ERD. |
| `02-erd-diagram.mermaid` | Visual ERD untuk entitas inti (subset dari 49 tabel — yang jarang di-query relasinya secara langsung disederhanakan/diringkas). |
| `03-api-contract.md` | Kontrak REST API — Public API, Admin API, Webhook masuk/keluar, konvensi error & pagination. |
| `04-laravel-architecture.md` | Struktur folder Laravel, pemetaan modul, pola Service/Action/Agent, mekanisme tenant isolation. |
| `05-agentic-workflow.md` | Spesifikasi 7 AI agent, kontrak tool calling, workflow DSL, guardrail, alur end-to-end. |
| `06-lead-state-machine.md` + `.mermaid` | State machine lead (pipeline) lengkap dengan trigger, guard, dan efek samping tiap transisi. |
| `07-sprint-plan.md` | Urutan development per sprint, dari project setup sampai MVP3 (AI), mengikuti urutan dependency di blueprint. |

## Cara pakai `01-database-schema.sql`

1. Buat project Supabase baru (atau gunakan yang sudah ada).
2. Jalankan file ini lewat Supabase SQL Editor, atau pecah jadi migration Laravel (`php artisan make:migration`) jika tim lebih nyaman migration-per-tabel — strukturnya sudah diurutkan sesuai dependency FK sehingga aman dijalankan top-to-bottom.
3. Karena auth memakai **Laravel Auth** (bukan Supabase Auth — lihat `04-laravel-architecture.md`), RLS di file ini memakai session variable `app.tenant_id` yang di-set oleh middleware Laravel per-request, bukan `auth.uid()` bawaan Supabase.

## North Star Metric

**Qualified Leads → Sales Conversion.** Metric pendukung: Lead-to-Sale Conversion Rate, Revenue Influenced, Sales Response Time. Semua keputusan desain di paket ini (index di `leads.status`/`assigned_to`, event `lead_events`, `ai_agent_logs`) diarahkan untuk membuat metric ini terukur sejak MVP1.

## Urutan baca yang disarankan

`README` → `database-schema.sql` + `erd-diagram` → `laravel-architecture` → `api-contract` → `lead-state-machine` → `agentic-workflow` → `sprint-plan`.
