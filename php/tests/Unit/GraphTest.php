<?php

declare(strict_types=1);

use Phox\ComposableJev\Data;
use Phox\ComposableJev\Graph;
use Phox\ComposableJev\Numbers;
use Phox\ComposableJev\Oracle\Simulator;
use Phox\ComposableJev\Presets;
use Tests\Fakes\FakeTypeSafe;

it('builds xor fluently, which one node cannot compute', function (): void {
    foreach ([[0, 0, false], [0, 1, true], [1, 0, true], [1, 1, false]] as [$a, $b, $want]) {
        expect(Presets::get('xor')->run(new Simulator, ['a' => $a, 'b' => $b])->yes('xor'))->toBe($want);
    }
});

it('adds', function (int $bits, int $x, int $y): void {
    $row = Presets::adder($bits)->run(new Simulator, Presets::sums($bits, [[$x, $y]])[0]);

    expect($row->number('s'))->toBe($x + $y)
        ->and(array_sum(array_map(fn ($i) => ($row->ideal["s{$i}"] > 0.5 ? 1 : 0) << $i, range(0, $bits))))->toBe($x + $y);
})->with([[4, 7, 5], [4, 15, 15], [8, 255, 1], [8, 100, 55], [8, 0, 0]]);

it('lays the adder out two nodes a bit, asked down its carry chain, its sum read most significant first', function (): void {
    $adder = Presets::adder(4);

    expect(count(Presets::adder(8)->nodes()))->toBe(16)
        ->and(Presets::adder(8)->plan()->levels)->toHaveCount(8)
        ->and(array_column($adder->layers(), 1))->toBe(['s3', 's2', 's1', 's0']);
});

it('refuses a node reading a signal that is not there', function (): void {
    expect(fn () => Graph::make()->input('a')->gate('x', 'and', 'a', 'nope')->plan())
        ->toThrow(InvalidArgumentException::class, 'nope, which is not an input or a node');
});

it('refuses a name twice, a reserved name, or a malformed one', function (Closure $build, string $error): void {
    expect($build)->toThrow(InvalidArgumentException::class, $error);
})->with([
    [fn () => Graph::make()->input('a')->gate('a', 'buffer', 'a'), 'used twice'],
    [fn () => Graph::make()->input('text'), 'used twice'],
    [fn () => Graph::make()->input('A1'), 'lowercase letter'],
]);

it('sends whole seconds as whole numbers and on/off inputs as floats', function (): void {
    $values = Graph::make()->input('a')->time()->context('', 'one', 'two')->values(['a' => 1], 3, 32.04);

    expect([Numbers::seconds(32.0), Numbers::seconds(32.04), Numbers::seconds(32.25)])->toBe([32, 32, 32.3])
        ->and($values)->toBe(['a' => 1.0, 'context' => 'two', 'time' => 32]);
});

it('runs a truth table a row per combination and per text', function (): void {
    $graph = Graph::make()->input('a')->context('one', 'two')->gate('b', 'buffer', 'a');

    expect(array_map(fn ($r) => [$r->inputs['context'], $r->inputs['a']], $graph->truthTable(new Simulator)))
        ->toBe([['one', 0.0], ['one', 1.0], ['two', 0.0], ['two', 1.0]]);
});

it('turns into the page spec and back without changing what it computes', function (): void {
    foreach (['4-bit adder' => Presets::adder(4), 'sr latch' => Presets::get('sr latch'), 'ticket triage' => Presets::get('ticket triage')] as $name => $graph) {
        // The page lists nodes in drawing order, so they come back in that order.
        $questions = fn ($net) => array_map(fn ($n) => $n->question(), $net->nodes());
        $rebuilt = Graph::fromSpec($graph->spec());
        $before = $questions($graph);
        $after = $questions($rebuilt);
        ksort($before);
        ksort($after);

        expect($after)->toBe($before, $name)->and($rebuilt->start())->toBe($graph->start(), $name);
    }
});

it('builds the adders in data/presets.json', function (int $bits, string $name): void {
    expect(Presets::adder($bits)->spec())->toBe(Data::read('presets.json')[$name]['graph']);
})->with([[4, '4-bit adder'], [8, '8-bit adder']]);

it('holds every preset to its spec', function (): void {
    foreach (Presets::names() as $name) {
        expect(Presets::get($name)->spec())->toBe(Data::read('presets.json')[$name]['graph'], $name);
    }
});

it('takes a context as text or as a JSON object, from make() or context()', function (): void {
    $fake = FakeTypeSafe::answering(0.9);
    $ticket = ['ticket' => 'Refund me', 'customer' => ['plan' => 'enterprise', 'seats' => 400]];
    Graph::make($ticket)->ask('refund', 'Is the customer asking for money back?', Graph::CONTEXT)->run($fake->jev());

    expect($fake->requests[0]['body']['state'])->toBe(['context' => $ticket])
        ->and(Graph::make('one')->context('two')->contexts())->toBe(['one', 'two'])
        ->and(Graph::make()->context([])->contexts())->toBe([]);
});

it('takes text as another name for the context', function (): void {
    $graph = Graph::make()->text('one')->ask('q', 'Is it?', 'text');
    $old = Graph::fromSpec(['inputs' => [], 'texts' => ['one'], 'layers' => [[['name' => 'q', 'preset' => 'custom', 'reads' => ['text'], 'instructions' => 'Is it?']]]]);

    expect(Graph::TEXT)->toBe(Graph::CONTEXT)
        ->and($graph->nodes()['q']->reads)->toBe(['context'])
        ->and($graph->texts())->toBe(['one'])
        ->and($old->spec())->toBe($graph->spec())
        ->and(fn () => Graph::make()->input('text'))->toThrow(InvalidArgumentException::class, 'used twice');
});
