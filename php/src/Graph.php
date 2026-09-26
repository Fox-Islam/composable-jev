<?php

declare(strict_types=1);

namespace Phox\ComposableJev;

use Closure;
use InvalidArgumentException;
use Phox\ComposableJev\Oracle\Oracle;

/**
 * Nodes wired together, built fluently. Each node's value - a number, rounded to three places, or
 * the option a choice picked - is passed in the state of every node reading it: one question per
 * node, since questions in one request cannot see each other's answers. A node can read any signal, one after it or itself
 * included, so a graph can loop.
 *
 *     $xor = Graph::make()
 *         ->input('a', 'b')
 *         ->gate('or', 'or', 'a', 'b')
 *         ->gate('nand', 'nand', 'a', 'b')
 *         ->gate('xor', 'and', 'or', 'nand');
 *     $xor->run(Jev::make($key), ['a' => 1, 'b' => 0])->yes('xor');
 */
final class Graph
{
    /**
     * The signal holding the context: what the questions are about, as System One's `state` is.
     */
    public const string CONTEXT = 'context';

    /** The same signal as CONTEXT, by its other name; a node reading `text` reads the context. */
    public const string TEXT = self::CONTEXT;

    public const string TIME = 'time';

    private const string ALIAS = 'text';

    private const string NAME = '/^[a-z][a-z0-9_]{0,11}$/';

    /** @var list<string> */
    private array $inputs = [];

    /** @var list<string|array<mixed>> */
    private array $contexts = [];

    private bool $time = false;

    /** @var array<string, Node> */
    private array $nodes = [];

    /** @var array<string, float> */
    private array $start = [];

    /** @var list<list<string>>|null */
    private ?array $layout = null;

    /**
     * @param  string|array<mixed>|null  $context  what the questions are about: text, or a JSON object or array
     */
    public static function make(string|array|null $context = null): self
    {
        $graph = new self;

        return $context === null ? $graph : $graph->context($context);
    }

    /**
     * A graph as the page edits it, and as `data/presets.json` holds one. The layers are where
     * the page draws the nodes, and only that: the order nodes are asked in comes from what each
     * reads.
     *
     * @param  array<string, mixed>  $spec
     */
    public static function fromSpec(array $spec): self
    {
        $inputs = $spec['inputs'] ?? null;
        $layers = $spec['layers'] ?? null;
        $contexts = $spec['contexts'] ?? $spec['texts'] ?? [];
        if (! is_array($contexts) || ! array_is_list($contexts) || array_filter($contexts, fn ($c) => ! self::given($c)) !== []) {
            throw new InvalidArgumentException('contexts: a list of texts, JSON objects or arrays, none empty');
        }
        if (! is_array($inputs) || ($inputs === [] && $contexts === [] && empty($spec['time']))) {
            throw new InvalidArgumentException('inputs: at least one, a context, or time');
        }
        if (! is_array($layers) || $layers === [] || array_filter($layers, fn ($l) => ! is_array($l) || $l === []) !== []) {
            throw new InvalidArgumentException('layers: at least one, and no empty layer');
        }
        $graph = self::make()->input(...array_map('strval', $inputs))->context(...$contexts);
        if (! empty($spec['time'])) {
            $graph->time();
        }
        foreach (array_merge(...$layers) as $node) {
            $node = is_array($node) ? $node : [];
            $graph->node(self::nodeFrom($node));
            if (isset($node['start'])) {
                $graph->startsAt((string) $node['name'], is_numeric($node['start'])
                    ? (float) $node['start']
                    : throw new InvalidArgumentException("{$node['name']}: start is a number"));
            }
        }
        $graph->layout(...array_map(fn (array $layer) => array_map(fn ($n) => (string) ($n['name'] ?? ''), $layer), $layers));
        $graph->plan();

        return $graph;
    }

    public function input(string ...$names): self
    {
        foreach ($names as $name) {
            $this->claim($name);
            $this->inputs[] = $name;
        }

        return $this;
    }

    /**
     * The `context` input, with the contexts a truth table runs a row each and a tick picks from.
     * Each is text, or a JSON object or array.
     *
     * @param  string|array<mixed>  ...$contexts
     */
    public function context(string|array ...$contexts): self
    {
        $this->contexts = [...$this->contexts, ...array_values($contexts)];

        return $this;
    }

    /**
     * @param  string|array<mixed>  ...$contexts
     */
    public function text(string|array ...$contexts): self
    {
        return $this->context(...$contexts);
    }

    /**
     * The `time` input: the tick number times the tick interval, and 0 in a truth table.
     */
    public function time(): self
    {
        $this->time = true;

        return $this;
    }

    public function gate(string $name, string $gate, string ...$reads): self
    {
        return $this->node(Gates::gate($gate, array_values($reads), $name));
    }

    /**
     * @param  array<string, float>  $weights  signal => weight
     */
    public function weighted(string $name, array $weights, float $bias): self
    {
        return $this->node(Gates::weighted(array_values(array_map('floatval', $weights)), $bias, $name, array_keys($weights)));
    }

    /**
     * @param  string|list<string>  $reads
     */
    public function ask(string $name, string $question, array|string $reads, string $yes = '', string $no = ''): self
    {
        return $this->node(Gates::custom($name, $question, (array) $reads, $yes, $no));
    }

    /**
     * See Node for what a later question and code read of a choice or a score.
     *
     * @param  string|list<string>  $reads
     * @param  array<string, string>  $options  option => what it means
     */
    public function choose(string $name, string $question, array|string $reads, array $options): self
    {
        return $this->node(Gates::choice($name, $question, (array) $reads, $options));
    }

    /**
     * @param  string|list<string>  $reads
     * @param  list<string>  $levels  what each score means, lowest first
     */
    public function score(string $name, string $question, array|string $reads, array $levels): self
    {
        return $this->node(Gates::score($name, $question, (array) $reads, $levels));
    }

    public function all(string $name, string ...$reads): self
    {
        return $this->node(Rules::all($name, array_values($reads)));
    }

    public function any(string $name, string ...$reads): self
    {
        return $this->node(Rules::any($name, array_values($reads)));
    }

    public function sum(string $name, string ...$reads): self
    {
        return $this->node(Rules::sum($name, array_values($reads)));
    }

    public function none(string $name, string ...$reads): self
    {
        return $this->node(Rules::none($name, array_values($reads)));
    }

    public function atLeast(string $name, int $n, string ...$reads): self
    {
        return $this->node(Rules::atLeast($name, $n, array_values($reads)));
    }

    /**
     * @param  array<string, float|int>  $weights  signal => weight
     */
    public function average(string $name, array $weights): self
    {
        return $this->node(Rules::average($name, $weights));
    }

    /**
     * @param  Closure(array<string, mixed>): float  $compute  given the signals it reads by name
     */
    public function rule(string $name, Closure $compute, string ...$reads): self
    {
        return $this->node(Rules::rule($name, $compute, array_values($reads)));
    }

    public function node(Node $node): self
    {
        $node = in_array(self::ALIAS, $node->reads, true)
            ? $node->reading(array_map(fn (string $r) => $r === self::ALIAS ? self::CONTEXT : $r, $node->reads))
            : $node;
        $this->claim($node->name);
        $this->nodes[$node->name] = $node;

        return $this;
    }

    /**
     * What a node in a loop holds before it is first asked.
     */
    public function startsAt(string $name, float $value): self
    {
        $this->start[$name] = $value;

        return $this;
    }

    /**
     * The columns a page draws the nodes in, if not by the level each is asked at.
     *
     * @param  list<string>  ...$columns
     */
    public function layout(array ...$columns): self
    {
        $this->layout = array_values($columns);

        return $this;
    }

    /**
     * @return array<string, Node>
     */
    public function nodes(): array
    {
        return $this->nodes;
    }

    /**
     * The on/off inputs.
     *
     * @return list<string>
     */
    public function inputs(): array
    {
        return $this->inputs;
    }

    /**
     * The contexts with something in them.
     *
     * @return list<string|array<mixed>>
     */
    public function contexts(): array
    {
        return array_values(array_filter($this->contexts, self::given(...)));
    }

    /**
     * @return list<string|array<mixed>>
     */
    public function texts(): array
    {
        return $this->contexts();
    }

    public function hasTime(): bool
    {
        return $this->time;
    }

    /**
     * Every input signal: the on/off inputs, then `context` and `time` where the graph has them.
     *
     * @return list<string>
     */
    public function signals(): array
    {
        return [...$this->inputs, ...($this->contexts() === [] ? [] : [self::CONTEXT]), ...($this->time ? [self::TIME] : [])];
    }

    /**
     * @return array<string, float>
     */
    public function start(): array
    {
        return $this->start;
    }

    public function output(): string
    {
        return (string) array_key_last($this->nodes);
    }

    public function plan(): Plan
    {
        $known = array_flip($this->known());
        $reads = [];
        foreach ($this->nodes as $name => $node) {
            $missing = array_filter($node->reads, fn (string $s) => ! isset($known[$s]));
            if ($missing !== []) {
                throw new InvalidArgumentException("{$name}: reads " . implode(', ', $missing) . ', which is not an input or a node');
            }
            $picked = array_filter($node->reads, fn (string $s) => ($this->nodes[$s] ?? null)?->type === 'choice');
            if ($picked !== [] && ! in_array($node->preset, ['custom', 'choice', 'score'], true)) {
                $choice = reset($picked);
                throw new InvalidArgumentException("{$name}: reads the option {$choice} picked, which is not a number; read one option's probability, such as "
                    . $this->nodes[$choice]->parts()[0]);
            }
            $reads[$name] = $node->reads;
        }

        return new Plan($reads);
    }

    /**
     * Every signal a node can read: the inputs, the nodes, and the parts of a choice or a score.
     *
     * @return list<string>
     */
    public function known(): array
    {
        return [...$this->signals(), ...array_keys($this->nodes), ...$this->parts()];
    }

    /**
     * An oracle that batches is asked each level's loop-free nodes in one request. A node worked
     * out in code is never sent. A node is shown time as whole seconds where they are whole, and
     * every other number as a float rounded to three places.
     *
     * @param  (Closure(string, string, mixed): void)|null  $onFire
     */
    public function simulation(Oracle $oracle, ?Closure $onFire = null): Simulation
    {
        $fire = function (string $name, array $state) use ($oracle): float|Answer {
            $node = $this->nodes[$name];

            return $node->compute === null ? $oracle->fire($node, self::shown($state)) : ($node->compute)(self::shown($state));
        };
        $fireMany = function (array $items) use ($oracle, $fire): array {
            $asked = array_values(array_filter($items, fn (array $i) => $this->nodes[$i[0]]->compute === null));
            $answers = $asked === [] ? [] : $oracle->fireMany(array_map(fn (array $i) => [$i[0], $this->nodes[$i[0]], self::shown($i[1])], $asked));
            $all = [];
            foreach ($items as [$name, $state]) {
                $all[$name] = $answers[$name] ?? $fire($name, $state);
            }

            return $all;
        };

        return new Simulation($this->plan(), $fire, $onFire, $oracle->batching() ? $fireMany : null);
    }

    /**
     * The same graph with every node answering exactly by its rule: null for a node with no
     * rule, on its boundary, or reading one that is null.
     */
    public function ideal(): Simulation
    {
        return new Simulation($this->plan(), function (string $name, array $state): ?float {
            $expected = in_array(null, $state, true) ? null : $this->nodes[$name]->expected($state);

            return $expected === null ? null : (float) $expected;
        });
    }

    /**
     * Every node from its starting value, settled once against the inputs.
     *
     * @param  array<string, mixed>  $inputs  on/off input => 0 or 1
     */
    public function run(Oracle $oracle, array $inputs = [], int $context = 0, float|int $time = 0): Row
    {
        return $this->tick($oracle, $this->values($inputs, $context, $time));
    }

    /**
     * One step: `$values` settled from where `$previous` ended, or from the starting values.
     *
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>|null  $previous  inputs and nodes as the last step ended
     * @param  (Closure(string, string, mixed): void)|null  $onFire
     */
    public function tick(Oracle $oracle, array $values, ?array $previous = null, ?array $previousIdeal = null, ?Closure $onFire = null): Row
    {
        $simulation = $this->simulation($oracle, $onFire);
        if ($previous === null) {
            return $this->row($values, $simulation->fresh($values, $this->start), $this->ideal()->fresh($values, $this->start));
        }

        return $this->row(
            $values,
            $simulation->step($this->restore($previous), $values),
            $this->ideal()->step($this->restore($previousIdeal ?? $previous), $values),
        );
    }

    /**
     * One row per combination of the on/off inputs, and per context: each from the starting values,
     * so a latch forgets between rows.
     *
     * @param  (Closure(int, Row): void)|null  $onRow
     * @param  (Closure(int, string, string, mixed): void)|null  $onFire  row, kind, node, value
     * @return list<Row>
     */
    public function truthTable(Oracle $oracle, ?Closure $onRow = null, ?Closure $onFire = null): array
    {
        $rows = [];
        foreach ($this->combinations() as $i => $values) {
            $row = $this->tick($oracle, $values, onFire: $onFire === null ? null : fn (string $k, string $n, mixed $v) => $onFire($i, $k, $n, $v));
            $rows[] = $row;
            if ($onRow !== null) {
                $onRow($i, $row);
            }
        }

        return $rows;
    }

    public function ticker(Oracle $oracle, float $every = 1.0): Ticker
    {
        return new Ticker($this, $oracle, $every);
    }

    /**
     * The values a step runs with: each on/off input as 0.0 or 1.0, the context picked from those
     * given, and time as whole seconds where it is whole.
     *
     * @param  array<string, mixed>  $inputs
     * @return array<string, mixed>
     */
    public function values(array $inputs, int $context = 0, float|int $time = 0): array
    {
        $values = [];
        foreach ($this->inputs as $name) {
            $values[$name] = (float) ($inputs[$name] ?? 0);
        }
        $contexts = $this->contexts();
        if ($contexts !== []) {
            $values[self::CONTEXT] = $contexts[$context % count($contexts)];
        }
        if ($this->time) {
            $values[self::TIME] = Numbers::seconds($time);
        }

        return $values;
    }

    /**
     * The columns the page draws: the layout, or else the level each node is asked at.
     *
     * @return list<list<string>>
     */
    public function layers(): array
    {
        if ($this->layout !== null) {
            return $this->layout;
        }

        return array_map(fn (array $level) => array_merge(...$level), $this->plan()->levels);
    }

    /**
     * The graph as the page edits it.
     *
     * @return array<string, mixed>
     */
    public function spec(): array
    {
        return array_filter([
            'inputs' => $this->inputs,
            'contexts' => $this->contexts === [] ? null : $this->contexts,
            'time' => $this->time ?: null,
            'layers' => array_map(fn (array $column) => array_map(
                fn (string $n) => $this->nodes[$n]->spec() + (isset($this->start[$n]) ? ['start' => $this->start[$n]] : []),
                $column,
            ), $this->layers()),
        ], fn ($v) => $v !== null);
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private static function shown(array $state): array
    {
        foreach ($state as $key => $value) {
            if (is_int($value) || is_float($value)) {
                $state[$key] = $key === self::TIME ? Numbers::seconds($value) : Numbers::round((float) $value, 3);
            }
        }

        return $state;
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private static function nodeFrom(array $spec): Node
    {
        $name = (string) ($spec['name'] ?? '');
        $preset = $spec['preset'] ?? null;
        $reads = $spec['reads'] ?? null;
        if (! is_array($reads) || $reads === [] || count(array_unique($reads)) !== count($reads)) {
            throw new InvalidArgumentException("{$name}: read at least one signal, each once");
        }
        $reads = array_map(fn ($r) => (string) $r === self::ALIAS ? self::CONTEXT : (string) $r, array_values($reads));
        if (! in_array($preset, ['custom', 'choice', 'score'], true) && in_array(self::CONTEXT, $reads, true)) {
            throw new InvalidArgumentException("{$name}: {$preset} works on numbers, so only a question can read the context");
        }

        $weights = array_map('floatval', array_values((array) ($spec['weights'] ?? [])));

        return match (true) {
            is_string($preset) && isset(Gates::templates()[$preset]) => Gates::gate($preset, $reads, $name),
            $preset === 'all' => Rules::all($name, $reads),
            $preset === 'any' => Rules::any($name, $reads),
            $preset === 'none' => Rules::none($name, $reads),
            $preset === 'atLeast' => Rules::atLeast($name, (int) ($spec['n'] ?? 1), $reads),
            $preset === 'average' => Rules::average($name, count($weights) === count($reads) ? array_combine($reads, $weights)
                : throw new InvalidArgumentException("{$name}: one weight per signal read")),
            $preset === 'weighted' => Gates::weighted($weights, (float) ($spec['bias'] ?? 0), $name, $reads),
            $preset === 'custom' => Gates::custom($name, (string) ($spec['instructions'] ?? ''), $reads, (string) ($spec['yes'] ?? ''), (string) ($spec['no'] ?? '')),
            $preset === 'choice' => Gates::choice($name, (string) ($spec['instructions'] ?? ''), $reads, (array) ($spec['options'] ?? [])),
            $preset === 'score' => Gates::score($name, (string) ($spec['instructions'] ?? ''), $reads, array_values((array) ($spec['levels'] ?? []))),
            $preset === 'sum' => Rules::sum($name, $reads),
            default => throw new InvalidArgumentException("{$name}: preset is a gate, a rule, weighted or custom"),
        };
    }

    /**
     * Whether a context has something in it: text that is not blank, or a non-empty object or array.
     */
    private static function given(mixed $context): bool
    {
        return is_string($context) ? trim($context) !== '' : is_array($context) && $context !== [];
    }

    /**
     * @return list<string>
     */
    private function parts(): array
    {
        return array_merge(...array_values(array_map(fn (Node $n) => $n->parts(), $this->nodes)));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function combinations(): array
    {
        $count = count($this->inputs);
        $bits = [];
        for ($i = 0; $i < 2 ** $count; $i++) {
            $bits[] = array_combine($this->inputs, array_map(fn (int $j) => (float) ($i >> ($count - 1 - $j) & 1), array_keys($this->inputs)));
        }
        $contexts = $this->contexts() === [] ? [0] : array_keys($this->contexts());
        $rows = [];
        foreach ($contexts as $context) {
            foreach ($bits as $b) {
                $rows[] = $this->values($b, $context);
            }
        }

        return $rows;
    }

    /**
     * The signals a step starts from: what was given, where it names a signal of this graph.
     *
     * @param  array<string, mixed>  $previous
     * @return array<string, mixed>
     */
    private function restore(array $previous): array
    {
        $signals = [];
        foreach (array_keys($this->nodes) as $node) {
            $signals[$node] = $this->start[$node] ?? 0.0;
        }
        $known = array_flip($this->known());

        return array_merge($signals, array_intersect_key($previous, $known));
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function row(array $values, Settled $settled, Settled $ideal): Row
    {
        $nodes = array_keys($this->nodes);
        $answered = array_flip([...$nodes, ...$this->parts()]);

        return new Row(
            $values,
            array_intersect_key($settled->signals, $answered),
            array_intersect_key($ideal->signals, array_flip($nodes)),
            $this->output(),
            $settled->unsettled === [] ? [] : array_merge(...$settled->unsettled),
            $settled->fires,
        );
    }

    private function claim(string $name): void
    {
        if (! preg_match(self::NAME, $name)) {
            throw new InvalidArgumentException("'{$name}': a name is a lowercase letter, then up to 11 letters, digits or _");
        }
        if (in_array($name, [self::CONTEXT, self::ALIAS, self::TIME, ...$this->inputs, ...array_keys($this->nodes)], true)) {
            throw new InvalidArgumentException("{$name}: used twice");
        }
    }
}
