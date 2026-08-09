<?php

namespace App\Agents\Values;

class ToolResult
{
    public const STATUS_OK = 'success';

    public const STATUS_FAILED = 'failed';

    public const STATUS_DENIED = 'denied';

    public function __construct(
        public readonly string $status,
        public readonly string $tool,
        public readonly array $input,
        public readonly array $output,
        public readonly int $latencyMs,
    ) {}

    public static function denied(string $tool, array $input): self
    {
        return new self(self::STATUS_DENIED, $tool, $input, [
            'error' => "Tool '{$tool}' tidak terdaftar untuk agent ini.",
        ], 0);
    }

    public function isOk(): bool
    {
        return $this->status === self::STATUS_OK;
    }

    /**
     * Representasi untuk dikirim balik ke LLM pada iterasi berikutnya.
     */
    public function toMessageContent(): string
    {
        return '[tool_result tool='.$this->tool.' status='.$this->status.' output='.json_encode($this->output).']';
    }
}
