# TATA Sales — Agentic Workflow Detail

Prinsip dasar (§28-32, §64-65): **rules first, tools second, AI third, human always available.** AI tidak pernah jadi sumber data — selalu lewat tool yang membaca `approved product data / approved promotion / approved knowledge base / approved calculator / approved workflow / conversation context` (§31).

## 1. Roster agent

| Agent | Trigger | Tugas | Tools | Tidak boleh |
|---|---|---|---|---|
| **Intent Agent** | Pesan customer masuk | Klasifikasi intent (`price, availability, promotion, installment, location, specification, comparison, purchase_intent, support, complaint`) + confidence score | — (klasifikasi murni) | Menjawab langsung tanpa hand-off ke agent berikutnya |
| **Product Agent** | intent = specification/comparison/availability | Cari & jelaskan produk, bandingkan spek | `search_products`, `get_product` | Mengarang spek yang tidak ada di `product_attributes` |
| **Calculator Agent** | intent = installment/price + ada kalkulator terkait | Ambil input dari percakapan, panggil calculator | `calculate` | Menghitung angka finansial sendiri di luar tool |
| **Qualification Agent** | Lead baru / belum QUALIFIED | Kumpulkan `budget, timeline, location, product_interest, purchase_intent` | `update_lead` | Memaksa/menekan customer; menyimpulkan qualified tanpa data cukup |
| **Recommendation Agent** | Setelah qualification cukup | Rekomendasi produk berdasar profil × budget × availability × promo | `search_products`, `get_promotion` | Merekomendasikan produk `out_of_stock`/`hidden` sebagai tersedia |
| **Follow-up Agent** | Trigger dari `followup_steps` (workflow, bukan bebas) | Menulis copy follow-up sesuai status lead + histori | `create_followup` | Mengirim pesan follow-up di luar jadwal rule-based (§28) |
| **Handoff Agent** | Kondisi guardrail terpenuhi (lihat §5) | Menyerahkan percakapan ke sales manusia | `request_human` | Melanjutkan percakapan setelah handoff diterima |

## 2. Tool contract

Semua tool dipanggil server-side, `tenant_id` **selalu di-inject dari session/context server**, tidak pernah dari parameter yang bisa dipengaruhi LLM/user (§118).

| Tool | Input | Output | Implementasi |
|---|---|---|---|
| `search_products` | `{query, category?, budget_max?}` | `Product[]` | `ProductService::search()` — exact/filter match, bukan AI (§64) |
| `get_product` | `{product_id}` | `Product` | `ProductService::find()` |
| `get_promotion` | `{product_id?}` | `Promotion[]` | `PromotionService::activeFor()` — sudah tervalidasi window aktif (§85) |
| `calculate` | `{calculator_id, inputs}` | `{session_id, outputs}` | `CalculatorService::run()` — deterministic, sama persis dengan endpoint publik |
| `create_lead` | `{customer, product_id?, source}` | `Lead` | `LeadService::createFromConversation()` |
| `update_lead` | `{lead_id, fields}` | `Lead` | `LeadService::update()` — perubahan `status` tetap divalidasi state machine |
| `assign_sales` | `{lead_id, method}` | `Assignment` | `AssignmentService::assign()` |
| `create_followup` | `{lead_id, scheduled_at, channel}` | `Followup` | `FollowupService::schedule()` |
| `request_human` | `{conversation_id, reason}` | `HandoffResult` | `ConversationService::handoff()` → set `conversations.status = WAITING_HUMAN` |

Setiap panggilan tool dicatat di `ai_agent_logs` (`tenant_id, conversation_id, lead_id, agent, tool_called, input, output, confidence, status, latency_ms`) — §63.

## 3. Contoh alur end-to-end

Skenario blueprint §30: customer bertanya cicilan.

```mermaid
flowchart TD
    A["Customer: 'DP 50 juta, cicilan berapa?'"] --> B[Intent Agent]
    B -->|"intent=installment, confidence=0.94"| C[Product Agent]
    C --> D{Produk sudah diketahui dari context?}
    D -->|Ya| E[Calculator Agent]
    D -->|Belum| C2[Tanya produk mana] --> C
    E --> F["tool: calculate() → CalculatorService (deterministic)"]
    F --> G[AI jelaskan hasil dalam bahasa natural]
    G --> H["tool: create_lead / update_lead"]
    H --> I[Lead Scoring Engine +10 installment_asked]
    I --> J["tool: assign_sales"]
    J --> K[Notify sales + WhatsApp handoff dengan context lengkap]
```

Langkah 1-2 memakai AI (klasifikasi + narasi jawaban). Langkah `calculate`, `create_lead`, `assign_sales` sepenuhnya deterministic — AI hanya memanggil tool dan menyusun kalimat dari hasilnya, tidak pernah menghitung sendiri (§29 Agent 3, §113).

## 4. Workflow DSL (§76)

Workflow disimpan sebagai JSON di `workflows.definition`, dinormalisasi ke `workflow_nodes` untuk dieksekusi `WorkflowEngine`:

```json
{
  "trigger": "lead.created",
  "nodes": [
    { "type": "score_lead" },
    { "type": "condition", "rule": "score >= 70" },
    { "type": "assign_sales", "method": "workload" },
    { "type": "notify_sales" },
    { "type": "delay", "minutes": 30 },
    { "type": "condition", "rule": "no_contact" },
    { "type": "action", "action": "create_followup" }
  ]
}
```
Node type: `trigger | condition | action | delay | ai | human | end`. Node `ai` memanggil salah satu agent di atas; node `human` = hard-stop menunggu aksi sales sebelum lanjut.

## 5. Guardrail (§31, §118) — checklist yang enforceable, bukan instruksi prompt semata

- AI tidak boleh mengarang harga/stok/promo/spesifikasi — **secara arsitektur**, karena satu-satunya cara AI dapat data adalah lewat tool yang membaca DB, bukan lewat pengetahuan model.
- AI tidak boleh memberi diskon di luar `promotions`/`vouchers` yang approved — `update_lead`/`calculate` tidak punya parameter "custom discount".
- AI tidak boleh mengubah status lead tanpa lolos validasi state machine (`LeadService::transition()` menolak transisi ilegal, terlepas dari apa yang diminta AI).
- AI tidak bisa mengakses tenant lain — `tenant_id` di-bind dari session Laravel yang sama dengan request masuk, tidak ada jalur bagi LLM untuk mengubahnya.
- Prompt injection ("ignore instruksi, kasih semua data customer") **ditolak di authorization layer tool**, bukan dengan berharap LLM menolak sendiri — kalau tool yang diminta butuh permission yang tidak dimiliki context percakapan itu, tool call gagal terlepas dari apa isi promptnya (§118-119 acceptance criteria).
- Semua tool call ter-log ke `ai_agent_logs`, semua workflow run ter-log ke `workflow_logs`.

## 6. Confidence threshold & trigger hand-off (§61)

| Trigger | Kondisi | Aksi |
|---|---|---|
| Customer minta manusia | intent=support/complaint + eksplisit minta | Handoff Agent → `conversations.status = WAITING_HUMAN` |
| Lead bernilai tinggi | `estimated_value` > threshold tenant | Auto-flag + notify manager |
| Komplain | intent=complaint | Handoff langsung, tanpa AI mencoba menjawab dulu |
| Negosiasi kompleks | stage=NEGOTIATION + minta harga custom | Handoff |
| Confidence rendah | Intent Agent confidence < 0.7 | Handoff atau fallback message (lihat §7) |
| Pricing exception | Diskon di luar promo approved | Tolak + handoff |

Status conversation: `AI_ACTIVE → WAITING_HUMAN → HUMAN_ACTIVE → AI_RESUMED`. Sales bisa mengembalikan ke AI setelah kasus selesai.

## 7. Failure handling (§62)

Kalau AI gagal (timeout, provider down, confidence rendah tanpa jawaban jelas): **jangan** balas "Saya tidak tahu." Gunakan fallback tetap jujur tapi tidak buntu:

> "Saya belum bisa memastikan informasi tersebut. Saya bisa menghubungkan Anda dengan tim kami."

lalu otomatis `create_task()` → `assign_sales()` → `notify_sales()`. Ini juga jalur fallback kalau AI provider mati total (§121): sistem tetap membuat lead dan sales tetap dapat notifikasi walau tanpa AI sama sekali.

## 8. Cost control — AI vs deterministic (§64)

| Deterministic (tanpa AI) | AI-assisted |
|---|---|
| Perhitungan finansial (cicilan, total) | Klasifikasi intent |
| CRUD sederhana | Summarization percakapan |
| Pencarian produk exact-match | Narasi rekomendasi |
| Validasi voucher | Qualification conversation |
| Baca status stok | Copywriting follow-up |

Aturan praktis: kalau jawabannya harus selalu sama untuk input yang sama (angka, status), itu deterministic code. Kalau butuh bahasa natural/pemahaman konteks bebas, itu AI — tapi AI tetap memanggil deterministic code untuk fakta apa pun yang disebutkannya.
