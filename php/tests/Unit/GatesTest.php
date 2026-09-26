<?php

declare(strict_types=1);

use Phox\ComposableJev\Gates;

dataset('two-input gates', [
    'and' => ['and', fn (int $a, int $b) => $a && $b],
    'or' => ['or', fn (int $a, int $b) => $a || $b],
    'nand' => ['nand', fn (int $a, int $b) => ! ($a && $b)],
    'nor' => ['nor', fn (int $a, int $b) => ! ($a || $b)],
    'xor' => ['xor', fn (int $a, int $b) => ($a xor $b)],
]);

it('follows its truth table', function (string $gate, Closure $truth): void {
    foreach ([[0, 0], [0, 1], [1, 0], [1, 1]] as [$a, $b]) {
        expect(Gates::builtIn()[$gate]->expected(['a' => $a, 'b' => $b]))->toBe((bool) $truth($a, $b));
    }
})->with('two-input gates');

it('says which side the boundary falls on', function (): void {
    foreach (Gates::builtIn() as $gate) {
        expect(str_contains($gate->yes . $gate->no, '0.5 or less') || str_contains($gate->instructions, 'greater than 0.5'))->toBeTrue();
    }
});

it('asks about the signals it reads by name, however many', function (): void {
    expect(Gates::gate('and', ['or', 'nand'])->instructions)->toBe('Are or and nand both greater than 0.5?')
        ->and(Gates::gate('and', ['p', 'q', 'r'])->instructions)->toBe('Are p, q and r all greater than 0.5?')
        ->and(Gates::gate('and', ['p', 'q', 'r'])->expected(['p' => 1, 'q' => 1, 'r' => 0]))->toBeFalse()
        ->and(Gates::gate('majority', ['p', 'q', 'r'])->expected(['p' => 1, 'q' => 1, 'r' => 0]))->toBeTrue();
});

it('refuses the wrong number of signals', function (string $gate, array $reads, string $error): void {
    expect(fn () => Gates::gate($gate, $reads))->toThrow(InvalidArgumentException::class, $error);
})->with([
    ['buffer', ['a', 'b'], 'one signal'],
    ['and', ['a'], 'two or more'],
    ['maybe', ['a'], 'not a gate'],
]);

it('writes a weighted sum without trailing decimals, with its rule', function (): void {
    $node = Gates::weighted([0.6, 0.6], 1.0);

    expect($node->instructions)->toBe('Is 0.6 × a + 0.6 × b greater than 1?')
        ->and($node->expected(['a' => 1, 'b' => 1]))->toBeTrue()
        ->and($node->expected(['a' => 1, 'b' => 0]))->toBeFalse();
});

it('gives a custom question no rule, and refuses an empty one', function (): void {
    expect(Gates::custom('q', ' Is it? ', ['text'])->expected(['text' => 'x']))->toBeNull()
        ->and(fn () => Gates::custom('q', ' ', ['text']))->toThrow(InvalidArgumentException::class, 'write a question');
});

it('names every gate in the README', function (): void {
    $readme = (string) file_get_contents(dirname(__DIR__, 3) . '/README.md');

    foreach (array_keys(Gates::templates()) as $gate) {
        expect($readme)->toContain("`{$gate}`");
    }
});
