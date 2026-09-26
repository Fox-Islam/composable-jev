<?php

declare(strict_types=1);

namespace Phox\ComposableJev;

use InvalidArgumentException;

/**
 * The questions a node asks: logic gates, a weighted sum, and questions written by hand - a noul,
 * a choice or a score. Every gate thresholds at 0.5 and states which side 0.5 itself falls on,
 * so the boundary is defined in the question. The wording is a template over the names of the
 * signals the gate reads: {x} is the one signal, {list} is them all ("a and b", "a, b and c"),
 * {both} is "both" or "all". The page renders the same templates, so what it shows is what Jev
 * is asked.
 */
final class Gates
{
    /**
     * `data/gates.json`. Arity 1 reads exactly one signal; arity 2 reads two or more. xor and
     * majority ask Jev to count, which its documentation names as a weakness; they are what make
     * an adder two nodes a bit, and were right on every adder answer measured.
     *
     * @return array<string, array{arity: int, instructions: string, yes: string, no: string}>
     */
    public static function templates(): array
    {
        return Data::read('gates.json');
    }

    /**
     * @param  list<string>  $reads
     */
    public static function gate(string $gate, array $reads, ?string $name = null): Node
    {
        $template = self::templates()[$gate] ?? throw new InvalidArgumentException("{$gate} is not a gate");
        if ($reads === [] || ($template['arity'] === 1) !== (count($reads) === 1)) {
            throw new InvalidArgumentException($gate . ' reads ' . ($template['arity'] === 1 ? 'one signal' : 'two or more signals'));
        }

        return new Node(
            $name ?? $gate,
            $gate,
            $reads,
            self::render($template['instructions'], $reads),
            self::render($template['yes'], $reads),
            self::render($template['no'], $reads),
            fn (array $state): float => self::margin($gate, array_map(fn (string $r) => (float) $state[$r], $reads)),
        );
    }

    /**
     * Each gate over `a`, or `a` and `b`: the Gates tab's single node.
     *
     * @return array<string, Node>
     */
    public static function builtIn(): array
    {
        $gates = [];
        foreach (self::templates() as $name => $template) {
            $gates[$name] = self::gate($name, $template['arity'] === 1 ? ['a'] : ['a', 'b']);
        }

        return $gates;
    }

    /**
     * A classic perceptron: yes where the weighted sum of the inputs exceeds the bias. It asks Jev
     * to do the sum, which is the arithmetic its documentation names as a weakness.
     *
     * @param  list<float>  $weights
     * @param  list<string>|null  $inputs  the signals read, `a`, `b`, `c`… when not given
     */
    public static function weighted(array $weights, float $bias, string $name = 'weighted', ?array $inputs = null): Node
    {
        $inputs ??= array_map(fn (int $i) => chr(ord('a') + $i), array_keys($weights));
        if ($weights === [] || count($inputs) !== count($weights)) {
            throw new InvalidArgumentException("{$name}: one weight per signal read");
        }
        $total = implode(' + ', array_map(fn (float $w, string $x) => Numbers::text($w) . ' × ' . $x, $weights, $inputs));
        $b = Numbers::text($bias);

        return new Node(
            $name,
            'weighted',
            $inputs,
            "Is {$total} greater than {$b}?",
            "{$total} is greater than {$b}.",
            "{$total} is {$b} or less.",
            fn (array $state): float => array_sum(array_map(fn (float $w, string $x) => $w * (float) $state[$x], $weights, $inputs)) - $bias,
            $weights,
            $bias,
        );
    }

    /**
     * A question written by hand, over the signals it names. It has no rule.
     *
     * @param  list<string>  $reads
     */
    public static function custom(string $name, string $question, array $reads, string $yes = '', string $no = ''): Node
    {
        if (trim($question) === '') {
            throw new InvalidArgumentException("{$name}: write a question");
        }

        return new Node($name, 'custom', $reads, trim($question), trim($yes), trim($no));
    }

    /**
     * A question Jev answers by picking one of the options.
     *
     * @param  list<string>  $reads
     * @param  array<string, string>  $options  option => what it means
     */
    public static function choice(string $name, string $question, array $reads, array $options): Node
    {
        if (trim($question) === '') {
            throw new InvalidArgumentException("{$name}: write a question");
        }
        if (count($options) < 2 || array_filter(array_keys($options), fn ($o) => ! is_string($o) || trim($o) === '' || str_contains($o, '.')) !== []) {
            throw new InvalidArgumentException("{$name}: two or more options, each named, with no . in the name");
        }

        return new Node($name, 'choice', $reads, trim($question), type: 'choice', options: array_map('strval', $options));
    }

    /**
     * A question Jev answers with a score, from 0 for the first level up.
     *
     * @param  list<string>  $reads
     * @param  list<string>  $levels  what each score means, lowest first
     */
    public static function score(string $name, string $question, array $reads, array $levels): Node
    {
        if (trim($question) === '') {
            throw new InvalidArgumentException("{$name}: write a question");
        }
        if (count($levels) < 2 || ! array_is_list($levels)) {
            throw new InvalidArgumentException("{$name}: two or more levels, lowest first");
        }

        return new Node($name, 'score', $reads, trim($question), type: 'score', options: array_map('strval', $levels));
    }

    /**
     * @param  list<string>  $reads
     */
    public static function render(string $template, array $reads): string
    {
        $list = count($reads) === 1 ? $reads[0] : implode(', ', array_slice($reads, 0, -1)) . ' and ' . $reads[count($reads) - 1];

        return strtr($template, ['{x}' => $reads[0], '{list}' => $list, '{both}' => count($reads) === 2 ? 'both' : 'all']);
    }

    /**
     * @param  list<float>  $v
     */
    private static function margin(string $gate, array $v): float
    {
        $high = count(array_filter($v, fn (float $x) => $x > 0.5));

        return match ($gate) {
            'buffer' => $v[0] - 0.5,
            'not' => 0.5 - $v[0],
            'and' => min($v) - 0.5,
            'or' => max($v) - 0.5,
            'nand' => 0.5 - min($v),
            'nor' => 0.5 - max($v),
            'xor' => $high % 2 === 1 ? 0.5 : -0.5,
            default => $high - count($v) / 2 - 0.25,
        };
    }
}
