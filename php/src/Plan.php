<?php

declare(strict_types=1);

namespace Phox\ComposableJev;

/**
 * The order a graph's nodes are asked in: levels of strongly connected components. A component
 * with no loop is one node, asked once what it reads is final; a component with a loop is asked a
 * node at a time. Kosaraju's passes put the components in the order they depend on each other.
 *
 * A node reading another's part, `team.billing`, depends on that node.
 */
final readonly class Plan
{
    /** @var array<string, list<string>> signal => the nodes that read it */
    public array $readers;

    /** @var list<list<list<string>>> */
    public array $levels;

    /** @var list<string> the nodes that can reach themselves */
    public array $looping;

    /**
     * @param  array<string, list<string>>  $reads  node => the signals it reads
     */
    public function __construct(public array $reads)
    {
        $readers = [];
        foreach ($reads as $node => $signals) {
            foreach (array_unique($signals) as $signal) {
                $readers[$signal][] = $node;
            }
        }
        $this->readers = $readers;
        $this->levels = $this->levels();
        $looping = [];
        foreach ($this->levels as $level) {
            foreach ($level as $component) {
                $looping = $this->looped($component) ? [...$looping, ...$component] : $looping;
            }
        }
        $this->looping = $looping;
    }

    /**
     * @param  list<string>  $component
     */
    public function looped(array $component): bool
    {
        return count($component) > 1 || in_array($component[0], $this->sources($component[0]), true);
    }

    /**
     * The nodes a node reads, or reads a part of, in the order it reads them.
     *
     * @return list<string>
     */
    public function sources(string $node): array
    {
        $sources = [];
        foreach ($this->reads[$node] as $signal) {
            $base = explode('.', $signal, 2)[0];
            if (isset($this->reads[$base]) && ! in_array($base, $sources, true)) {
                $sources[] = $base;
            }
        }

        return $sources;
    }

    /**
     * Nodes in the order a depth-first pass over readers finishes them.
     *
     * @param  list<string>  $order
     * @param  array<string, list<string>>  $forward
     * @return list<string>
     */
    private static function finished(array $order, array $forward): array
    {
        $finished = [];
        $seen = [];
        foreach ($order as $root) {
            if (isset($seen[$root])) {
                continue;
            }
            $seen[$root] = true;
            $stack = [[$root, 0]];
            while ($stack !== []) {
                [$node, $next] = $stack[count($stack) - 1];
                $child = $forward[$node][$next] ?? null;
                if ($child === null) {
                    array_pop($stack);
                    $finished[] = $node;

                    continue;
                }
                $stack[count($stack) - 1][1]++;
                if (! isset($seen[$child])) {
                    $seen[$child] = true;
                    $stack[] = [$child, 0];
                }
            }
        }

        return $finished;
    }

    /**
     * The components, walked back along what each node reads, in dependency order.
     *
     * @param  list<string>  $finished
     * @param  array<string, list<string>>  $backward
     * @param  array<string, int>  $position
     * @return list<list<string>>
     */
    private static function components(array $finished, array $backward, array $position): array
    {
        $components = [];
        $assigned = [];
        foreach (array_reverse($finished) as $root) {
            if (isset($assigned[$root])) {
                continue;
            }
            $members = [];
            $stack = [$root];
            $assigned[$root] = true;
            while ($stack !== []) {
                $node = array_pop($stack);
                $members[] = $node;
                foreach ($backward[$node] as $source) {
                    if (! isset($assigned[$source])) {
                        $assigned[$source] = true;
                        $stack[] = $source;
                    }
                }
            }
            $components[] = self::sorted($members, $position);
        }

        return $components;
    }

    /**
     * @param  list<list<string>>  $components
     * @param  array<string, list<string>>  $backward
     * @return list<list<list<string>>>
     */
    private static function grouped(array $components, array $backward): array
    {
        $of = [];
        foreach ($components as $i => $members) {
            foreach ($members as $node) {
                $of[$node] = $i;
            }
        }
        $level = [];
        $grouped = [];
        foreach ($components as $i => $members) {
            $level[$i] = 0;
            foreach ($members as $node) {
                foreach ($backward[$node] as $source) {
                    $level[$i] = $of[$source] === $i ? $level[$i] : max($level[$i], $level[$of[$source]] + 1);
                }
            }
            $grouped[$level[$i]][] = $members;
        }
        ksort($grouped);

        return array_values($grouped);
    }

    /**
     * @param  list<string>  $nodes
     * @param  array<string, int>  $position
     * @return list<string>
     */
    private static function sorted(array $nodes, array $position): array
    {
        usort($nodes, fn (string $a, string $b) => $position[$a] <=> $position[$b]);

        return $nodes;
    }

    /**
     * @return list<list<list<string>>>
     */
    private function levels(): array
    {
        $order = array_keys($this->reads);
        $position = array_flip($order);
        $forward = array_fill_keys($order, []);
        $backward = [];
        foreach ($order as $node) {
            $backward[$node] = $this->sources($node);
            foreach ($backward[$node] as $source) {
                $forward[$source][] = $node;
            }
        }
        $forward = array_map(fn (array $readers) => self::sorted($readers, $position), $forward);
        $components = self::components(self::finished($order, $forward), $backward, $position);

        return self::grouped($components, $backward);
    }
}
