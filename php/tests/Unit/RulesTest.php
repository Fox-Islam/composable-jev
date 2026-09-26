<?php

declare(strict_types=1);

use Phox\ComposableJev\Graph;
use Phox\ComposableJev\Oracle\Simulator;
use Phox\ComposableJev\Presets;
use Phox\ComposableJev\Rules;
use Tests\Fakes\FakeTypeSafe;

it('works out each rule as a probability that is over 0.5 where its logic is true', function (): void {
    $state = ['a' => 0.9, 'b' => 0.3, 'c' => 0.6];
    $value = fn ($node) => round(($node->compute)($state), 6);

    expect($value(Rules::all('x', ['a', 'b', 'c'])))->toBe(0.3)
        ->and($value(Rules::any('x', ['a', 'b', 'c'])))->toBe(0.9)
        ->and($value(Rules::none('x', ['b'])))->toBe(0.7)
        ->and($value(Rules::atLeast('x', 2, ['a', 'b', 'c'])))->toBe(0.6)
        ->and($value(Rules::average('x', ['a' => 3, 'b' => 1])))->toBe(0.75);
});

it('never sends a rule to Jev', function (): void {
    $graph = Presets::get('ticket triage');
    $fake = FakeTypeSafe::forFixture($graph);
    $row = $graph->run($fake->jev(), context: 1);

    expect(count($fake->requests))->toBe(1)
        ->and(array_keys($fake->requests[0]['body']['questions']))->toEqualCanonicalizing(['angry', 'enterprise', 'refund'])
        ->and($row->value('escalate'))->toBe(min($row->value('angry'), max($row->value('refund'), $row->value('enterprise'))));
});

it('runs a function of your own, which a spec cannot hold', function (): void {
    $graph = Graph::make()->input('a', 'b')->rule('both', fn (array $s) => $s['a'] * $s['b'], 'a', 'b')->none('neither', 'a', 'b');

    expect($graph->run(new Simulator, ['a' => 1, 'b' => 1])->value('both'))->toBe(1.0)
        ->and($graph->run(new Simulator, ['a' => 0, 'b' => 0])->value('neither'))->toBe(1.0)
        ->and(fn () => $graph->spec())->toThrow(LogicException::class, 'which a spec cannot hold');
});

it('refuses a rule it cannot work out', function (Closure $build, string $message): void {
    expect($build)->toThrow(InvalidArgumentException::class, $message);
})->with([
    [fn () => Rules::atLeast('x', 3, ['a', 'b']), 'no more than the 2 signals'],
    [fn () => Rules::average('x', ['a' => -1, 'b' => 2]), 'none below 0'],
    [fn () => Rules::all('x', []), 'read at least one signal'],
    [fn () => Graph::fromSpec(['inputs' => [], 'texts' => ['hi'], 'layers' => [[['name' => 'x', 'preset' => 'all', 'reads' => ['text']]]]]), 'works on numbers'],
]);
