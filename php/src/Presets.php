<?php

declare(strict_types=1);

namespace Phox\ComposableJev;

use InvalidArgumentException;

/**
 * The graphs in `data/presets.json`, each with what it shows and the inputs its first tick
 * starts from.
 */
final class Presets
{
    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_keys(Data::read('presets.json'));
    }

    public static function get(string $name): Graph
    {
        return Graph::fromSpec(self::entry($name)['graph']);
    }

    public static function about(string $name): string
    {
        return self::entry($name)['about'];
    }

    /**
     * @return array<string, int>
     */
    public static function starts(string $name): array
    {
        return self::entry($name)['starts'] ?? [];
    }

    /**
     * A ripple-carry adder of a and b into s, least significant bit first, of any width. Each
     * bit is two nodes: the sum is xor of a, b and the carry in, and the carry out is their
     * majority, the last carry being the top bit of the sum. The sum row is drawn the other way
     * from the carries, so it reads most significant bit first.
     */
    public static function adder(int $bits): Graph
    {
        $graph = Graph::make();
        for ($i = 0; $i < $bits; $i++) {
            $graph->input("a{$i}");
        }
        for ($i = 0; $i < $bits; $i++) {
            $graph->input("b{$i}");
        }
        $carries = [];
        for ($i = 0; $i < $bits; $i++) {
            $carry = $i < $bits - 1 ? 'c' . ($i + 1) : "s{$bits}";
            $reads = $i === 0 ? ['a0', 'b0'] : ["a{$i}", "b{$i}", "c{$i}"];
            $graph->gate("s{$i}", 'xor', ...$reads)->gate($carry, $i === 0 ? 'and' : 'majority', ...$reads);
            $carries[] = $carry;
        }

        return $graph->layout(...array_map(fn (string $carry, int $i) => [$carry, 's' . ($bits - 1 - $i)], $carries, array_keys($carries)));
    }

    /**
     * Each adder input set to a pair of numbers.
     *
     * @param  list<array{int, int}>  $pairs
     * @return list<array<string, int>>
     */
    public static function sums(int $bits, array $pairs): array
    {
        return array_map(function (array $pair) use ($bits): array {
            $inputs = [];
            for ($i = 0; $i < $bits; $i++) {
                $inputs["a{$i}"] = $pair[0] >> $i & 1;
                $inputs["b{$i}"] = $pair[1] >> $i & 1;
            }

            return $inputs;
        }, $pairs);
    }

    /**
     * Whether a truth table is worth running: the 8-bit adder's has 65,536 rows.
     */
    public static function truthTable(string $name): bool
    {
        return self::entry($name)['truthTable'] ?? true;
    }

    /**
     * @return array{about: string, graph: array<string, mixed>, starts?: array<string, int>, truthTable?: bool}
     */
    private static function entry(string $name): array
    {
        return Data::read('presets.json')[$name]
            ?? throw new InvalidArgumentException("{$name} is not a preset; the presets are " . implode(', ', self::names()));
    }
}
