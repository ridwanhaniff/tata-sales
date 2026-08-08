<?php

namespace App\Services\Calculator;

use RuntimeException;

/**
 * Evaluator ekspresi matematika yang deterministic dan aman.
 *
 * Tidak memakai eval(). Mendukung: angka, variabel, + - * / ( ),
 * serta fungsi annuity/floor/ceil/round/max/min.
 */
class FormulaEvaluator
{
    private array $tokens = [];

    private int $pos = 0;

    /** @param array<string, int|float> $variables */
    public function __construct(private readonly array $variables = []) {}

    public function evaluate(string $expression): float
    {
        $expression = trim($expression);

        if ($expression === '') {
            throw new RuntimeException('Formula kosong.');
        }

        $this->tokens = $this->tokenize($expression);
        $this->pos = 0;

        $value = $this->parseExpression();

        if ($this->pos !== count($this->tokens)) {
            throw new RuntimeException('Formula tidak valid: token tidak terpakai.');
        }

        if (! is_finite($value)) {
            throw new RuntimeException('Hasil formula tidak terdefinisi.');
        }

        return $value;
    }

    private function tokenize(string $expression): array
    {
        $tokens = [];
        $length = strlen($expression);
        $i = 0;

        while ($i < $length) {
            $char = $expression[$i];

            if (ctype_space($char)) {
                $i++;

                continue;
            }

            if (in_array($char, ['+', '-', '*', '/', '(', ')', ','], true)) {
                $tokens[] = ['type' => 'symbol', 'value' => $char];
                $i++;

                continue;
            }

            if (ctype_digit($char) || $char === '.') {
                $start = $i;
                $hasDot = false;
                while ($i < $length && (ctype_digit($expression[$i]) || $expression[$i] === '.')) {
                    if ($expression[$i] === '.') {
                        $hasDot = true;
                    }
                    $i++;
                }
                $raw = substr($expression, $start, $i - $start);

                if (substr_count($raw, '.') > 1) {
                    throw new RuntimeException("Angka tidak valid: {$raw}.");
                }

                $tokens[] = ['type' => 'number', 'value' => $hasDot ? (float) $raw : (int) $raw];

                continue;
            }

            if (ctype_alpha($char) || $char === '_') {
                $start = $i;
                while ($i < $length && (ctype_alnum($expression[$i]) || $expression[$i] === '_')) {
                    $i++;
                }
                $tokens[] = ['type' => 'identifier', 'value' => substr($expression, $start, $i - $start)];

                continue;
            }

            throw new RuntimeException("Karakter tidak dikenal: '{$char}'.");
        }

        return $tokens;
    }

    private function parseExpression(): float
    {
        $value = $this->parseTerm();

        while ($this->peekSymbol('+') || $this->peekSymbol('-')) {
            $op = $this->consume()['value'];
            $right = $this->parseTerm();
            $value = $op === '+' ? $value + $right : $value - $right;
        }

        return $value;
    }

    private function parseTerm(): float
    {
        $value = $this->parseFactor();

        while ($this->peekSymbol('*') || $this->peekSymbol('/')) {
            $op = $this->consume()['value'];
            $right = $this->parseFactor();

            if ($op === '/' && $right == 0) {
                throw new RuntimeException('Pembagian dengan nol.');
            }

            $value = $op === '*' ? $value * $right : $value / $right;
        }

        return $value;
    }

    private function parseFactor(): float
    {
        $token = $this->current();

        if ($token === null) {
            throw new RuntimeException('Formula tidak valid: ekspresi berakhir mendadak.');
        }

        if ($token['type'] === 'number') {
            $this->consume();

            return (float) $token['value'];
        }

        if ($token['type'] === 'identifier') {
            return $this->parseIdentifier();
        }

        if ($this->peekSymbol('-')) {
            $this->consume();

            return -$this->parseFactor();
        }

        if ($this->peekSymbol('+')) {
            $this->consume();

            return $this->parseFactor();
        }

        if ($this->peekSymbol('(')) {
            $this->consume();
            $value = $this->parseExpression();

            if (! $this->peekSymbol(')')) {
                throw new RuntimeException('Kurung tutup tidak ditemukan.');
            }
            $this->consume();

            return $value;
        }

        throw new RuntimeException('Formula tidak valid.');
    }

    private function parseIdentifier(): float
    {
        $name = $this->consume()['value'];

        if (! $this->peekSymbol('(')) {
            if (! array_key_exists($name, $this->variables)) {
                throw new RuntimeException("Variabel tidak dikenal: {$name}.");
            }

            return (float) $this->variables[$name];
        }

        $this->consume(); // (
        $args = [];

        if (! $this->peekSymbol(')')) {
            do {
                $args[] = $this->parseExpression();
            } while ($this->peekSymbol(',') && ($this->consume() || true));
        }

        if (! $this->peekSymbol(')')) {
            throw new RuntimeException('Kurung tutup fungsi tidak ditemukan.');
        }
        $this->consume();

        return $this->callFunction($name, $args);
    }

    private function callFunction(string $name, array $args): float
    {
        return match ($name) {
            'annuity' => $this->annuity($args),
            'floor' => floor($this->singleArg($name, $args)),
            'ceil' => ceil($this->singleArg($name, $args)),
            'round' => round($this->singleArg($name, $args)),
            'max' => max($args),
            'min' => min($args),
            default => throw new RuntimeException("Fungsi tidak dikenal: {$name}."),
        };
    }

    private function singleArg(string $name, array $args): float
    {
        if (count($args) !== 1) {
            throw new RuntimeException("Fungsi {$name} butuh tepat 1 argumen.");
        }

        return (float) $args[0];
    }

    /** Rumus angsuran anuitas: PMT = P * r * (1+r)^n / ((1+r)^n - 1), r = rate/100/12. */
    private function annuity(array $args): float
    {
        if (count($args) !== 3) {
            throw new RuntimeException('Fungsi annuity butuh 3 argumen: annuity(pokok, bunga_persen, tenor).');
        }

        [$principal, $ratePercent, $months] = array_map('floatval', $args);

        if ($principal < 0 || $ratePercent < 0 || $months <= 0) {
            throw new RuntimeException('Argumen annuity tidak valid.');
        }

        $rate = $ratePercent / 100 / 12;

        if ($rate == 0) {
            return $principal / $months;
        }

        $factor = (1 + $rate) ** $months;

        return $principal * $rate * $factor / ($factor - 1);
    }

    private function current(): ?array
    {
        return $this->tokens[$this->pos] ?? null;
    }

    private function consume(): array
    {
        $token = $this->current();

        if ($token === null) {
            throw new RuntimeException('Formula tidak valid.');
        }

        $this->pos++;

        return $token;
    }

    private function peekSymbol(string $symbol): bool
    {
        $token = $this->current();

        return $token !== null && $token['type'] === 'symbol' && $token['value'] === $symbol;
    }
}
