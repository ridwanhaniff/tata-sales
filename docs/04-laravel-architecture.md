# TATA Sales — Laravel Architecture

Prinsip: **Modular Monolith** (blueprint §144-145) — satu Laravel app, dipecah per modul secara logis lewat namespace, bukan microservices. Layering wajib: `Controller → Request (validasi) → Service/Action → Response` (§74) — logic tidak boleh nyasar ke Controller.

## Struktur folder

```text
tata-sales/
├── app/
│   ├── Console/
│   │   └── Commands/                      # artisan command (seed template, cleanup, dsb)
│   │
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Api/V1/
│   │   │   │   ├── Public/
│   │   │   │   │   ├── ProductController.php
│   │   │   │   │   ├── PromotionController.php
│   │   │   │   │   ├── CalculatorController.php
│   │   │   │   │   ├── LeadController.php
│   │   │   │   │   ├── ChatController.php
│   │   │   │   │   └── LandingPageController.php
│   │   │   │   └── Admin/
│   │   │   │       ├── ProductController.php
│   │   │   │       ├── PromotionController.php
│   │   │   │       ├── VoucherController.php
│   │   │   │       ├── LeadController.php
│   │   │   │       ├── SalesController.php
│   │   │   │       ├── ConversationController.php
│   │   │   │       ├── WorkflowController.php
│   │   │   │       ├── AnalyticsController.php
│   │   │   │       └── UserController.php
│   │   │   ├── Webhooks/
│   │   │   │   ├── WhatsAppWebhookController.php
│   │   │   │   ├── PaymentWebhookController.php
│   │   │   │   └── CrmWebhookController.php
│   │   │   └── Web/
│   │   │       └── Admin/                 # Blade dashboard controllers
│   │   │
│   │   ├── Middleware/
│   │   │   ├── ResolveTenant.php          # subdomain/domain/header → tenant_id
│   │   │   ├── SetTenantContext.php       # SET LOCAL app.tenant_id (RLS defense-in-depth)
│   │   │   ├── EnsureRole.php             # role-based access (§9, §43)
│   │   │   └── VerifyWebhookSignature.php
│   │   │
│   │   ├── Requests/
│   │   │   ├── Product/StoreProductRequest.php
│   │   │   ├── Lead/StoreLeadRequest.php
│   │   │   ├── Promotion/StorePromotionRequest.php
│   │   │   └── Calculator/CalculateRequest.php
│   │   │
│   │   └── Resources/                     # API Resource transformer
│   │       ├── ProductResource.php
│   │       ├── LeadResource.php
│   │       └── ...
│   │
│   ├── Models/
│   │   ├── Concerns/
│   │   │   └── BelongsToTenant.php        # global scope: WHERE tenant_id = current
│   │   ├── Tenant.php
│   │   ├── User.php
│   │   ├── Product.php  ├── ProductVariant.php  ├── ProductAttribute.php
│   │   ├── Promotion.php  ├── Voucher.php
│   │   ├── Customer.php
│   │   ├── Lead.php  ├── LeadEvent.php  ├── PipelineStage.php
│   │   ├── Conversation.php  ├── ConversationMessage.php
│   │   ├── Followup.php
│   │   ├── Quotation.php
│   │   ├── Workflow.php  ├── WorkflowRun.php
│   │   └── ...
│   │
│   ├── Services/                          # 1 service = 1 domain module (§74)
│   │   ├── Product/ProductService.php
│   │   ├── Promotion/PromotionService.php
│   │   ├── Promotion/VoucherService.php
│   │   ├── Calculator/CalculatorService.php   # deterministic engine, dipakai app & CalculatorAgent
│   │   ├── Lead/LeadService.php               # orchestrates create→score→assign→notify
│   │   ├── Lead/LeadScoringService.php
│   │   ├── Lead/AssignmentService.php         # round_robin/product/location/workload/manual
│   │   ├── Sales/SalesPipelineService.php     # validasi transisi state machine
│   │   ├── Conversation/ConversationService.php
│   │   ├── Followup/FollowupService.php
│   │   ├── Workflow/WorkflowService.php
│   │   ├── Analytics/AnalyticsService.php
│   │   └── Attribution/AttributionService.php
│   │
│   ├── Actions/                           # single-purpose, invokable (dipanggil Service maupun Job)
│   │   ├── Lead/CreateLeadAction.php
│   │   ├── Lead/ScoreLeadAction.php
│   │   ├── Lead/AssignLeadAction.php
│   │   ├── Calculator/RunCalculatorAction.php
│   │   └── Voucher/RedeemVoucherAction.php
│   │
│   ├── Agents/                            # AI module (§29, §64-65) — lihat 05-agentic-workflow.md
│   │   ├── Contracts/
│   │   │   ├── AgentInterface.php
│   │   │   └── LLMProvider.php
│   │   ├── IntentAgent.php  ├── ProductAgent.php  ├── CalculatorAgent.php
│   │   ├── QualificationAgent.php  ├── RecommendationAgent.php
│   │   ├── FollowupAgent.php  ├── HandoffAgent.php
│   │   ├── Tools/
│   │   │   ├── SearchProductsTool.php  ├── GetProductTool.php  ├── GetPromotionTool.php
│   │   │   ├── CalculateTool.php       ├── CreateLeadTool.php  ├── UpdateLeadTool.php
│   │   │   ├── AssignSalesTool.php     ├── CreateFollowupTool.php ├── RequestHumanTool.php
│   │   └── Providers/
│   │       ├── OpenAIProvider.php  ├── AnthropicProvider.php  └── GeminiProvider.php
│   │
│   ├── Workflows/                         # workflow node executor (§33, §76)
│   │   ├── WorkflowEngine.php
│   │   └── Nodes/
│   │       ├── TriggerNode.php ├── ConditionNode.php ├── ActionNode.php
│   │       ├── DelayNode.php   ├── AiNode.php         ├── HumanNode.php ├── EndNode.php
│   │
│   ├── Events/
│   │   ├── LeadCreated.php  ├── LeadUpdated.php  ├── ProductViewed.php
│   │   ├── CalculatorCompleted.php  ├── MessageReceived.php
│   │   ├── QuotationCreated.php  ├── DealWon.php  └── DealLost.php
│   │
│   ├── Listeners/
│   │   ├── ScoreLeadOnEvent.php
│   │   ├── TriggerWorkflowOnEvent.php     # jembatan event internal → workflow engine
│   │   ├── LogLeadEvent.php
│   │   └── DispatchOutgoingWebhook.php
│   │
│   ├── Jobs/                              # queue: Laravel database queue di MVP (§40)
│   │   ├── SendFollowupJob.php
│   │   ├── ProcessWhatsAppWebhookJob.php
│   │   ├── RunWorkflowStepJob.php
│   │   ├── AggregateAnalyticsJob.php
│   │   └── DispatchWebhookJob.php
│   │
│   ├── Policies/
│   │   ├── ProductPolicy.php  ├── LeadPolicy.php  └── ...
│   │
│   └── Providers/
│       ├── AppServiceProvider.php
│       ├── EventServiceProvider.php
│       └── LLMServiceProvider.php         # bind LLMProvider interface → provider aktif (§65)
│
├── database/
│   ├── migrations/                        # dipecah dari 01-database-schema.sql per tabel
│   ├── seeders/
│   │   ├── TenantSeeder.php
│   │   ├── PipelineStageSeeder.php        # seed NEW/CONTACTED/.../NURTURE per tenant baru
│   │   └── TemplateSeeder.php             # automotive-v1, property-v1, wedding-v1
│   └── factories/
│
├── resources/
│   ├── views/
│   │   ├── admin/                         # Blade dashboard
│   │   ├── landing/                       # Blade block components (Hero, ProductGrid, dst)
│   │   └── components/
│   ├── css/
│   └── js/                                # Alpine.js
│
├── routes/
│   ├── web.php     # landing page + admin dashboard (Blade)
│   ├── api.php      # /api/v1/... (lihat 03-api-contract.md)
│   └── channels.php
│
├── tests/
│   ├── Unit/          # calculator, voucher, promo, scoring, assignment, validation
│   ├── Feature/       # create lead, create product, WA flow, workflow
│   └── Integration/   # Supabase, WhatsApp provider, AI provider, webhook
│
├── config/
│   ├── tata.php        # default scoring weights, pipeline default, template registry
│   └── llm.php          # provider config (§65)
└── README.md
```

## Tenant isolation (§45) — dua lapis

1. **Application layer (wajib, sumber kebenaran):**
   `ResolveTenant` middleware menentukan `tenant_id` dari subdomain/domain/header, disimpan di request context. Semua Eloquent model tenant-scoped pakai trait `BelongsToTenant` yang mendaftarkan **global scope** `WHERE tenant_id = ?` — jadi query lupa filter tenant pun otomatis aman, dan tidak mungkin lupa karena bukan opsional per-query.

2. **Database layer (defense-in-depth):**
   `SetTenantContext` middleware menjalankan `SET LOCAL app.tenant_id = '<uuid>'` di awal transaction. RLS policy di `01-database-schema.sql` memvalidasi ulang di level Postgres — kalau lapis 1 lolos karena bug, lapis 2 tetap menahan.

**Test wajib** (§45): tenant A tidak bisa baca products/leads/customers/campaigns milik tenant B — dijalankan sebagai `tests/Feature/TenantIsolationTest.php`, bagian dari Definition of Done tiap sprint yang menyentuh tabel baru.

## Kenapa `Actions/` terpisah dari `Services/`

`Service` = orkestrasi satu domain (mis. `LeadService::createFromForm()` memanggil beberapa `Action` berurutan: validate → normalize → find-or-create customer → `CreateLeadAction` → `ScoreLeadAction` → `AssignLeadAction` → notify). `Action` = satu langkah spesifik yang bisa dipakai ulang dari tempat lain — mis. `ScoreLeadAction` dipanggil baik dari `LeadService` (saat lead baru) maupun dari `Listeners/ScoreLeadOnEvent` (saat ada `CalculatorCompleted` event di lead yang sudah ada). Ini juga titik masuk yang sama dipakai `Agents/Tools/*Tool.php` — tool AI tidak pernah query database langsung, selalu lewat `Service`/`Action` yang sama dengan jalur non-AI (§32: "Agent tidak langsung mengakses database").

## Provider abstraction (§65)

```php
interface LLMProvider
{
    public function generate(string $prompt, array $context = [], array $options = []): LLMResponse;
    public function classify(string $input, array $labels, array $context = []): ClassificationResult;
    public function extract(string $input, array $schema, array $context = []): array;
}
```
`LLMServiceProvider` mem-bind interface ini ke implementasi aktif berdasarkan `config/llm.php` (`LLM_PROVIDER` env). Ganti provider (OpenAI ↔ Anthropic ↔ Gemini) = ganti config, tanpa sentuh `Agents/*Agent.php`.
