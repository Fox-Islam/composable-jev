<?php

declare(strict_types=1);

namespace Phox\ComposableJev;

use Closure;
use InvalidArgumentException;

/**
 * Nodes worked out in code: no call, no tokens, no wait, and exact. Asking Jev an and between two
 * answers takes a request a level; for ticket triage that is 3 requests against 1, 746 ms
 * against 268 ms and 1,223 tokens against 502, with the same decision on every ticket.
 *
 * Each returns a probability, so a later question sees how sure the answers were, and each
 * is over 0.5 exactly where its logic is true: the lowest of some answers is over 0.5 only where
 * all of them are.
 */
final class Rules
{
    /**
     * `data/rules.json`: each rule's name on the page and what it works out.
     *
     * @return array<string, array{label: string, glyph: string, describe: string}>
     */
    public static function templates(): array
    {
        return Data::read('rules.json');
    }

    public static function isRule(string $preset): bool
    {
        return isset(self::templates()[$preset]) || $preset === 'rule';
    }

    /**
     * @param  list<string>  $reads
     */
    public static function all(string $name, array $reads): Node
    {
        return self::node($name, 'all', $reads, fn (array $v) => min($v));
    }

    /**
     * @param  list<string>  $reads
     */
    public static function any(string $name, array $reads): Node
    {
        return self::node($name, 'any', $reads, fn (array $v) => max($v));
    }

    /**
     * Not, over one signal; nor, over more.
     *
     * @param  list<string>  $reads
     */
    public static function none(string $name, array $reads): Node
    {
        return self::node($name, 'none', $reads, fn (array $v) => 1.0 - max($v));
    }

    /**
     * The chance one of them is true, where no two can be, as a choice's options or a score's
     * levels cannot: `sum('serious', 'severity.3', 'severity.4')`. Held to 1 at most.
     *
     * @param  list<string>  $reads
     */
    public static function sum(string $name, array $reads): Node
    {
        return self::node($name, 'sum', $reads, fn (array $v) => min(1.0, array_sum($v)));
    }

    /**
     * The value `$n` down from the highest: over 0.5 where at least `$n` of them are.
     *
     * @param  list<string>  $reads
     */
    public static function atLeast(string $name, int $n, array $reads): Node
    {
        if ($n < 1 || $n > count($reads)) {
            throw new InvalidArgumentException("{$name}: at least 1, and no more than the " . count($reads) . ' signals it reads');
        }

        return self::node($name, 'atLeast', $reads, function (array $v) use ($n): float {
            rsort($v);

            return $v[$n - 1];
        }, n: $n);
    }

    /**
     * @param  array<string, float|int>  $weights  signal => weight, none below 0 and not all 0
     */
    public static function average(string $name, array $weights): Node
    {
        $weights = array_map('floatval', $weights);
        if ($weights === [] || min($weights) < 0 || array_sum($weights) <= 0) {
            throw new InvalidArgumentException("{$name}: a weight for each signal, none below 0 and not all 0");
        }
        $w = array_values($weights);

        return self::node($name, 'average', array_keys($weights), function (array $v) use ($w): float {
            return array_sum(array_map(fn (float $x, float $weight) => $x * $weight, $v, $w)) / array_sum($w);
        }, $w);
    }

    /**
     * A function of your own over the signals read, returning 0 to 1. It has no spec, so the page
     * and `data/presets.json` cannot hold it.
     *
     * @param  Closure(array<string, mixed>): float  $compute  given the signals it reads by name
     * @param  list<string>  $reads
     */
    public static function rule(string $name, Closure $compute, array $reads): Node
    {
        return self::built($name, 'rule', $reads, "Worked out by a function of {$name}'s own", fn (array $state) => (float) $compute($state), [], 0);
    }

    /**
     * @param  list<string>  $reads
     * @param  list<float>  $weights
     */
    public static function describe(string $preset, array $reads, array $weights = [], int $n = 0): string
    {
        return strtr(Gates::render(self::templates()[$preset]['describe'], $reads), [
            '{n}' => (string) $n,
            '{weights}' => implode(', ', array_map(Numbers::text(...), $weights)),
        ]);
    }

    /**
     * @param  list<string>  $reads
     * @param  Closure(list<float>): float  $of  the values read, in order
     * @param  list<float>  $weights
     */
    private static function node(string $name, string $preset, array $reads, Closure $of, array $weights = [], int $n = 0): Node
    {
        self::reading($name, $reads);

        return self::built(
            $name,
            $preset,
            $reads,
            self::describe($preset, $reads, $weights, $n),
            fn (array $state): float => $of(array_map(fn (string $r) => (float) $state[$r], $reads)),
            $weights,
            $n,
        );
    }

    /**
     * @param  list<string>  $reads
     * @param  Closure(array<string, mixed>): float  $compute
     * @param  list<float>  $weights
     */
    private static function built(string $name, string $preset, array $reads, string $describe, Closure $compute, array $weights, int $n): Node
    {
        self::reading($name, $reads);

        return new Node($name, $preset, array_values($reads), $describe, '', '',
            fn (array $state): float => $compute($state) - 0.5, $weights, 0.0, $compute, $n);
    }

    /**
     * @param  list<string>  $reads
     */
    private static function reading(string $name, array $reads): void
    {
        if ($reads === []) {
            throw new InvalidArgumentException("{$name}: read at least one signal");
        }
    }
}
