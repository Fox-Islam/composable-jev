<?php

declare(strict_types=1);

use Phox\ComposableJev\Gates;
use Phox\ComposableJev\Oracle\Batches;
use Phox\ComposableJev\Presets;
use Tests\Fakes\FakeTypeSafe;

it('sends a node only the signals it reads, and caches the answer', function (): void {
    $fake = FakeTypeSafe::answering(0.9);
    $jev = $fake->jev(cacheDir: sys_get_temp_dir() . '/cjev-' . bin2hex(random_bytes(4)));
    $node = Gates::gate('buffer', ['a']);

    expect($jev->fire($node, ['a' => 0.7, 'unused' => 1.0]))->toBe(0.9)
        ->and($jev->fire($node, ['a' => 0.7]))->toBe(0.9)
        ->and([$jev->calls, $jev->cached, $jev->tokens])->toBe([1, 1, 380])
        ->and($fake->requests[0]['body']['state'])->toBe(['a' => 0.7])
        ->and($fake->requests[0]['body']['questions']['out']['type'])->toBe('noul');
});

it('keeps apart states that call different signals by one name', function (): void {
    $items = [['x', null, ['a' => 1, 'b' => 0]], ['y', null, ['a' => 0, 'b' => 1]], ['z', null, ['a' => 1, 'c' => 1]]];

    expect(array_map(fn ($batch) => array_column($batch, 0), Batches::of($items)))->toBe([['x', 'z'], ['y']]);
});

it('asks a batched adder one request a level, for the same sum', function (): void {
    $adder = Presets::adder(4);
    $inputs = Presets::sums(4, [[7, 5]])[0];
    $apart = FakeTypeSafe::byRule($adder);
    $together = FakeTypeSafe::byRule($adder);
    $one = $adder->run($apart->jev(batch: false), $inputs);
    $many = $adder->run($together->jev(), $inputs);

    expect($many->signals)->toBe($one->signals)
        ->and($many->number('s'))->toBe(12)
        ->and([count($apart->requests), count($together->requests)])->toBe([8, 4])
        ->and(array_keys($together->requests[1]['body']['state']))->toBe(['a1', 'b1', 'c1']);
});

it('caches a batched request as one', function (): void {
    $adder = Presets::adder(2);
    $fake = FakeTypeSafe::byRule($adder);
    $jev = $fake->jev(cacheDir: sys_get_temp_dir() . '/cjev-' . bin2hex(random_bytes(4)));
    $inputs = Presets::sums(2, [[1, 3]])[0];

    expect($adder->run($jev, $inputs)->signals)->toBe($adder->run($jev, $inputs)->signals)
        ->and(count($fake->requests))->toBe($jev->calls)
        ->and($jev->cached)->toBe($jev->calls);
});

it('sends on/off inputs as 1.0 and whole seconds as whole numbers', function (): void {
    $fake = FakeTypeSafe::answering(0.1);
    $graph = Presets::get('delay timer')->input('a')->gate('b', 'buffer', 'a');
    $graph->run($fake->jev(batch: false), ['a' => 1], time: 32.0);

    $raw = implode("\n", array_column($fake->requests, 'raw'));
    expect($raw)->toContain('"a":1.0')->and($raw)->toMatch('/"time":32[,}]/')->and($raw)->not->toContain('32.0');
});
