<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use App\Models\Tenant;
use App\Services\Conversation\ConversationService;
use Illuminate\Console\Command;

/**
 * Pipeline chat AI dari terminal (channel webchat) — untuk verifikasi
 * manual (docs/08-agents-first-execution.md) tanpa endpoint HTTP.
 * One-shot dengan --message, atau loop interaktif tanpa --message.
 */
class TataChatCommand extends Command
{
    protected $signature = 'tata:chat {tenant : ID tenant} {--phone= : nomor customer (default 6280000000000)} {--message= : satu pesan one-shot, tanpa loop interaktif}';

    protected $description = 'Jalankan pipeline chat AI (intent → agent → jawaban) dari terminal.';

    public function handle(ConversationService $chat): int
    {
        $tenant = Tenant::query()->withoutGlobalScope('tenant')->find($this->argument('tenant'));

        if (! $tenant) {
            $this->error("Tenant {$this->argument('tenant')} tidak ditemukan.");

            return self::FAILURE;
        }

        app()->instance('currentTenant', $tenant);

        $phone = $this->option('phone') ?: '6280000000000';
        $conversationId = null;

        $oneShot = $this->option('message');

        if ($oneShot !== null) {
            $this->printTurn($chat->chat($phone, $oneShot, $conversationId, $tenant));

            return self::SUCCESS;
        }

        $this->info("Chat TATA Sales — tenant {$tenant->name} (channel webchat). Ketik pesan; 'exit' untuk keluar.");

        while (true) {
            $input = readline('Anda > ');

            if ($input === false) {
                break;
            }

            $message = trim($input);

            if (in_array($message, ['exit', 'quit', 'keluar'], true)) {
                break;
            }

            if ($message === '') {
                continue;
            }

            try {
                $result = $chat->chat($phone, $message, $conversationId, $tenant);
                $conversationId = $result['conversation_id'];
                $this->printTurn($result);
            } catch (\Throwable $e) {
                $this->error('Gagal memproses pesan: '.$e->getMessage());
            }
        }

        return self::SUCCESS;
    }

    /**
     * @param  array{conversation_id: string, reply: string, intent: string, status: string, confidence: float}  $result
     */
    private function printTurn(array $result): void
    {
        $this->line(sprintf('[%s] status=%s confidence=%.2f', $result['intent'], $result['status'], $result['confidence']));
        $this->line('AI   > '.$result['reply']);

        if ($result['status'] === Conversation::STATUS_WAITING_HUMAN) {
            $this->warn('Percakapan diteruskan ke tim manusia.');
        }
    }
}
