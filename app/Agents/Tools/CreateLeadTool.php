<?php

namespace App\Agents\Tools;

use App\Agents\Tools\Contracts\Tool;
use App\Models\CalculatorSession;
use App\Models\Conversation;
use App\Models\Product;
use App\Services\Lead\LeadService;
use Illuminate\Support\Arr;

/**
 * create_lead (§5 roster + §3 chain: Calculator → create_lead →
 * assign_sales). Customer & consent DIAMBIL DARI SERVER CONTEXT
 * (currentConversation), tidak pernah dari argumen LLM (§118). Consent
 * pemasaran wajib (§91): tanpa consent tool menolak — percakapan tetap
 * tercatat, lead tidak dipaksakan. Assignment tidak dilakukan di sini;
 * langkah berikutnya di chain adalah assign_sales.
 */
class CreateLeadTool implements Tool
{
    public function __construct(private readonly LeadService $leads) {}

    public function name(): string
    {
        return 'create_lead';
    }

    public function description(): string
    {
        return 'Buat lead baru dari percakapan ini ke CRM (pipeline penuh: score, follow-up, webhook). Hanya berhasil bila customer sudah memberi consent pemasaran dan percakapan belum punya lead. Jangan panggil bila percakapan sudah punya lead.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'product_id' => ['type' => 'string', 'description' => 'opsional: id produk yang diminati customer (dari katalog approved)'],
                'calculator_session_id' => ['type' => 'string', 'description' => 'opsional: session id dari hasil tool calculate, untuk menautkan simulasi ke lead'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments): array
    {
        $conversation = app()->bound('currentConversation') ? app('currentConversation') : null;

        if (! $conversation instanceof Conversation) {
            return ['done' => false, 'reason' => 'Tidak ada konteks percakapan.'];
        }

        if ($conversation->lead_id) {
            return ['done' => false, 'reason' => 'Percakapan ini sudah memiliki lead.'];
        }

        $customer = $conversation->customer;

        if (! $customer) {
            return ['done' => false, 'reason' => 'Customer percakapan tidak ditemukan.'];
        }

        if (! $customer->consent_marketing) {
            return ['done' => false, 'reason' => 'Customer belum memberikan consent pemasaran — lead tidak boleh dibuat.'];
        }

        $productId = Arr::get($arguments, 'product_id') ?: null;

        if ($productId) {
            $product = Product::query()
                ->where('status', 'published')
                ->whereNotNull('published_at')
                ->find($productId);

            if (! $product) {
                return ['done' => false, 'reason' => 'Produk tidak tersedia di katalog.'];
            }
        }

        $sessionId = Arr::get($arguments, 'calculator_session_id') ?: null;

        if ($sessionId) {
            $session = CalculatorSession::query()
                ->withoutGlobalScope('tenant')
                ->find($sessionId);

            if (! $session || $session->tenant_id !== $conversation->tenant_id
                || ($session->customer_id !== null && $session->customer_id !== $customer->id)) {
                return ['done' => false, 'reason' => 'Sesi kalkulator tidak valid untuk percakapan ini.'];
            }
        }

        $result = $this->leads->createFromForm([
            'customer' => [
                'name' => $customer->name,
                'phone' => $customer->phone,
            ],
            'source' => 'chat',
            'consent_marketing' => true,
            'product_id' => $productId,
            'calculator_session_id' => $sessionId,
            'skip_assignment' => true,
        ]);

        $lead = $result['lead'];

        // tautkan lead ke percakapan supaya tool berikutnya (update_lead,
        // assign_sales) bekerja pada lead yang sama — murni server-side.
        $conversation->forceFill(['lead_id' => $lead->id, 'updated_at' => now()])->save();

        return [
            'done' => true,
            'lead_id' => $lead->id,
            'created' => $result['created'],
            'status' => $lead->status,
            'score' => $lead->score,
        ];
    }
}
