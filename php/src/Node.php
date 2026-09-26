<?php

declare(strict_types=1);

namespace Phox\ComposableJev;

use Closure;
use LogicException;

/**
 * One question about some signals. A noul's value is the probability Jev gives for yes; a
 * choice's is the option Jev picks and a score's the score it gives, with the probability of each
 * option or level as the node's parts, read as `name.option` or `name.2`. Its state is keyed by
 * the names of the signals it reads.
 *
 * The margin is the rule as code, positive where the right answer is yes. Jev never sees it: it
 * is what a run is checked against and what the simulator answers from. A question written by
 * hand - custom, choice or score - has none.
 *
 * A node with `compute` is worked out in code and never asked: its value is what `compute`
 * returns, and its instructions describe what that is.
 */
final readonly class Node
{
    /**
     * @param  string  $preset  a gate from Gates::templates(), a rule from Rules::templates(), `rule`, `weighted`, `custom`, `choice` or `score`
     * @param  list<string>  $reads
     * @param  (Closure(array<string, mixed>): float)|null  $margin
     * @param  list<float>  $weights  one per signal read, for a weighted sum or average
     * @param  (Closure(array<string, mixed>): float)|null  $compute
     * @param  int  $n  how many must be over 0.5, for `atLeast`
     * @param  string  $type  `noul`, `choice` or `score`
     * @param  array<string, string>|list<string>  $options  a choice's options, option => what it means, or a score's levels, lowest first
     */
    public function __construct(
        public string $name,
        public string $preset,
        public array $reads,
        public string $instructions,
        public string $yes = '',
        public string $no = '',
        public ?Closure $margin = null,
        public array $weights = [],
        public float $bias = 0.0,
        public ?Closure $compute = null,
        public int $n = 0,
        public string $type = 'noul',
        public array $options = [],
    ) {}

    /**
     * The same node reading other signals, as when a signal is known by another name.
     *
     * @param  list<string>  $reads
     */
    public function reading(array $reads): self
    {
        return new self($this->name, $this->preset, $reads, $this->instructions, $this->yes, $this->no, $this->margin, $this->weights, $this->bias, $this->compute, $this->n, $this->type, $this->options);
    }

    public function named(string $name): self
    {
        return new self($name, $this->preset, $this->reads, $this->instructions, $this->yes, $this->no, $this->margin, $this->weights, $this->bias, $this->compute, $this->n, $this->type, $this->options);
    }

    /**
     * The question as System One is sent it.
     *
     * @return array{type: string, instructions: string, criteria: array<string, string>|list<string>}
     */
    public function question(): array
    {
        return [
            'type' => $this->type,
            'instructions' => $this->instructions,
            'criteria' => $this->type === 'noul' ? ['true' => $this->yes, 'false' => $this->no] : $this->options,
        ];
    }

    /**
     * The names of a choice's or a score's parts, `name.option` or `name.0`, in order.
     *
     * @return list<string>
     */
    public function parts(): array
    {
        return match ($this->type) {
            'choice' => array_map(fn ($o) => "{$this->name}.{$o}", array_keys($this->options)),
            'score' => array_map(fn (int $i) => "{$this->name}.{$i}", array_keys($this->options)),
            default => [],
        };
    }

    /**
     * The right answer, or null where the input sits on the boundary or there is no rule.
     *
     * @param  array<string, mixed>  $state
     */
    public function expected(array $state): ?bool
    {
        if ($this->margin === null) {
            return null;
        }
        $margin = ($this->margin)($state);

        return $margin == 0 ? null : $margin > 0;
    }

    /**
     * The node as the page edits it.
     *
     * @return array<string, mixed>
     */
    public function spec(): array
    {
        return ['name' => $this->name, 'preset' => $this->preset, 'reads' => $this->reads] + match ($this->preset) {
            'custom' => ['instructions' => $this->instructions, 'yes' => $this->yes, 'no' => $this->no],
            'choice' => ['instructions' => $this->instructions, 'options' => $this->options],
            'score' => ['instructions' => $this->instructions, 'levels' => $this->options],
            'weighted' => ['weights' => $this->weights, 'bias' => $this->bias],
            'average' => ['weights' => $this->weights],
            'atLeast' => ['n' => $this->n],
            'rule' => throw new LogicException("{$this->name} is worked out by a function, which a spec cannot hold"),
            default => [],
        };
    }

    /**
     * @return array{name: string, inputs: list<string>, instructions: string, yes: string, no: string, rule: bool, code: bool}
     */
    public function describe(): array
    {
        return [
            'name' => $this->name, 'inputs' => $this->reads, 'instructions' => $this->instructions,
            'yes' => $this->yes, 'no' => $this->no, 'rule' => $this->margin !== null, 'code' => $this->compute !== null,
        ];
    }
}
