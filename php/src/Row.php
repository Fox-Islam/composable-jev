<?php

declare(strict_types=1);

namespace Phox\ComposableJev;

/**
 * What one step gave: the values it ran with, each node's answer, and each node's right answer
 * where it has a rule.
 */
final readonly class Row
{
    /**
     * @param  array<string, mixed>  $inputs
     * @param  array<string, mixed>  $signals  node => Jev's answer, and a choice's or a score's parts
     * @param  array<string, float|null>  $ideal  node => 1.0, 0.0, or null with no rule
     * @param  list<string>  $unsettled  nodes in a loop that had not settled
     */
    public function __construct(
        public array $inputs,
        public array $signals,
        public array $ideal,
        public string $output,
        public array $unsettled,
        public int $fires,
    ) {}

    public function value(string $node): float
    {
        return (float) $this->signals[$node];
    }

    public function yes(string $node): bool
    {
        return $this->value($node) > 0.5;
    }

    /**
     * The option a choice picked.
     */
    public function choice(string $node): string
    {
        return (string) $this->signals[$node];
    }

    /**
     * Each option's or level's probability, from a choice's or a score's parts.
     *
     * @return array<string, float>
     */
    public function probabilities(string $node): array
    {
        $parts = [];
        foreach ($this->signals as $signal => $value) {
            if (str_starts_with($signal, "{$node}.")) {
                $parts[mb_substr($signal, mb_strlen($node) + 1)] = (float) $value;
            }
        }

        return $parts;
    }

    /**
     * The number held in bits named `{$prefix}0`, `{$prefix}1`…, least significant first.
     */
    public function number(string $prefix): int
    {
        $total = 0;
        for ($i = 0; array_key_exists("{$prefix}{$i}", $this->signals); $i++) {
            $total += $this->yes("{$prefix}{$i}") ? 1 << $i : 0;
        }

        return $total;
    }

    /**
     * Inputs and nodes, as the next step starts from them.
     *
     * @return array<string, mixed>
     */
    public function state(): array
    {
        return $this->inputs + $this->signals;
    }

    /**
     * @return array<string, mixed>
     */
    public function idealState(): array
    {
        return $this->inputs + $this->ideal;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $want = $this->ideal[$this->output] ?? null;

        return [
            'inputs' => $this->inputs, 'signals' => $this->signals, 'ideal' => $this->ideal,
            'p' => $this->signals[$this->output] ?? null, 'want' => $want === null ? null : $want > 0.5,
            'unsettled' => $this->unsettled, 'fires' => $this->fires,
        ];
    }
}
