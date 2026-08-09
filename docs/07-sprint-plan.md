# TATA Sales — Sprint Plan

Sprint 2 minggu. Urutan mengikuti dependency development order blueprint §110 (`Setup → Database → Tenant → Auth → Product → Public website → Lead → WhatsApp → Admin → Sales assignment → Calculator → Promo → Analytics → Workflow → Follow-up → AI → Integrations`) dan MVP scoping §104-107. Tiap sprint yang menutup sebuah MVP mencantumkan item Definition of Done dari §142 yang selesai di sprint itu.

## Sprint 0 (1 minggu) — Project Setup

**Goal:** repo & environment siap, skema jalan, tenant isolation teruji sejak hari pertama.

- Init Laravel repo sesuai `04-laravel-architecture.md`, CI dasar (lint + test)
- Setup project Supabase, jalankan `01-database-schema.sql`
- `Tenant` model + `ResolveTenant` + `SetTenantContext` middleware + trait `BelongsToTenant`
- `tests/Feature/TenantIsolationTest.php` — tenant A tidak bisa baca data tenant B (jalan dari sprint ini, terus diperluas tiap sprint berikutnya)
- Response envelope + error handler standar
- Staging environment + deploy pipeline (shared hosting sesuai §70, atau VPS kalau koneksi Postgres langsung tidak didukung shared hosting)

## Sprint 1 — Tenant, Auth, Product Core

- CRUD Tenant (super admin)
- Login/logout, role middleware (`super_admin/owner/manager/sales/content_manager`)
- CRUD Product/ProductCategory/ProductVariant/ProductAttribute (admin)
- Upload gambar produk ke Supabase Storage, optimasi WebP (§38)
- `GET /products`, `GET /products/{slug}` (public)
- Perluas test tenant isolation ke tabel product

## Sprint 2 — Landing Page & Public Website

- Landing page builder: block Hero, Product, Product Grid, Banner, FAQ, Footer (subset dari §22, sisanya sprint berikutnya)
- Render landing page (Blade + Tailwind + Alpine.js), mobile-first (§23)
- SEO fields (`seo_title`, `seo_description`, OG) per landing page & produk
- Event tracking dasar: `page_view`, `product_view` → `campaign_events`

## Sprint 3 — Promo & Calculator (v1)

- CRUD Promotion + `promotion_rules` (kondisi product/category/date_range)
- Validasi window aktif promo di server (§85) — tidak pernah tampil sebelum start / setelah expired
- CRUD Calculator (`calculator_inputs/rules/outputs`) — 1 tipe calculator end-to-end (mis. credit calculator automotive)
- `CalculatorService` deterministic + `calculator_sessions` tersimpan
- `POST /calculators/{id}/calculate`

## Sprint 4 — Lead Capture, Lead Dashboard, WhatsApp CTA

- `POST /leads` full pipeline: validate → normalize phone → find/create customer → create lead → score → assign → log activity → notify sales (§112)
- `LeadScoringService` rule-based (§15), bobot configurable per tenant
- `AssignmentService` — mulai dari `round_robin`
- Lead Dashboard (filter status/temperature/sales/product/campaign/date) + Sales Dashboard ("my leads")
- WhatsApp CTA dengan pesan kontekstual (produk + hasil kalkulator, §24)
- `lead_events` logging penuh

## Sprint 5 — Analytics & MVP1 Hardening

- Admin analytics dashboard: revenue potential, leads, hot leads, conversion rate, sales response time, WA clicks, calculator completion, top products/campaigns
- `AttributionService` — capture UTM + referrer + landing_page saat lead dibuat
- `audit_logs` untuk aksi kritikal (perubahan promo, harga, reassign lead)
- Full regression tenant isolation suite
- **MVP1 Definition of Done** (§142 item 1-14): tenant dibuat, owner login, product dibuat & publish, visitor lihat product/promo, calculator jalan, lead submit & masuk DB & ter-score & ter-assign, sales lihat lead, WA context, admin lihat analytics

**→ MVP1 SHIP**

## Sprint 6 — Voucher & Sales Pipeline

- Voucher engine: generate kode, redeem, `usage_limit`/`per_customer_limit`, expiration (§21, §114)
- `pipeline_stages` CRUD (custom pipeline per tenant, §27)
- `LeadService::transition()` — validasi state machine (lihat `06-lead-state-machine.md`), termasuk aturan WON/LOST terminal
- Customer 360 view (journey timeline, §98)

## Sprint 7 — Workflow Engine v1 & Follow-up

- `workflows`/`workflow_nodes` storage + `WorkflowEngine` (node `trigger/condition/action/delay/end`; node `ai`/`human` di-stub, diisi penuh di Sprint 9-11)
- `followup_steps` rule engine + `SendFollowupJob` terjadwal (cron + queue, §28, §40-41)
- Notification system: channel dashboard + email
- CRUD Campaign, `campaign_sources` lengkap

## Sprint 8 — Assignment v2, Notifikasi, MVP2 Hardening

- Assignment by `product`/`location`/`workload` (§26)
- `sales_teams`/`sales_targets`
- Channel notifikasi WhatsApp + webhook
- `webhook_events` idempotency untuk WA masuk (§79-80)
- **MVP2 Definition of Done**: voucher jalan, pipeline custom jalan, workflow dasar jalan tanpa AI (§142 item 18), follow-up terkirim sesuai jadwal, notifikasi sampai ke sales

**→ MVP2 SHIP**

## Sprint 9 — AI Foundation: Provider Abstraction + Intent & Product Agent

- `LLMProvider` interface + adapter (minimal 1 provider aktif, interface siap multi-provider, §65)
- `AgentInterface` + kerangka tool-calling
- Intent Agent (klasifikasi + confidence)
- Product Agent + tools `search_products`, `get_product`, `get_promotion`
- `ai_agent_logs` wiring
- Test guardrail: AI tidak bisa mengarang harga/stok (assert lewat tool-only access)

## Sprint 10 — Calculator Agent, Qualification Agent, Conversation Context

- Calculator Agent (tool `calculate`, tidak menghitung manual)
- Qualification Agent (`budget/timeline/location/product_interest/purchase_intent`)
- Context object assembly per §25 (`customer/product/promotion/calculator/lead/campaign`)
- State `conversations.status` (`AI_ACTIVE/WAITING_HUMAN/HUMAN_ACTIVE/AI_RESUMED`)

## Sprint 11 — Follow-up Agent, Handoff Agent, Knowledge Base v1

- Follow-up Agent — copywriting dalam batas jadwal rule-based, tidak pernah mengirim bebas
- Handoff Agent + confidence threshold + trigger table (`05-agentic-workflow.md` §6)
- Knowledge base terstruktur (FAQ/policy/script) + retrieval sederhana (belum vector/RAG — §66)
- Test prompt-injection & failure-handling fallback (§62, §118)
- **MVP3 Definition of Done**: AI menjawab dari data approved saja, handoff berfungsi, semua aksi AI ter-log, AI tidak bisa cross-tenant

**→ MVP3 SHIP**

## Sprint 12+ (Backlog Phase 4) — pasca-MVP3

- WhatsApp Business API (ganti link sederhana) — **SELESAI**: provider abstraction (`echo` di dev / `meta` Cloud API), `WhatsAppService` outbox (`whatsapp_messages`), webhook status pesan, follow-up & quotation terkirim via provider, CTA `wa.me` tetap jadi fallback landing (§24-25)
- Quotation engine penuh (§99) + status `sent/viewed/accepted` — **SELESAI**: create→send (token publik)→viewed→accepted/rejected/expired, link publik + respond, state machine (PROPOSAL→NEGOTIATION→WON/LOST), notification + WhatsApp share
- Advanced analytics: funnel lengkap, win rate, campaign ROI — **SELESAI**: `/admin/analytics/pipeline`, `/win-rate`, `/campaign-roi` (§95)
- Integrasi CRM/Meta Ads/Google Ads (§78) — webhook `crm` sudah diverifikasi; konektor kerja penuh masih backlog
- RAG penuh untuk knowledge base bila volume FAQ/dokumen sudah besar — gated oleh volume (§66)

Tidak masuk cakupan sprint manapun di atas secara sengaja (§143): omnichannel penuh, marketplace template, AI autonomous agent penuh, billing kompleks, mobile app, microservices — baru relevan di Phase 5 (§108, §130-131) setelah traffic/kebutuhan riil menuntutnya.
