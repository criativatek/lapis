<?php

namespace App\Services\Usage\Insights;

/**
 * Uma sugestão de VERIFICAÇÃO para a equipa interna — nunca um diagnóstico.
 * `windowFrom`/`windowTo` são `Y-m-d`.
 */
final readonly class Signal
{
    public function __construct(
        public string $key,
        public string $label,
        public string $reason,
        public string $windowFrom,
        public string $windowTo,
    ) {}

    /**
     * @return array{key: string, label: string, reason: string, window_from: string, window_to: string}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'reason' => $this->reason,
            'window_from' => $this->windowFrom,
            'window_to' => $this->windowTo,
        ];
    }
}
