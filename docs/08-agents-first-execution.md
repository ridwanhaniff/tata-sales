# TATA Sales — Bootstrap & First Execution: Agents Module

Lanjutan dari `05-agentic-workflow.md`. Ini bukan langkah "deploy fitur AI", tapi langkah **verifikasi bertahap** supaya kalau ada yang gagal, kelihatan gagal di lapisan mana — bukan gagal diam-diam di ujung chat loop.

## Prasyarat sebelum baris kode Agent pertama dijalankan

1. **Konfigurasi provider** — `.env`: `LLM_PROVIDER=anthropic` (atau openai/gemini), `LLM_API_KEY=...`. `config/llm.php` dibuat, `LLMServiceProvider` di-register dan mem-bind `LLMProvider` interface ke adapter aktif (§65 di `04-laravel-architecture.md`).
2. **Data minimum ter-seed** — 1 tenant, `pipeline_stages` untuk tenant itu (`PipelineStageSeeder`), minimal 1-2 produk dengan `product_attributes` terisi, 1 calculator kalau mau tes Calculator Agent.
3. **Tenant context di luar HTTP request** — `ResolveTenant`/`SetTenantContext` middleware hanya jalan di jalur HTTP. Untuk tinker/artisan command/test, tenant context harus di-set manual:
   ```php
   app()->instance('currentTenant', $tenant);
   DB::statement('SET LOCAL app.tenant_id = ?', [$tenant->id]);
   ```
   Lupa langkah ini = error RLS `permission denied for table ...` yang membingungkan karena kelihatannya seperti bug tool, padahal cuma context belum ke-set.

## Urutan eksekusi (isolasi bertahap)

### 1. Test `LLMProvider` mentah — sebelum ada Agent sama sekali
```
php artisan tinker
>>> $provider = app(\App\Agents\Contracts\LLMProvider::class);
>>> $provider->generate('Balas dengan kata "ok" saja.');
```
Tujuannya memastikan API key & koneksi provider benar, terpisah dari logic agent — supaya kalau nanti ada error, kita sudah tahu bukan di sini sumbernya.

### 2. Test Intent Agent sendirian — agent paling sederhana, tanpa tool
Buat command sekali pakai:
```php
// app/Console/Commands/TestIntentAgent.php
class TestIntentAgent extends Command
{
    protected $signature = 'agents:test-intent {message}';

    public function handle(\App\Agents\IntentAgent $agent)
    {
        $result = $agent->handle(new \App\Agents\AgentContext(message: $this->argument('message')));
        $this->info(json_encode($result, JSON_PRETTY_PRINT));
    }
}
```
```
php artisan agents:test-intent "Kalau DP 50 juta cicilannya berapa?"
# expected: {"intent":"installment","confidence":0.9x}
```

### 3. Test tool call pertama — Product Agent + `search_products`
Butuh minimal 1 produk sudah ter-seed (langkah prasyarat #2). Verifikasi dua hal, bukan cuma satu:
- Output produk yang dikembalikan benar.
- Ada baris baru di `ai_agent_logs` (`agent=product`, `tool_called=search_products`, `status=success`). Ini pembuktian bahwa tool call benar-benar lewat jalur yang di-log — kalau agent "kelihatan jalan" tapi `ai_agent_logs` kosong, berarti ada jalur pintas yang bypass logging dan itu harus diperbaiki sebelum lanjut, karena observability ini yang dipakai untuk guardrail §63.

### 4. Test loop percakapan penuh — baru sekarang lewat endpoint, bukan tinker
```
POST /api/v1/chat/message
{ "customer_phone": "0812xxxxxxx", "message": "Kalau DP 50 juta cicilannya berapa?" }
```
Ini eksekusi pertama `ConversationService::assembleContext()` yang sebenarnya. Cek: row baru di `conversations` + `conversation_messages`, dan kolom `context` (JSONB) terisi snapshot produk/lead/campaign — bukan `{}` kosong.

### 5. Test guardrail sebelum lanjut ke agent lain
Tanyakan produk yang **tidak ada** di database. AI harus menjawab fallback ("belum bisa memastikan...") atau bilang tidak ditemukan — **bukan** mengarang spesifikasi. Ini test yang paling penting dijalankan lebih dulu daripada menambah agent baru, karena kalau guardrail ini bocor, menambah lebih banyak agent di atasnya cuma memperbesar permukaan masalah.

### 6. Baru sambungkan chain penuh: Calculator → create_lead → assign_sales
Ini alur yang didiagram di `05-agentic-workflow.md` §3. Sukses = satu pesan chat menghasilkan satu row baru di `leads`, dengan `lead_events` mencatat urutan `calculator_completed → lead_created → sales_assigned` secara berurutan dan waktunya masuk akal (bukan simultan — kalau simultan biasanya berarti ada race condition di listener event).

## Troubleshooting umum first-run

| Gejala | Kemungkinan penyebab |
|---|---|
| `permission denied for table X` | `app.tenant_id` belum di-set (khusus konteks CLI/tinker, lihat Prasyarat #3) |
| `generate()` polos berhasil, tapi tool call selalu gagal | Tool belum terdaftar di `AgentInterface::tools()` agent terkait |
| Agent "kelihatan" jalan tapi `ai_agent_logs` kosong | Logging biasanya ditaruh di `Service` layer yang dipanggil tool — cek tidak ada jalur pintas yang skip logging |
| Response lambat/timeout di endpoint chat tapi cepat di tinker | Biasanya N+1 query di `assembleContext()`, atau provider call yang seharusnya di-queue (untuk follow-up/async) malah sinkron di request cycle |
| Lead tidak muncul walau agent bilang "lead sudah dibuat" | Cek apakah `create_lead` tool benar-benar memanggil `LeadService`, atau AI cuma menjawab natural language tanpa tool call terjadi (confidence AI tinggi ≠ tool benar-benar dipanggil — selalu verifikasi lewat `ai_agent_logs`, jangan percaya teks jawabannya saja) |

Prinsip di balik semua langkah di atas: **percaya `ai_agent_logs`, bukan teks jawaban AI**, sebagai bukti sesuatu benar-benar terjadi. Ini konsisten dengan guardrail §31/§63 — kalau observability-nya sendiri belum bisa dipercaya, tidak ada cara memverifikasi guardrail lain berfungsi.
