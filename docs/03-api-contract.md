# TATA Sales — API Contract

Berdasarkan blueprint §42 (API Architecture) + §77-80 (Webhook) + §118-119 (guardrail & rate limit). Semua endpoint ada di bawah `/api/v1`.

## 0. Konvensi

**Base URL & tenant resolution**
- Multi-tenant lewat subdomain: `https://client.tatasales.com/api/v1/...`, atau
- `X-Tenant-ID: <uuid>` header untuk pemakaian API-only / server-to-server.
- Custom domain (Phase 2, §125-126): domain di-map ke `tenant_id` lewat tabel `tenants.domain`.

**Auth**
- Public endpoint: tanpa auth, tapi selalu rate-limited (§119).
- Admin endpoint: `Authorization: Bearer <token>` (Laravel Sanctum), role-scoped via middleware `EnsureRole`.

**Response envelope**
```json
{ "data": { }, "meta": { } }
```
Error:
```json
{ "error": { "code": "VALIDATION_ERROR", "message": "...", "details": { "phone": ["required"] } } }
```

**Pagination** — query `page`, `per_page` (default 20, max 100). `meta.total`, `meta.page`, `meta.per_page`.

**Idempotency** — endpoint yang boleh dipanggil ulang oleh provider eksternal (webhook, kadang lead submit dari retry frontend) menerima/menghasilkan `provider_event_id` / `Idempotency-Key` dan disimpan unik per tenant (lihat `leads.provider_event_id`, `webhook_events`). Request kedua dengan key yang sama mengembalikan resource yang sudah ada, bukan membuat duplikat (§79-80).

**Rate limit (§119)** — dikunci per kombinasi `IP + session + phone + tenant`, berlaku di: `POST /leads`, `POST /calculators/{id}/calculate`, `POST /chat/message`, endpoint search.

---

## 1. Public API

Dipanggil dari landing page / website tenant. Tidak butuh login.

### `GET /products`
Query: `category`, `featured`, `page`, `per_page`, `sort`
```json
{
  "data": [{
    "id": "uuid", "name": "Suzuki Fronx GLX", "slug": "suzuki-fronx-glx",
    "base_price": 249500000, "short_description": "...",
    "featured": true, "stock_status": "available",
    "images": ["https://.../fronx-1.webp"],
    "attributes": { "engine": "1500cc", "transmission": "AT", "seats": 5 }
  }],
  "meta": { "page": 1, "per_page": 20, "total": 48 }
}
```

### `GET /products/{slug}`
Detail produk + varian + promo aktif + `calculator_id` terkait (kalau template punya calculator).

### `GET /promotions/active`
Query opsional: `product_id`. Hanya mengembalikan promo yang lolos validasi §85 (`now() BETWEEN starts_at AND ends_at AND status = 'active'`) — promo expired tidak pernah muncul di response ini, validasi di server bukan di frontend.

### `POST /calculators/{id}/calculate`
Deterministic, tidak lewat AI (§113). Request:
```json
{ "inputs": { "price": 249500000, "dp": 50000000, "tenor": 60, "interest": 6.5 },
  "product_id": "uuid", "lead_id": null }
```
Response `200`:
```json
{ "session_id": "uuid", "outputs": { "monthly_installment": 3850000, "total_payment": 231000000 } }
```
`422` kalau input invalid (DP > price, tenor di luar range, dst — §117).

### `POST /leads`
Request:
```json
{
  "customer": { "name": "Budi Santoso", "phone": "0812xxxxxxx", "email": null },
  "product_id": "uuid", "variant_id": null,
  "calculator_session_id": "uuid",
  "source": "form",
  "utm": { "utm_source": "meta", "utm_campaign": "fronx-agustus" },
  "consent_marketing": true
}
```
Response `201` — alur internal: validate → normalize phone → find/create customer → create lead → score → assign → log activity → notify sales (§112):
```json
{ "lead_id": "uuid", "status": "NEW", "temperature": "WARM", "score": 45,
  "assigned_to": { "id": "uuid", "name": "Andi" } }
```
`429` rate limited. `409` kalau tenant punya rule dedup "tolak duplikat" untuk phone yang sama dalam window tertentu (default: update lead existing, bukan reject — §93, configurable).

### `POST /chat/message`
Request:
```json
{ "conversation_id": null, "lead_id": null, "customer_phone": "0812xxxxxxx",
  "message": "Kalau DP 50 juta berapa cicilannya?" }
```
Response:
```json
{ "conversation_id": "uuid",
  "reply": "Dengan DP Rp50.000.000 selama 60 bulan, estimasi cicilan sekitar Rp3.850.000/bulan.",
  "intent": "installment", "status": "AI_ACTIVE" }
```
Detail agent di baliknya: lihat `05-agentic-workflow.md`.

### `GET /landing-pages/{slug}`
Mengembalikan `page_sections` terurut dengan `config` per block (§22).

---

## 2. Admin API

Semua butuh `Authorization: Bearer <token>` + role check.

**Auth**
- `POST /auth/login` `{email, password}` → `{token, user}`
- `POST /auth/logout`
- `GET /auth/me`

**Products** (`owner`, `manager`, `content_manager`)
- `GET|POST /admin/products`
- `GET|PUT|DELETE /admin/products/{id}`
- `POST /admin/products/{id}/publish` · `POST /admin/products/{id}/unpublish`
- `POST /admin/products/{id}/images` (multipart, disimpan ke Supabase Storage — §38)

**Promotions & Vouchers** (`owner`, `manager`, `content_manager`)
- `GET|POST /admin/promotions`
- `PUT|DELETE /admin/promotions/{id}`
- `POST /admin/promotions/{id}/vouchers/generate` `{count, prefix}` → array kode unik (§21)

**Leads** (`owner`, `manager`, `sales` — sales hanya lihat lead miliknya, dijaga oleh `LeadPolicy`)
- `GET /admin/leads` — filter: `status, temperature, sales, product, campaign, date, location, source` (§49)
- `GET /admin/leads/{id}`
- `PUT /admin/leads/{id}` — perubahan `status` divalidasi terhadap state machine (lihat `06-lead-state-machine.md`), transisi ilegal → `422`
- `POST /admin/leads/{id}/assign` `{user_id}`
- `POST /admin/leads/{id}/notes`

**Sales**
- `GET /admin/sales/dashboard` — my leads, my tasks, today's follow-up, hot leads, pending response, won, lost (§50)
- `GET|POST /admin/sales/teams`
- `GET|POST /admin/sales/targets`

**Conversations**
- `GET /admin/conversations`
- `GET /admin/conversations/{id}/messages`
- `POST /admin/conversations/{id}/reply` (sales balas manual)
- `POST /admin/conversations/{id}/handoff` `{to: "human" | "ai"}`

**Workflows** (`owner`, `manager`)
- `GET|POST /admin/workflows`
- `PUT|DELETE /admin/workflows/{id}`
- `GET /admin/workflows/{id}/runs`
- `GET /admin/workflows/runs/{id}/logs`

**Analytics** (`owner`, `manager`)
- `GET /admin/analytics/summary` — revenue potential, leads, hot leads, conversion rate, WA clicks, calculator completion, top products/campaigns (§48)
- `GET /admin/analytics/funnel` — visitors → product views → ... → won (§95)
- `GET /admin/analytics/response-time` — rata-rata waktu lead-created → sales-contacted (§96)

**Users** (`super_admin`, `owner`)
- `GET|POST /admin/users`
- `PUT|DELETE /admin/users/{id}`

---

## 3. Webhook masuk

- `POST /webhooks/whatsapp`
- `POST /webhooks/payment`
- `POST /webhooks/crm`

Semua wajib (§79):
1. Verifikasi signature (header provider-specific, mis. `X-Hub-Signature-256`).
2. Validasi payload schema.
3. Cek idempotency via `provider_event_id` terhadap tabel `webhook_events` — kalau sudah ada, return `200` tanpa memproses ulang (§80).
4. Simpan event dulu (status `received`), baru proses lewat queue job (`ProcessWhatsAppWebhookJob`, dst) — supaya response ke provider cepat dan retry-safe.

## 4. Webhook keluar (dikonfigurasi per tenant)

Event: `lead.created`, `lead.updated`, `deal.won`, `deal.lost` (§77, §140).
```json
{
  "event": "lead.created",
  "tenant_id": "uuid",
  "data": { "lead_id": "uuid", "status": "NEW", "product_id": "uuid", "score": 45 },
  "sent_at": "2026-08-08T10:00:00Z"
}
```
Ditandatangani HMAC (header `X-TataSales-Signature`), dikirim lewat `DispatchWebhookJob` dengan retry+backoff.

## 5. Internal AI tool endpoints

Tool yang dipanggil agent (`search_products`, `get_product`, `calculate`, `create_lead`, dst) **bukan** REST endpoint publik — dipanggil langsung sebagai method PHP dari dalam proses yang sama (lihat `Agents/Tools/*` di `04-laravel-architecture.md`), supaya `tenant_id` selalu di-inject dari server context, tidak pernah dari input LLM (§118). Kontrak lengkap tiap tool ada di `05-agentic-workflow.md`.
