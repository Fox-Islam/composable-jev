<?php

declare(strict_types=1);

namespace Tests;

use Phox\ComposableJev\Gates;
use Phox\ComposableJev\Graph;
use Phox\ComposableJev\Numbers;
use Phox\ComposableJev\Oracle\Oracle;
use Phox\ComposableJev\Oracle\Simulator;
use Phox\ComposableJev\Plan;
use Phox\ComposableJev\Presets;
use Phox\ComposableJev\Row;
use Phox\ComposableJev\Rules;
use Phox\ComposableJev\Ticker;
use Tests\Fakes\FakeTypeSafe;

/**
 * `spec/fixtures.json`: what this implementation gives for a set of runs, which the JavaScript
 * and Python tests hold theirs to. A run is simulated, or answered by a fake TypeSafe and kept
 * with every request body it sent, in canonical form.
 */
final class Fixtures
{
    /**
     * @return array<string, mixed>
     */
    public static function make(): array
    {
        return [
            'numbers' => [
                'round' => array_map(fn (array $c) => [...$c, Numbers::round($c[0], $c[1])], [[32.25, 1], [0.75, 1], [0.0025, 3], [0.9995, 3], [0.12345, 3], [2.5, 0]]),
                'seconds' => array_map(fn (float $s) => [$s, Numbers::seconds($s)], [0.0, 1.0, 32.0, 32.04, 32.25, 0.75, 7.5]),
                'text' => array_map(fn (float $n) => [$n, Numbers::text($n)], [1.0, 0.6, -2.5, 5.0, 0.125, 100.0]),
            ],
            'questions' => self::questions(),
            'rules' => self::rules(),
            'plans' => array_map(fn (array $reads) => ['reads' => $reads, 'levels' => ($p = new Plan($reads))->levels, 'looping' => $p->looping], [
                ['b' => ['a'], 'c' => ['b'], 'd' => ['c', 'b']],
                ['d' => ['c', 'b'], 'c' => ['b'], 'b' => ['a']],
                ['x' => ['y'], 'y' => ['x'], 'z' => ['x', 'z']],
                ['p' => ['q'], 'q' => ['r'], 'r' => ['p'], 's' => ['r', 'input'], 't' => ['s', 't'], 'u' => ['t', 'p']],
                ['m' => ['n', 'n'], 'n' => ['o'], 'o' => ['m'], 'k' => ['k']],
            ]),
            'runs' => [
                self::truth('xor, simulated', 'xor', null),
                self::truth('xor', 'xor', true),
                self::truth('xor, one request a node', 'xor', false),
                self::truth('half-adder, simulated', 'half-adder', null),
                self::truth('ticket triage', 'ticket triage', true),
                self::ticks('4-bit adder, simulated', '4-bit adder', null, 1.0, self::adding(4, [[7, 5], [15, 5], [0, 0]])),
                self::ticks('4-bit adder', '4-bit adder', true, 1.0, self::adding(4, [[7, 5], [15, 5], [0, 0]])),
                self::ticks('8-bit adder, simulated', '8-bit adder', null, 1.0, self::adding(8, [[100, 55], [255, 1]])),
                self::ticks('sr latch, simulated', 'sr latch', null, 1.0, self::inputs([['s' => 1], ['s' => 1], [], ['r' => 1], ['r' => 1], []])),
                self::ticks('sr latch', 'sr latch', true, 1.0, self::inputs([['s' => 1], ['s' => 1], [], ['r' => 1], ['r' => 1], []])),
                self::ticks('delay timer, simulated', 'delay timer', null, 1.0, self::inputs(array_fill(0, 8, []))),
                self::ticks('delay timer, a quarter second a tick', 'delay timer', true, 0.25, self::inputs(array_fill(0, 4, []))),
                self::ticks('555 oscillator', '555 oscillator', true, 5.0, self::inputs(array_fill(0, 5, []))),
                self::ticks('decay', 'decay', true, 1.0, self::inputs([['trigger' => 1], [], [], [], []])),
                self::ticks('ticket triage, a tick a context', 'ticket triage', true, 1.0, [['inputs' => [], 'context' => 1], ['inputs' => [], 'context' => 2]]),
                self::truth('routing, with a choice and a score', self::routing(), true),
                self::truth('routing, one request a node', self::routing(), false),
                self::truth('a context that is a JSON object', [
                    'inputs' => [],
                    'contexts' => [
                        ['ticket' => 'Refund me.', 'customer' => ['plan' => 'enterprise', 'seats' => 400], 'tags' => ['billing', 'vip']],
                        ['ticket' => 'How do I log in?', 'customer' => ['plan' => 'free', 'seats' => 1], 'tags' => []],
                    ],
                    'layers' => [[['name' => 'big', 'preset' => 'custom', 'reads' => ['context'], 'instructions' => 'Is this a large customer?', 'yes' => '', 'no' => '']]],
                ], true),
                self::ticks('routing, a tick a context', self::routing(), true, 1.0, [['inputs' => [], 'context' => 0], ['inputs' => [], 'context' => 1], ['inputs' => [], 'context' => 1]]),
            ],
        ];
    }

    /**
     * A graph with a choice and a score in it, which the presets the page offers leave out.
     *
     * @return array<string, mixed>
     */
    public static function routing(): array
    {
        return json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/spec/routing.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    public static function path(): string
    {
        return dirname(__DIR__, 2) . '/spec/fixtures.json';
    }

    public static function encode(array $fixtures): string
    {
        return json_encode($fixtures, JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    }

    /**
     * Every gate over two and three signals, a weighted sum, a choice and a score, as asked.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function questions(): array
    {
        $questions = [];
        foreach (Gates::templates() as $gate => $template) {
            foreach ($template['arity'] === 1 ? [['x']] : [['a', 'b'], ['a', 'b', 'c']] as $reads) {
                $questions[$gate . ' ' . implode(',', $reads)] = Gates::gate($gate, $reads)->question();
            }
        }
        $questions['weighted'] = Gates::weighted([0.6, 1.0, -2.5], 1.0)->question();
        $questions['choice'] = Gates::choice('x', 'Which?', ['context'], ['one' => 'The first', 'two' => 'The second'])->question();
        $questions['score'] = Gates::score('x', 'How much?', ['context'], ['None', 'Some', 'All'])->question();

        return $questions;
    }

    /**
     * Each rule over some states, with what it works out and how it describes itself.
     *
     * @return list<array<string, mixed>>
     */
    private static function rules(): array
    {
        $states = [['a' => 0.9, 'b' => 0.3, 'c' => 0.6], ['a' => 0.0, 'b' => 1.0, 'c' => 0.5], ['a' => 0.51, 'b' => 0.49, 'c' => 0.123]];
        $rules = [
            Rules::all('x', ['a', 'b', 'c']), Rules::all('x', ['a']), Rules::any('x', ['a', 'b']), Rules::none('x', ['c']),
            Rules::none('x', ['a', 'b', 'c']), Rules::atLeast('x', 2, ['a', 'b', 'c']), Rules::atLeast('x', 3, ['a', 'b', 'c']),
            Rules::average('x', ['a' => 3, 'b' => 1]), Rules::sum('x', ['a', 'b', 'c']), Rules::sum('x', ['b', 'c']), Rules::average('x', ['a' => 0.5, 'b' => 0.25, 'c' => 1]),
        ];

        return array_map(fn ($rule) => [
            'spec' => $rule->spec(),
            'describe' => $rule->instructions,
            'values' => array_map(fn (array $state) => ($rule->compute)($state), $states),
            'states' => $states,
        ], $rules);
    }

    /**
     * @param  list<array{int, int}>  $pairs
     * @return list<array{inputs: array<string, int>, context: int}>
     */
    private static function adding(int $bits, array $pairs): array
    {
        return self::inputs(Presets::sums($bits, $pairs));
    }

    /**
     * @param  list<array<string, int>>  $inputs
     * @return list<array{inputs: array<string, int>, context: int}>
     */
    private static function inputs(array $inputs): array
    {
        return array_map(fn (array $i) => ['inputs' => $i, 'context' => 0], $inputs);
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @param  string|array<string, mixed>  $preset  a preset's name, or a graph's spec
     */
    private static function truth(string $name, string|array $preset, ?bool $batch): array
    {
        $graph = is_string($preset) ? Presets::get($preset) : Graph::fromSpec($preset);
        [$oracle, $fake] = self::oracle($graph, $batch);
        $asked = [];
        $rows = $graph->truthTable($oracle, onFire: function (int $row, string $kind, string $node) use (&$asked): void {
            if ($kind === 'fired') {
                $asked[$row][] = $node;
            }
        });

        return self::run($name, $preset, $batch, 'truth', null, null, $rows, $asked, $fake);
    }

    /**
     * @param  string|array<string, mixed>  $preset  a preset's name, or a graph's spec
     * @param  list<array{inputs: array<string, int>, context: int}>  $ticks
     * @return array<string, mixed>
     */
    private static function ticks(string $name, string|array $preset, ?bool $batch, float $every, array $ticks): array
    {
        $graph = is_string($preset) ? Presets::get($preset) : Graph::fromSpec($preset);
        [$oracle, $fake] = self::oracle($graph, $batch);
        $ticker = new Ticker($graph, $oracle, $every);
        $asked = [];
        foreach ($ticks as $i => $tick) {
            $ticker->tick($tick['inputs'], $tick['context'], function (string $kind, string $node) use (&$asked, $i): void {
                if ($kind === 'fired') {
                    $asked[$i][] = $node;
                }
            });
        }

        return self::run($name, $preset, $batch, 'ticks', $every, $ticks, $ticker->rows, $asked, $fake);
    }

    /**
     * @return array{Oracle, FakeTypeSafe|null}
     */
    private static function oracle(Graph $graph, ?bool $batch): array
    {
        if ($batch === null) {
            return [new Simulator, null];
        }
        $fake = FakeTypeSafe::forFixture($graph);

        return [$fake->jev($batch), $fake];
    }

    /**
     * @param  list<array<string, mixed>>|null  $ticks
     * @param  list<Row>  $rows
     * @param  array<int, list<string>>  $asked
     * @return array<string, mixed>
     */
    private static function run(string $name, string|array $preset, ?bool $batch, string $mode, ?float $every, ?array $ticks, array $rows, array $asked, ?FakeTypeSafe $fake): array
    {
        return array_filter([
            'name' => $name,
            ...(is_string($preset) ? ['preset' => $preset] : ['graph' => $preset]),
            'oracle' => $batch === null ? 'simulator' : 'jev',
            'batch' => $batch,
            'mode' => $mode,
            'every' => $every,
            'ticks' => $ticks,
            'rows' => array_map(fn (Row $r, int $i) => [
                'inputs' => $r->inputs, 'signals' => $r->signals, 'ideal' => $r->ideal,
                'unsettled' => $r->unsettled, 'fires' => $r->fires, 'asked' => $asked[$i] ?? [],
            ], $rows, array_keys($rows)),
            'requests' => $fake === null ? null : array_map(fn (array $r) => Numbers::canonical($r['body']), $fake->requests),
        ], fn ($v) => $v !== null);
    }
}
