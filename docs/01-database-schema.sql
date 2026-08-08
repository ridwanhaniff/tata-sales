-- ============================================================================
-- TATA SALES — Supabase PostgreSQL Schema
-- Version: 1.0
-- Source: TATA Sales Product Blueprint, section 10-14 (core model) + additions
--         needed to make described behavior (scoring, workflow, AI logging,
--         webhook idempotency) actually enforceable.
--
-- CONVENTIONS
--   - PK: UUID via gen_random_uuid()
--   - Semua tabel tenant-scoped WAJIB punya tenant_id + terdaftar di blok RLS
--     paling bawah file ini.
--   - Uang: DECIMAL(18,2). Jangan pernah FLOAT (lihat blueprint §82).
--   - Waktu: TIMESTAMPTZ, disimpan UTC. tenant.timezone hanya dipakai untuk
--     konversi tampilan (lihat blueprint §87) — jangan hardcode Asia/Jakarta.
--   - Status/enum: VARCHAR + CHECK hanya untuk yang benar-benar fixed
--     (role, temperature, node_type, dst). Untuk yang harus configurable
--     per tenant (lead pipeline stage, promo discount_type) sengaja
--     TIDAK di-CHECK di level DB — validasi ada di application layer,
--     supaya "Configuration over custom code" (blueprint §5.2) tidak
--     butuh migration tiap kali tenant menambah stage baru.
--   - Custom field per industri (mobil/properti/wedding/dst) SELALU lewat
--     product_attributes, BUKAN kolom/tabel baru per vertical (blueprint §5.1).
-- ============================================================================

CREATE EXTENSION IF NOT EXISTS "pgcrypto";

CREATE OR REPLACE FUNCTION set_updated_at()
RETURNS TRIGGER AS $$
BEGIN
  NEW.updated_at = now();
  RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- ============================================================================
-- 1. TENANCY & IDENTITY  (blueprint §8, §9, §43)
-- ============================================================================

CREATE TABLE tenants (
  id                 UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  name               VARCHAR(255) NOT NULL,
  slug               VARCHAR(100) NOT NULL UNIQUE,
  domain             VARCHAR(255) UNIQUE,                 -- custom domain, nullable di MVP
  industry_template  VARCHAR(50),                          -- automotive-v1, property-v1, wedding-v1, ...
  timezone           VARCHAR(50) NOT NULL DEFAULT 'Asia/Jakarta',
  status             VARCHAR(20) NOT NULL DEFAULT 'active', -- active, trial, suspended
  plan               VARCHAR(50) NOT NULL DEFAULT 'starter',-- starter, growth, pro, enterprise (§129)
  settings           JSONB NOT NULL DEFAULT '{}',           -- scoring weights, branding, feature flags
  created_at         TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at         TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- users: role fixed sesuai §9 (super_admin punya tenant_id NULL)
CREATE TABLE users (
  id             UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id      UUID REFERENCES tenants(id) ON DELETE CASCADE,  -- NULL hanya untuk super_admin
  name           VARCHAR(255) NOT NULL,
  email          VARCHAR(255) NOT NULL,
  phone          VARCHAR(30),
  password_hash  VARCHAR(255) NOT NULL,
  role           VARCHAR(30) NOT NULL
                   CHECK (role IN ('super_admin','owner','manager','sales','content_manager')),
  status         VARCHAR(20) NOT NULL DEFAULT 'active',   -- active, suspended
  last_login_at  TIMESTAMPTZ,
  created_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at     TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX uq_users_tenant_email ON users(tenant_id, email);

-- ============================================================================
-- 2. PRODUCT  (blueprint §11 — generic, custom fields via product_attributes)
-- ============================================================================

CREATE TABLE product_categories (
  id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id   UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  parent_id   UUID REFERENCES product_categories(id) ON DELETE SET NULL,
  name        VARCHAR(255) NOT NULL,
  slug        VARCHAR(255) NOT NULL,
  created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
  UNIQUE(tenant_id, slug)
);

CREATE TABLE products (
  id                  UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id           UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  category_id         UUID REFERENCES product_categories(id) ON DELETE SET NULL,
  name                VARCHAR(255) NOT NULL,
  slug                VARCHAR(255) NOT NULL,
  description         TEXT,
  short_description   VARCHAR(500),
  base_price          DECIMAL(18,2) NOT NULL DEFAULT 0,
  status              VARCHAR(20) NOT NULL DEFAULT 'draft',      -- draft, published, archived
  stock_status        VARCHAR(20) NOT NULL DEFAULT 'available'
                        CHECK (stock_status IN ('available','low_stock','out_of_stock','preorder','hidden')), -- §84
  featured            BOOLEAN NOT NULL DEFAULT false,
  seo_title           VARCHAR(255),
  seo_description     VARCHAR(500),
  og_image_url        TEXT,
  published_at        TIMESTAMPTZ,
  created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
  UNIQUE(tenant_id, slug)
);

CREATE TABLE product_variants (
  id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id   UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  product_id  UUID NOT NULL REFERENCES products(id) ON DELETE CASCADE,
  name        VARCHAR(255) NOT NULL,
  sku         VARCHAR(100),
  price       DECIMAL(18,2) NOT NULL,
  stock       INT NOT NULL DEFAULT 0,
  status      VARCHAR(20) NOT NULL DEFAULT 'active',
  metadata    JSONB NOT NULL DEFAULT '{}',
  created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE product_images (
  id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id   UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  product_id  UUID NOT NULL REFERENCES products(id) ON DELETE CASCADE,
  variant_id  UUID REFERENCES product_variants(id) ON DELETE CASCADE,
  url         TEXT NOT NULL,
  alt_text    VARCHAR(255),
  sort_order  INT NOT NULL DEFAULT 0,
  created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Ini kunci "industry-agnostic core": engine mobil = tanpa tahu kolom
-- engine/transmission/seats. Semua lewat sini. (§5.1, §11)
CREATE TABLE product_attributes (
  id               UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id        UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  product_id       UUID NOT NULL REFERENCES products(id) ON DELETE CASCADE,
  attribute_key    VARCHAR(100) NOT NULL,        -- engine, land_size, guest_count, ...
  attribute_value  TEXT,
  attribute_type   VARCHAR(20) NOT NULL DEFAULT 'text' CHECK (attribute_type IN ('text','number','boolean','date')),
  created_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
  UNIQUE(product_id, attribute_key)
);

-- ============================================================================
-- 3. PROMO & VOUCHER  (blueprint §19-21)
-- ============================================================================

CREATE TABLE promotions (
  id                 UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id          UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  name               VARCHAR(255) NOT NULL,
  description        TEXT,
  discount_type      VARCHAR(20) NOT NULL,   -- percentage, fixed_amount, cashback, free_item, bundle, voucher, installment, custom
  discount_value     DECIMAL(18,2),
  minimum_purchase   DECIMAL(18,2),
  usage_limit        INT,
  usage_count        INT NOT NULL DEFAULT 0,
  starts_at          TIMESTAMPTZ NOT NULL,
  ends_at            TIMESTAMPTZ NOT NULL,
  status             VARCHAR(20) NOT NULL DEFAULT 'draft', -- draft, active, expired, disabled
  created_at         TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at         TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_promotions_active_window ON promotions(tenant_id, status, starts_at, ends_at);

CREATE TABLE promotion_products (
  id            UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id     UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  promotion_id  UUID NOT NULL REFERENCES promotions(id) ON DELETE CASCADE,
  product_id    UUID NOT NULL REFERENCES products(id) ON DELETE CASCADE,
  UNIQUE(promotion_id, product_id)
);

CREATE TABLE promotion_rules (
  id            UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id     UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  promotion_id  UUID NOT NULL REFERENCES promotions(id) ON DELETE CASCADE,
  rule_type     VARCHAR(30) NOT NULL, -- product, category, minimum_amount, date_range, customer_segment, location, quantity, campaign
  operator      VARCHAR(10) NOT NULL DEFAULT '=',
  value         JSONB NOT NULL,
  created_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE vouchers (
  id                  UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id           UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  promotion_id        UUID REFERENCES promotions(id) ON DELETE CASCADE,
  code                VARCHAR(50) NOT NULL,       -- e.g. TATA-A8F2
  discount_type       VARCHAR(20) NOT NULL,
  discount_value      DECIMAL(18,2),
  minimum_purchase    DECIMAL(18,2),
  usage_limit         INT,
  per_customer_limit  INT NOT NULL DEFAULT 1,
  usage_count         INT NOT NULL DEFAULT 0,
  expires_at          TIMESTAMPTZ,
  status              VARCHAR(20) NOT NULL DEFAULT 'active' CHECK (status IN ('active','disabled','expired')),
  created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
  UNIQUE(tenant_id, code)
);

-- ============================================================================
-- 4. LANDING PAGE / CONTENT  (blueprint §22, §53, §88-89)
-- ============================================================================

CREATE TABLE landing_pages (
  id                  UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id           UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  title               VARCHAR(255) NOT NULL,
  slug                VARCHAR(255) NOT NULL,
  template            VARCHAR(50),                -- automotive-v1, property-v1, ...
  status              VARCHAR(20) NOT NULL DEFAULT 'draft', -- draft, published, archived
  seo_title           VARCHAR(255),
  seo_description     VARCHAR(500),
  seo_keywords        VARCHAR(500),
  og_title            VARCHAR(255),
  og_image_url        TEXT,
  canonical_url       TEXT,
  published_at        TIMESTAMPTZ,
  created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
  UNIQUE(tenant_id, slug)
);

CREATE TABLE page_sections (
  id               UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id        UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  landing_page_id  UUID NOT NULL REFERENCES landing_pages(id) ON DELETE CASCADE,
  block_type       VARCHAR(30) NOT NULL,  -- hero, product, product_grid, promo, countdown, calculator, lead_form, testimonials, faq, banner, article, cta, whatsapp, chat, footer
  sort_order       INT NOT NULL DEFAULT 0,
  config           JSONB NOT NULL DEFAULT '{}',
  status           VARCHAR(20) NOT NULL DEFAULT 'active',
  created_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at       TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE media (
  id           UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id    UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  url          TEXT NOT NULL,
  type         VARCHAR(20) NOT NULL DEFAULT 'image', -- image, video, document
  mime_type    VARCHAR(100),
  size_bytes   BIGINT,
  alt_text     VARCHAR(255),
  uploaded_by  UUID REFERENCES users(id) ON DELETE SET NULL,
  created_at   TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ============================================================================
-- 5. CALCULATOR ENGINE  (blueprint §17-18, §82, §113 — deterministic only)
-- ============================================================================

CREATE TABLE calculators (
  id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id   UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  name        VARCHAR(255) NOT NULL,
  type        VARCHAR(50) NOT NULL,   -- credit, kpr, wedding_package, renovation_estimate, custom
  status      VARCHAR(20) NOT NULL DEFAULT 'active',
  created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE calculator_inputs (
  id             UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id      UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  calculator_id  UUID NOT NULL REFERENCES calculators(id) ON DELETE CASCADE,
  key            VARCHAR(100) NOT NULL,  -- price, dp, tenor, interest, guest_count, area, ...
  label          VARCHAR(255) NOT NULL,
  data_type      VARCHAR(20) NOT NULL DEFAULT 'number' CHECK (data_type IN ('number','select','boolean')),
  min_value      DECIMAL(18,2),
  max_value      DECIMAL(18,2),
  options        JSONB,                   -- untuk data_type = select
  is_required    BOOLEAN NOT NULL DEFAULT true,
  sort_order     INT NOT NULL DEFAULT 0,
  UNIQUE(calculator_id, key)
);

-- formula = referensi/expression yang dieval oleh CalculatorService (Laravel),
-- BUKAN oleh AI (§29 Agent 3, §64, §113: "tidak menggunakan AI untuk perhitungan")
CREATE TABLE calculator_rules (
  id                UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id         UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  calculator_id     UUID NOT NULL REFERENCES calculators(id) ON DELETE CASCADE,
  formula           TEXT NOT NULL,          -- contoh: "annuity(price - dp, interest, tenor)"
  rounding_policy   VARCHAR(20) NOT NULL DEFAULT 'round' CHECK (rounding_policy IN ('round','floor','ceil')),
  sort_order        INT NOT NULL DEFAULT 0,
  created_at        TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE calculator_outputs (
  id             UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id      UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  calculator_id  UUID NOT NULL REFERENCES calculators(id) ON DELETE CASCADE,
  key            VARCHAR(100) NOT NULL,   -- monthly_installment, total_payment, ...
  label          VARCHAR(255) NOT NULL,
  format         VARCHAR(20) NOT NULL DEFAULT 'currency' CHECK (format IN ('currency','number','text')),
  sort_order     INT NOT NULL DEFAULT 0
);

-- ============================================================================
-- 6. CUSTOMER  (blueprint §13-14, §81, §93-94)
-- ============================================================================

CREATE TABLE customers (
  id                  UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id           UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  name                VARCHAR(255),
  phone               VARCHAR(30),   -- dinormalisasi ke format 62xxxxxxxxxx sebelum insert (§81)
  email               VARCHAR(255),
  location            VARCHAR(255),
  source              VARCHAR(50),
  tags                TEXT[],
  notes               TEXT,
  consent_marketing   BOOLEAN NOT NULL DEFAULT false,  -- §91: jangan asumsikan lead otomatis consent
  consent_at          TIMESTAMPTZ,
  consent_version     VARCHAR(20),
  created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at          TIMESTAMPTZ NOT NULL DEFAULT now()
);
-- identity resolution utama = normalized_phone per tenant (§94)
CREATE UNIQUE INDEX uq_customers_tenant_phone ON customers(tenant_id, phone) WHERE phone IS NOT NULL;

-- ============================================================================
-- 7. CAMPAIGN & ATTRIBUTION  (blueprint §47, §90, §95-96)
-- ============================================================================

CREATE TABLE campaigns (
  id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id   UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  name        VARCHAR(255) NOT NULL,
  utm_campaign VARCHAR(255),
  status      VARCHAR(20) NOT NULL DEFAULT 'active',
  budget      DECIMAL(18,2),
  starts_at   TIMESTAMPTZ,
  ends_at     TIMESTAMPTZ,
  created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE campaign_sources (
  id               UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id        UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  campaign_id      UUID NOT NULL REFERENCES campaigns(id) ON DELETE CASCADE,
  utm_source       VARCHAR(100),
  utm_medium       VARCHAR(100),
  utm_content      VARCHAR(100),
  utm_term         VARCHAR(100),
  referrer         TEXT,
  landing_page_id  UUID REFERENCES landing_pages(id) ON DELETE SET NULL
);

-- Anonymous visitor tracking sebelum jadi lead (§46)
CREATE TABLE campaign_events (
  id           UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id    UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  campaign_id  UUID REFERENCES campaigns(id) ON DELETE SET NULL,
  visitor_id   VARCHAR(100),   -- anonymous id, di-set via cookie/localStorage sisi client
  event_type   VARCHAR(50) NOT NULL, -- page_view, product_view, promo_view, calculator_start, calculator_complete, form_start, form_complete, cta_click, whatsapp_click, chat_start
  event_data   JSONB NOT NULL DEFAULT '{}',
  occurred_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_campaign_events_tenant_type ON campaign_events(tenant_id, event_type, occurred_at);

-- ============================================================================
-- 8. LEAD  (blueprint §12, §15, §27, §75 — lihat juga 06-lead-state-machine)
-- ============================================================================

-- Pipeline harus configurable per tenant (§27, §75) — leads.status TIDAK
-- di-CHECK terhadap tabel ini secara hard FK supaya menambah stage baru
-- tidak butuh migration. Validasi transisi ada di LeadService (app layer).
CREATE TABLE pipeline_stages (
  id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id   UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  key         VARCHAR(50) NOT NULL,   -- NEW, CONTACTED, QUALIFIED, PROPOSAL, NEGOTIATION, WON, LOST, NURTURE
  label       VARCHAR(100) NOT NULL,
  sort_order  INT NOT NULL DEFAULT 0,
  is_won      BOOLEAN NOT NULL DEFAULT false,
  is_lost     BOOLEAN NOT NULL DEFAULT false,
  created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
  UNIQUE(tenant_id, key)
);

CREATE TABLE leads (
  id                  UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id           UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  customer_id         UUID NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
  product_id          UUID REFERENCES products(id) ON DELETE SET NULL,
  variant_id          UUID REFERENCES product_variants(id) ON DELETE SET NULL,
  source              VARCHAR(50),        -- form, whatsapp, chat, manual, api
  campaign_id         UUID REFERENCES campaigns(id) ON DELETE SET NULL,
  status              VARCHAR(30) NOT NULL DEFAULT 'NEW',   -- lihat pipeline_stages / state machine
  temperature         VARCHAR(10) NOT NULL DEFAULT 'COLD' CHECK (temperature IN ('COLD','WARM','HOT')), -- §15
  score               INT NOT NULL DEFAULT 0,
  estimated_value     DECIMAL(18,2),
  assigned_to         UUID REFERENCES users(id) ON DELETE SET NULL,
  provider_event_id   VARCHAR(255),       -- idempotency key dari channel eksternal (§79-80)
  last_activity_at    TIMESTAMPTZ,
  created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at          TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX uq_leads_provider_event ON leads(tenant_id, provider_event_id) WHERE provider_event_id IS NOT NULL;
CREATE INDEX idx_leads_tenant_status      ON leads(tenant_id, status);
CREATE INDEX idx_leads_tenant_assigned    ON leads(tenant_id, assigned_to);
CREATE INDEX idx_leads_tenant_temperature ON leads(tenant_id, temperature);
CREATE INDEX idx_leads_customer           ON leads(customer_id);

CREATE TABLE lead_events (
  id           UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id    UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  lead_id      UUID NOT NULL REFERENCES leads(id) ON DELETE CASCADE,
  event_type   VARCHAR(50) NOT NULL,  -- lead_created, product_viewed, promo_viewed, calculator_started, calculator_completed, form_started, form_completed, whatsapp_clicked, chat_started, sales_assigned, message_received, message_sent, followup_sent, quotation_created, quotation_viewed, won, lost
  event_data   JSONB NOT NULL DEFAULT '{}',
  occurred_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_lead_events_lead ON lead_events(lead_id, occurred_at);

CREATE TABLE lead_scores (
  id               UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id        UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  lead_id          UUID NOT NULL REFERENCES leads(id) ON DELETE CASCADE,
  event_type       VARCHAR(50) NOT NULL,
  points           INT NOT NULL,
  resulting_score  INT NOT NULL,
  created_at       TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE lead_assignments (
  id             UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id      UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  lead_id        UUID NOT NULL REFERENCES leads(id) ON DELETE CASCADE,
  assigned_to    UUID NOT NULL REFERENCES users(id),
  assigned_by    UUID REFERENCES users(id),   -- NULL = system-assigned
  method         VARCHAR(20) NOT NULL CHECK (method IN ('round_robin','product','location','workload','manual')),
  assigned_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
  unassigned_at  TIMESTAMPTZ
);

-- FK ke customers/leads, jadi baru bisa dibuat di sini walau secara modul
-- ini bagian dari Promo/Voucher engine (§21, §80, §93)
CREATE TABLE voucher_usages (
  id           UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id    UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  voucher_id   UUID NOT NULL REFERENCES vouchers(id) ON DELETE CASCADE,
  customer_id  UUID REFERENCES customers(id) ON DELETE SET NULL,
  lead_id      UUID REFERENCES leads(id) ON DELETE SET NULL,
  used_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- FK ke customers/leads — bagian dari Calculator engine (§18)
CREATE TABLE calculator_sessions (
  id             UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id      UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  calculator_id  UUID NOT NULL REFERENCES calculators(id),
  customer_id    UUID REFERENCES customers(id) ON DELETE SET NULL,
  lead_id        UUID REFERENCES leads(id) ON DELETE SET NULL,
  input_data     JSONB NOT NULL,
  output_data    JSONB NOT NULL,
  created_at     TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ============================================================================
-- 9. SALES  (blueprint §26, §50, §96-97)
-- ============================================================================

CREATE TABLE sales_teams (
  id                   UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id            UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  name                 VARCHAR(255) NOT NULL,   -- "Team Jakarta", "Sales Property"
  region               VARCHAR(100),
  product_category_id  UUID REFERENCES product_categories(id) ON DELETE SET NULL,
  created_at           TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Tidak eksplisit disebut nama tabelnya di blueprint, tapi wajib ada sebagai
-- junction table many-to-many antara sales_teams dan users.
CREATE TABLE sales_team_members (
  id             UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id      UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  sales_team_id  UUID NOT NULL REFERENCES sales_teams(id) ON DELETE CASCADE,
  user_id        UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE(sales_team_id, user_id)
);

CREATE TABLE sales_targets (
  id             UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id      UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  user_id        UUID REFERENCES users(id) ON DELETE CASCADE,
  sales_team_id  UUID REFERENCES sales_teams(id) ON DELETE CASCADE,
  period         VARCHAR(20) NOT NULL,   -- '2026-08'
  target_leads   INT,
  target_revenue DECIMAL(18,2),
  created_at     TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ============================================================================
-- 10. CONVERSATION & AI  (blueprint §24-25, §29-32, §61-63)
-- ============================================================================

CREATE TABLE conversations (
  id           UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id    UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  lead_id      UUID REFERENCES leads(id) ON DELETE CASCADE,
  customer_id  UUID REFERENCES customers(id) ON DELETE SET NULL,
  channel      VARCHAR(20) NOT NULL DEFAULT 'whatsapp' CHECK (channel IN ('whatsapp','webchat','email')),
  status       VARCHAR(20) NOT NULL DEFAULT 'AI_ACTIVE'
                 CHECK (status IN ('AI_ACTIVE','WAITING_HUMAN','HUMAN_ACTIVE','AI_RESUMED','CLOSED')), -- §61
  assigned_to  UUID REFERENCES users(id) ON DELETE SET NULL,
  context      JSONB NOT NULL DEFAULT '{}',  -- snapshot customer/product/promo/calculator/lead/campaign (§25)
  created_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at   TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE conversation_messages (
  id               UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id        UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  conversation_id  UUID NOT NULL REFERENCES conversations(id) ON DELETE CASCADE,
  sender_type      VARCHAR(20) NOT NULL CHECK (sender_type IN ('customer','ai','sales','system')),
  sender_id        UUID,           -- user id kalau sales, NULL kalau customer/ai
  content          TEXT NOT NULL,
  intent           VARCHAR(30),    -- price, availability, promotion, installment, location, specification, comparison, purchase_intent, support, complaint (§16)
  metadata         JSONB NOT NULL DEFAULT '{}',
  created_at       TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_conv_messages_conv ON conversation_messages(conversation_id, created_at);

-- Observability wajib per AI action (§63): tenant_id, conversation_id,
-- lead_id, agent, input, tool, output, timestamp, status.
CREATE TABLE ai_agent_logs (
  id               UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id        UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  conversation_id  UUID REFERENCES conversations(id) ON DELETE SET NULL,
  lead_id          UUID REFERENCES leads(id) ON DELETE SET NULL,
  agent            VARCHAR(50) NOT NULL,  -- intent, product, calculator, qualification, recommendation, followup, handoff
  tool_called      VARCHAR(100),
  input            JSONB,
  output           JSONB,
  confidence       DECIMAL(4,3),
  status           VARCHAR(20) NOT NULL CHECK (status IN ('success','failed','denied','handoff')),
  latency_ms       INT,
  created_at       TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_ai_agent_logs_conv ON ai_agent_logs(conversation_id, created_at);

-- ============================================================================
-- 11. FOLLOW-UP  (blueprint §28)
-- ============================================================================

CREATE TABLE followups (
  id            UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id     UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  lead_id       UUID NOT NULL REFERENCES leads(id) ON DELETE CASCADE,
  assigned_to   UUID REFERENCES users(id) ON DELETE SET NULL,
  status        VARCHAR(20) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','sent','skipped','failed')),
  channel       VARCHAR(20) NOT NULL DEFAULT 'whatsapp',
  scheduled_at  TIMESTAMPTZ NOT NULL,
  sent_at       TIMESTAMPTZ,
  message       TEXT,
  created_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_followups_due ON followups(tenant_id, status, scheduled_at);

CREATE TABLE followup_steps (
  id             UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id      UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  name           VARCHAR(255) NOT NULL,    -- "30 minutes reminder", "Day 1 follow-up"
  trigger_event  VARCHAR(50) NOT NULL,     -- lead.created, no_contact, ...
  delay_minutes  INT NOT NULL,
  condition      JSONB,                    -- {"no_contact": true}
  action         VARCHAR(30) NOT NULL DEFAULT 'create_followup',
  sort_order     INT NOT NULL DEFAULT 0,
  status         VARCHAR(20) NOT NULL DEFAULT 'active'
);

-- ============================================================================
-- 12. QUOTATION  (blueprint §99)
-- Catatan desain: blueprint §100 menyinggung "deals" sebagai objek terpisah
-- di Phase 2. Di skema ini SENGAJA tidak dibuat tabel `deals` tersendiri —
-- leads.status (WON/LOST) + quotations sudah menangkap pipeline & value.
-- Tabel deals terpisah baru relevan kalau satu lead butuh >1 deal independen
-- (Phase 4/5, upsell/renewal) — tambahkan saat itu benar-benar dibutuhkan.
-- ============================================================================

CREATE TABLE quotations (
  id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id       UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  lead_id         UUID REFERENCES leads(id) ON DELETE SET NULL,
  customer_id     UUID NOT NULL REFERENCES customers(id),
  created_by      UUID REFERENCES users(id) ON DELETE SET NULL,
  status          VARCHAR(20) NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','sent','viewed','accepted','rejected','expired')),
  subtotal        DECIMAL(18,2) NOT NULL DEFAULT 0,
  discount_total  DECIMAL(18,2) NOT NULL DEFAULT 0,
  total           DECIMAL(18,2) NOT NULL DEFAULT 0,
  notes           TEXT,
  valid_until     TIMESTAMPTZ,
  sent_at         TIMESTAMPTZ,
  viewed_at       TIMESTAMPTZ,
  created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE quotation_items (
  id             UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id      UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  quotation_id   UUID NOT NULL REFERENCES quotations(id) ON DELETE CASCADE,
  product_id     UUID REFERENCES products(id) ON DELETE SET NULL,
  variant_id     UUID REFERENCES product_variants(id) ON DELETE SET NULL,
  description    VARCHAR(500) NOT NULL,
  quantity       INT NOT NULL DEFAULT 1,
  unit_price     DECIMAL(18,2) NOT NULL,
  discount       DECIMAL(18,2) NOT NULL DEFAULT 0,
  line_total     DECIMAL(18,2) NOT NULL
);

-- ============================================================================
-- 13. ACTIVITY, TASK, NOTE  (blueprint §83, §98)
-- ============================================================================

CREATE TABLE activities (
  id             UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id      UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  lead_id        UUID REFERENCES leads(id) ON DELETE CASCADE,
  customer_id    UUID REFERENCES customers(id) ON DELETE SET NULL,
  user_id        UUID REFERENCES users(id) ON DELETE SET NULL,  -- NULL = system/AI
  activity_type  VARCHAR(50) NOT NULL,  -- call, note, status_change, email, whatsapp, meeting, site_visit, test_drive
  description    TEXT,
  metadata       JSONB NOT NULL DEFAULT '{}',
  created_at     TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE tasks (
  id            UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id     UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  lead_id       UUID REFERENCES leads(id) ON DELETE CASCADE,
  assigned_to   UUID REFERENCES users(id) ON DELETE SET NULL,
  title         VARCHAR(255) NOT NULL,
  description   TEXT,
  due_at        TIMESTAMPTZ,
  status        VARCHAR(20) NOT NULL DEFAULT 'open' CHECK (status IN ('open','done','cancelled')),
  created_by    UUID REFERENCES users(id) ON DELETE SET NULL,
  created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
  completed_at  TIMESTAMPTZ
);

CREATE TABLE notes (
  id           UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id    UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  lead_id      UUID REFERENCES leads(id) ON DELETE CASCADE,
  customer_id  UUID REFERENCES customers(id) ON DELETE SET NULL,
  user_id      UUID REFERENCES users(id) ON DELETE SET NULL,
  content      TEXT NOT NULL,
  created_at   TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ============================================================================
-- 14. NOTIFICATION  (blueprint §60)
-- ============================================================================

CREATE TABLE notifications (
  id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id   UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  user_id     UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  channel     VARCHAR(20) NOT NULL DEFAULT 'dashboard' CHECK (channel IN ('dashboard','email','whatsapp','webhook')),
  type        VARCHAR(50) NOT NULL,   -- new_hot_lead, lead_assigned, followup_due, quotation_viewed, ...
  title       VARCHAR(255) NOT NULL,
  body        TEXT,
  data        JSONB NOT NULL DEFAULT '{}',
  read_at     TIMESTAMPTZ,
  sent_at     TIMESTAMPTZ,
  created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_notifications_user_unread ON notifications(user_id, read_at);

-- ============================================================================
-- 15. WORKFLOW ENGINE  (blueprint §33-34, §76)
-- ============================================================================

CREATE TABLE workflows (
  id             UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id      UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  name           VARCHAR(255) NOT NULL,
  trigger_event  VARCHAR(50) NOT NULL,   -- lead.created, message.received, ...
  status         VARCHAR(20) NOT NULL DEFAULT 'active' CHECK (status IN ('active','paused','draft')),
  definition     JSONB NOT NULL,         -- full workflow DSL, lihat 05-agentic-workflow.md §Workflow DSL
  created_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at     TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE workflow_nodes (
  id            UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id     UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  workflow_id   UUID NOT NULL REFERENCES workflows(id) ON DELETE CASCADE,
  node_type     VARCHAR(20) NOT NULL CHECK (node_type IN ('trigger','condition','action','delay','ai','human','end')),
  config        JSONB NOT NULL DEFAULT '{}',
  sort_order    INT NOT NULL DEFAULT 0,
  next_node_id  UUID REFERENCES workflow_nodes(id) ON DELETE SET NULL
);

CREATE TABLE workflow_runs (
  id               UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id        UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  workflow_id      UUID NOT NULL REFERENCES workflows(id) ON DELETE CASCADE,
  lead_id          UUID REFERENCES leads(id) ON DELETE SET NULL,
  conversation_id  UUID REFERENCES conversations(id) ON DELETE SET NULL,
  status           VARCHAR(20) NOT NULL DEFAULT 'running' CHECK (status IN ('running','completed','failed','cancelled')),
  current_node_id  UUID REFERENCES workflow_nodes(id) ON DELETE SET NULL,
  started_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
  finished_at      TIMESTAMPTZ
);

CREATE TABLE workflow_logs (
  id               UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id        UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  workflow_run_id  UUID NOT NULL REFERENCES workflow_runs(id) ON DELETE CASCADE,
  node_id          UUID REFERENCES workflow_nodes(id) ON DELETE SET NULL,
  status           VARCHAR(20) NOT NULL CHECK (status IN ('success','failed','skipped')),
  input            JSONB,
  output           JSONB,
  error            TEXT,
  created_at       TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ============================================================================
-- 16. AUDIT & WEBHOOK  (blueprint §79-80, §83, §118-120)
-- ============================================================================

CREATE TABLE audit_logs (
  id           UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id    UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  actor_id     UUID REFERENCES users(id) ON DELETE SET NULL,  -- NULL = system/AI
  actor_type   VARCHAR(20) NOT NULL DEFAULT 'user' CHECK (actor_type IN ('user','system','ai')),
  action       VARCHAR(100) NOT NULL,  -- promo.updated, lead.reassigned, quotation.edited, voucher.deleted, permission.changed
  entity_type  VARCHAR(50) NOT NULL,
  entity_id    UUID,
  before_data  JSONB,
  after_data   JSONB,
  ip_address   INET,
  created_at   TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_audit_logs_entity ON audit_logs(tenant_id, entity_type, entity_id);

-- Idempotency untuk webhook masuk (WhatsApp/payment/CRM) — §79-80.
-- "System harus tetap membuat 1 lead saja, bukan 2 lead" walau event
-- dikirim dua kali oleh provider.
CREATE TABLE webhook_events (
  id                  UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id           UUID REFERENCES tenants(id) ON DELETE CASCADE,
  provider            VARCHAR(50) NOT NULL,   -- whatsapp, meta, google, payment, crm
  provider_event_id   VARCHAR(255) NOT NULL,
  payload             JSONB NOT NULL,
  status              VARCHAR(20) NOT NULL DEFAULT 'received' CHECK (status IN ('received','processed','failed','duplicate')),
  processed_at        TIMESTAMPTZ,
  created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
  UNIQUE(provider, provider_event_id)
);

-- ============================================================================
-- 17. TENANT ISOLATION (RLS)  (blueprint §45)
-- ============================================================================
-- Auth pakai Laravel Auth, bukan Supabase Auth (§43), jadi RLS di sini TIDAK
-- pakai auth.uid()/JWT bawaan Supabase. Sebagai gantinya, middleware Laravel
-- (lihat 04-laravel-architecture.md → SetTenantContext) menjalankan:
--
--     SET LOCAL app.tenant_id = '<uuid-tenant-user-yang-login>';
--
-- di awal setiap request/transaction. RLS ini adalah DEFENSE IN DEPTH —
-- lapisan pengaman kalau ada query yang lolos dari Eloquent global scope,
-- BUKAN pengganti tenant scoping di application layer.
-- ============================================================================

DO $$
DECLARE
  t TEXT;
  tenant_tables TEXT[] := ARRAY[
    'users','product_categories','products','product_variants','product_images',
    'product_attributes','promotions','promotion_products','promotion_rules',
    'vouchers','landing_pages','page_sections','media','calculators',
    'calculator_inputs','calculator_rules','calculator_outputs','customers',
    'campaigns','campaign_sources','campaign_events','pipeline_stages','leads',
    'lead_events','lead_scores','lead_assignments','voucher_usages',
    'calculator_sessions','sales_teams','sales_team_members','sales_targets',
    'conversations','conversation_messages','ai_agent_logs','followups',
    'followup_steps','quotations','quotation_items','activities','tasks',
    'notes','notifications','workflows','workflow_nodes','workflow_runs',
    'workflow_logs','audit_logs'
  ];
BEGIN
  FOREACH t IN ARRAY tenant_tables LOOP
    EXECUTE format('CREATE INDEX IF NOT EXISTS idx_%s_tenant ON %I(tenant_id);', t, t);
    EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY;', t);
    EXECUTE format(
      'CREATE POLICY tenant_isolation ON %I USING (tenant_id = current_setting(''app.tenant_id'', true)::uuid);',
      t
    );
  END LOOP;
END $$;

-- webhook_events: tenant_id nullable (event bisa datang sebelum tenant
-- teridentifikasi dari payload), jadi RLS-nya permisif — enforcement penuh
-- ada di application layer saat proses webhook.
CREATE INDEX idx_webhook_events_tenant ON webhook_events(tenant_id);

-- ============================================================================
-- 18. updated_at TRIGGER
-- ============================================================================

DO $$
DECLARE
  t TEXT;
  updated_at_tables TEXT[] := ARRAY[
    'tenants','users','product_categories','products','product_variants',
    'promotions','vouchers','landing_pages','page_sections','calculators',
    'customers','campaigns','leads','conversations','quotations','workflows'
  ];
BEGIN
  FOREACH t IN ARRAY updated_at_tables LOOP
    EXECUTE format(
      'CREATE TRIGGER trg_set_updated_at BEFORE UPDATE ON %I FOR EACH ROW EXECUTE FUNCTION set_updated_at();',
      t
    );
  END LOOP;
END $$;

-- ============================================================================
-- END OF SCHEMA — 49 tabel total.
-- Seed berikutnya yang dibutuhkan sebelum aplikasi jalan:
--   1. pipeline_stages default (NEW, CONTACTED, QUALIFIED, PROPOSAL,
--      NEGOTIATION, WON, LOST, NURTURE) per tenant baru — lihat
--       06-lead-state-machine.md.
--   2. Template default per vertical (automotive-v1, property-v1, ...)
--      — lihat blueprint §54, §132.
-- ============================================================================
