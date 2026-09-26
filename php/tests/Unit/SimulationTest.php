<?php

declare(strict_types=1);

use Phox\ComposableJev\Gates;
use Phox\ComposableJev\Graph;
use Phox\ComposableJev\Node;
use Phox\ComposableJev\Oracle\Oracle;
use Phox\ComposableJev\Oracle\Simulator;
use Phox\ComposableJev\Presets;

/**
 * Answers each node from a closure keyed by its name.
 *
 * @param  array<string, Closure(array<string, mixed>): float>  $answers
 */
function exactly(array $answers): Oracle
{
    return new class($answers) implements Oracle
    {
        public function __construct(private array $answers) {}

        public function fire(Node $node, array $state): float
        {
            return ($this->answers[$node->name] ?? $this->answers[$node->preset])($state);
        }

        public function fireMany(array $items): array
        {
            return array_combine(array_column($items, 0), array_map(fn ($i) => $this->fire($i[1], $i[2]), $items));
        }

        public function batching(): bool
        {
            return false;
        }
    };
}

it('remembers between ticks, a loop going round once a tick', function (): void {
    $latch = Presets::get('sr latch')->ticker(new Simulator);
    $rows = array_map(fn (array $in) => $latch->tick($in), [['s' => 1], ['s' => 1], [], [], ['r' => 1], []]);

    expect(array_map(fn ($r) => $r->yes('q'), $rows))->toBe([false, true, true, true, false, false])
        ->and(array_map(fn ($r) => $r->ideal['q'], $rows))->toBe([0.0, 1.0, 1.0, 1.0, 0.0, 0.0])
        ->and(array_map(fn ($r) => $r->unsettled !== [], $rows))->toBe([true, true, false, false, true, false]);
});

it('asks a node reading itself once a tick, from what it said the tick before', function (): void {
    $blinker = Graph::make()->input('a')->gate('x', 'not', 'x')->ticker(new Simulator);
    $rows = array_map(fn () => $blinker->tick(), range(1, 4));

    expect(array_map(fn ($r) => $r->fires, $rows))->toBe([1, 1, 1, 1])
        ->and(array_map(fn ($r) => $r->yes('x'), $rows))->toBe([true, false, true, false]);
});

it('reports a loop through two nodes still flipping', function (): void {
    $ring = Graph::make()->input('a')->gate('x', 'not', 'y')->gate('y', 'buffer', 'x');
    $row = $ring->run(new Simulator);

    expect($row->unsettled)->toBe(['x', 'y'])->and($row->fires)->toBe(2);
});

it('asks again only what a changed input reaches', function (): void {
    $adder = Presets::adder(4)->ticker(new Simulator);
    $first = $adder->tick(Presets::sums(4, [[0, 0]])[0]);
    $second = $adder->tick(['a3' => 1] + Presets::sums(4, [[0, 0]])[0]);

    expect([$first->fires, $second->fires])->toBe([8, 2]);
});

it('runs the decay down a tick at a time, not all at once', function (): void {
    $leak = exactly([
        'cap' => fn (array $s) => 1.0 - (1 - $s['trigger']) * (1 - $s['hold']),
        'hold' => fn (array $s) => 0.8 * $s['cap'],
        'buffer' => fn (array $s) => (float) ($s['cap'] > 0.5),
    ]);
    $decay = Presets::get('decay')->ticker($leak);
    $rows = array_map(fn (array $in) => $decay->tick($in), [['trigger' => 1], [], [], [], []]);

    expect(array_map(fn ($r) => round($r->value('cap'), 3), $rows))->toBe([1.0, 0.8, 0.64, 0.512, 0.41])
        ->and(array_map(fn ($r) => $r->yes('out'), $rows))->toBe([true, true, true, true, false]);
});

it('turns the delay timer on after five seconds of ticks', function (): void {
    $delay = Presets::get('delay timer')->ticker(new Simulator);

    expect(array_map(fn () => $delay->tick()->yes('on'), range(0, 7)))->toBe([false, false, false, false, false, false, true, true]);
});

it('oscillates on a ten-second period', function (): void {
    $answers = exactly([
        'set' => fn (array $s) => (float) ($s['time'] % 10 === 0),
        'reset' => fn (array $s) => (float) ($s['time'] % 10 === 5),
        'nor' => fn (array $s) => (float) (max($s) <= 0.5),
    ]);
    $oscillator = Presets::get('555 oscillator')->ticker($answers);

    expect(implode('', array_map(fn () => $oscillator->tick()->yes('q') ? '1' : '.', range(0, 20))))->toBe('.1111......1111......');
});

it('checks a custom question against nothing', function (): void {
    $row = Graph::make()->input('a')->ask('q', 'Is a big?', 'a')->run(exactly(['q' => fn () => 0.7]));

    expect($row->ideal['q'])->toBeNull()->and(Gates::builtIn()['and']->margin)->not->toBeNull();
});
