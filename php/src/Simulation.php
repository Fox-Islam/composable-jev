<?php

declare(strict_types=1);

namespace Phox\ComposableJev;

use Closure;

/**
 * Runs a graph for one step. No node is asked more than once a step, so a step is one trip
 * round a loop, the way a gate delay is in a circuit: a latch takes a step or two to settle, and
 * a node that reads itself moves once a step, which is what lets it run down over time.
 *
 * A node with no loop is asked only when something it reads has changed, so a step that flips
 * one input re-asks only what that input reaches; a level's such nodes go to `fireMany` in one
 * call. A loop is asked every step, a node at a time in design order, each reading the latest
 * values: asking a loop all at once would set a latch oscillating.
 */
final readonly class Simulation
{
    /**
     * An answer moving less than this is treated as unchanged, so Jev answering 0.98 then 0.99
     * does not re-ask everything that reads it.
     */
    public const float CHANGE = 0.02;

    /**
     * @param  Closure(string, array<string, mixed>): mixed  $fire
     * @param  (Closure(string, string, mixed): void)|null  $onFire  kind (`firing` or `fired`), node, value
     * @param  (Closure(list<array{string, array<string, mixed>}>): array<string, mixed>)|null  $fireMany
     */
    public function __construct(
        private Plan $plan,
        private Closure $fire,
        private ?Closure $onFire = null,
        private ?Closure $fireMany = null,
    ) {}

    public static function changed(mixed $old, mixed $new): bool
    {
        return self::numeric($old) && self::numeric($new) ? abs($old - $new) > self::CHANGE : $old !== $new;
    }

    /**
     * Whether an answer crossed from one side of 0.5 to the other, or a choice picked another
     * option.
     */
    public static function flipped(mixed $old, mixed $new): bool
    {
        if (is_string($old) && is_string($new)) {
            return $old !== $new;
        }

        return self::numeric($old) && self::numeric($new) && ($old > 0.5) !== ($new > 0.5);
    }

    public static function same(mixed $a, mixed $b): bool
    {
        return self::numeric($a) && self::numeric($b) ? (float) $a === (float) $b : $a === $b;
    }

    /**
     * Every node from its starting value, then settled against `$values`.
     *
     * @param  array<string, mixed>  $values
     * @param  array<string, float>  $start
     */
    public function fresh(array $values, array $start): Settled
    {
        $signals = [];
        foreach (array_keys($this->plan->reads) as $node) {
            $signals[$node] = $start[$node] ?? 0.0;
        }

        return $this->settle(array_merge($signals, $values), array_fill_keys(array_keys($this->plan->reads), true));
    }

    /**
     * The last step's signals with `$values` changed, settled again. A node that reads itself,
     * or sits in a loop, is asked every step.
     *
     * @param  array<string, mixed>  $previous
     * @param  array<string, mixed>  $values
     */
    public function step(array $previous, array $values): Settled
    {
        $signals = $previous;
        $dirty = array_fill_keys($this->plan->looping, true);
        foreach ($values as $key => $value) {
            if (! array_key_exists($key, $signals) || ! self::same($signals[$key], $value)) {
                $dirty += array_fill_keys($this->plan->readers[$key] ?? [], true);
            }
            $signals[$key] = $value;
        }

        return $this->settle($signals, $dirty);
    }

    private static function numeric(mixed $v): bool
    {
        return is_int($v) || is_float($v);
    }

    /**
     * @param  array<string, mixed>  $signals
     * @param  array<string, true>  $dirty
     */
    private function settle(array $signals, array $dirty): Settled
    {
        $unsettled = [];
        $fires = 0;
        foreach ($this->plan->levels as $level) {
            $single = array_values(array_filter(
                array_map(fn (array $c) => $this->plan->looped($c) ? null : $c[0], $level),
                fn (?string $n) => $n !== null && isset($dirty[$n]),
            ));
            foreach ($this->askAll($single, $signals) as $node => $value) {
                $this->record($node, $value, $signals, $dirty);
            }
            $fires += count($single);
            foreach ($level as $component) {
                if ($this->plan->looped($component)) {
                    $fires += count($component);
                    $unsettled = $this->roundLoop($component, $signals, $dirty) ? [...$unsettled, $component] : $unsettled;
                }
            }
        }

        return new Settled($signals, $unsettled, $fires);
    }

    /**
     * Asks a loop once round, in order. True where a node with others in its loop crossed 0.5,
     * as a latch's nodes do for a step after it is set: the loop has not settled. A loop that only
     * drifts, as a leak does, and one node reading only itself are not reported.
     *
     * @param  list<string>  $component
     * @param  array<string, mixed>  $signals
     * @param  array<string, true>  $dirty
     */
    private function roundLoop(array $component, array &$signals, array &$dirty): bool
    {
        $moved = false;
        foreach ($component as $node) {
            $old = $signals[$node] ?? null;
            $this->record($node, $this->ask($node, $signals), $signals, $dirty);
            $moved = $moved || self::flipped($old, $signals[$node]);
        }

        return $moved && count($component) > 1;
    }

    /**
     * @param  list<string>  $nodes
     * @param  array<string, mixed>  $signals
     * @return array<string, mixed>
     */
    private function askAll(array $nodes, array $signals): array
    {
        if ($this->fireMany === null || count($nodes) < 2) {
            $answers = [];
            foreach ($nodes as $node) {
                $answers[$node] = $this->ask($node, $signals);
            }

            return $answers;
        }
        foreach ($nodes as $node) {
            $this->notify('firing', $node, null);
        }
        $answers = ($this->fireMany)(array_map(fn (string $n) => [$n, $this->state($n, $signals)], $nodes));
        foreach ($nodes as $node) {
            $this->notify('fired', $node, $answers[$node] instanceof Answer ? $answers[$node]->value : $answers[$node]);
        }

        return $answers;
    }

    /**
     * @param  array<string, mixed>  $signals
     */
    private function ask(string $node, array $signals): mixed
    {
        $this->notify('firing', $node, null);
        $value = ($this->fire)($node, $this->state($node, $signals));
        $this->notify('fired', $node, $value instanceof Answer ? $value->value : $value);

        return $value;
    }

    /**
     * Stores an answer, a choice's or a score's parts with it, and marks what reads each to be
     * asked, if it moved.
     *
     * @param  array<string, mixed>  $signals
     * @param  array<string, true>  $dirty
     */
    private function record(string $node, mixed $value, array &$signals, array &$dirty): void
    {
        unset($dirty[$node]);
        $given = $value instanceof Answer ? [$node => $value->value] + array_combine(
            array_map(fn ($part) => "{$node}.{$part}", array_keys($value->parts)),
            array_values($value->parts),
        ) : [$node => $value];
        foreach ($given as $signal => $new) {
            $old = $signals[$signal] ?? null;
            $signals[$signal] = $new;
            if (self::changed($old, $new)) {
                foreach ($this->plan->readers[$signal] ?? [] as $reader) {
                    $dirty = $reader === $node ? $dirty : $dirty + [$reader => true];
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $signals
     * @return array<string, mixed>
     */
    private function state(string $node, array $signals): array
    {
        $state = [];
        foreach ($this->plan->reads[$node] as $signal) {
            $state[$signal] = $signals[$signal] ?? null;
        }

        return $state;
    }

    private function notify(string $kind, string $node, mixed $value): void
    {
        if ($this->onFire !== null) {
            ($this->onFire)($kind, $node, $value);
        }
    }
}
