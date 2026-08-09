<?php

namespace App\Agents;

use App\Agents\Tools\CalculateTool;
use App\Agents\Tools\RequestHumanTool;
use App\Agents\Values\LLMResponse;
use App\Services\Calculator\CalculatorService;

/**
 * Calculator Agent — intent installment/price bila tenant punya kalkulator.
 * Tidak menghitung angka sendiri: satu-satunya tool adalah calculate yang
 * memanggil CalculatorService (mesin deterministic, sama persis dengan
 * endpoint publik kalkulator §3). Angka jawaban hanya dari output tool.
 */
class CalculatorAgent extends Agent
{
    public function name(): string
    {
        return 'calculator';
    }

    public function tools(): array
    {
        return [
            new CalculateTool(app(CalculatorService::class)),
            new RequestHumanTool,
        ];
    }

    protected function systemPrompt(AgentContext $context): string
    {
        $calculators = collect($context->meta['calculators'] ?? [])
            ->map(fn ($c) => [
                'calculator_id' => $c['id'],
                'name' => $c['name'],
                'type' => $c['type'],
            ])
            ->values()
            ->all();

        $calculatorList = json_encode($calculators, JSON_UNESCAPED_UNICODE);

        return <<<PROMPT
Kamu adalah asisten simulasi penjualan (cicilan, simulasi harga, dst).

ATURAN WAJIB:
- Satu-satunya cara menghitung: panggil tool calculate dengan calculator_id dari daftar kalkulator tersedia.
- JANGAN PERNAH menghitung atau mengarang angka sendiri — angka hanya boleh dari field "outputs".
- Input kalkulator yang belum disebutkan customer → tanyakan pelan-pelan dulu.
- Kalau tool mengembalikan validation_errors → sebutkan yang kurang, jangan menebak.
- Kalau kalkulator tidak tersedia untuk percakapan ini → bilang jujur dan tawarkan menghubungkan ke tim.
- Kalau customer minta manusia/komplain/minta diskon di luar promo → panggil request_human.
- Jawab dalam bahasa Indonesia, ringkas, tanpa markdown.

Kalkulator yang tersedia: {$calculatorList}.
PROMPT;
    }

    protected function finalize(AgentContext $context, array $messages, array $toolResults, LLMResponse $final): array
    {
        $calculate = collect($toolResults)->firstWhere('tool', 'calculate');
        $result = $calculate && ($calculate->output['found'] ?? false)
            ? [
                'session_id' => $calculate->output['session_id'],
                'outputs' => $calculate->output['outputs'],
                'calculator_name' => $calculate->output['calculator_name'],
            ]
            : null;

        return [
            'reply' => $final->content,
            'calculator_result' => $result,
        ];
    }
}
