from __future__ import annotations

import re
from collections.abc import Callable
from dataclasses import replace
from typing import Any, Optional, Union

from . import gates, rules
from .answer import Answer
from .node import Compute, Node
from .numbers import Context, State, Value, is_number, round_half_up, seconds
from .oracle import Oracle
from .plan import Plan
from .row import Row
from .simulation import OnFire, Settled, Simulation
from .ticker import Ticker

# The signal holding the context: what the questions are about, as System One's `state` is
CONTEXT = 'context'

# The same signal as CONTEXT, by its other name; a node reading `text` reads the context
TEXT = CONTEXT

_ALIAS = 'text'

TIME = 'time'

_NAME = re.compile(r'[a-z][a-z0-9_]{0,11}')

# row, kind, node, value
OnRowFire = Callable[[int, str, str, Value], None]


class Graph:
    """
    Nodes wired together, built fluently. Each node's value - a number, rounded to three places, or
    the option a choice picked - is passed in the state of every node reading it: one question per
    node, since questions in one request cannot see each other's answers. A node can read any
    signal, one after it or itself included, so a graph can loop.

        xor = (Graph.make()
            .input('a', 'b')
            .gate('or', 'or', 'a', 'b')
            .gate('nand', 'nand', 'a', 'b')
            .gate('xor', 'and', 'or', 'nand'))
        xor.run(Jev.make(key), {'a': 1, 'b': 0}).yes('xor')
    """

    CONTEXT = CONTEXT

    TEXT = TEXT

    TIME = TIME

    def __init__(self) -> None:
        self._inputs: list[str] = []
        self._contexts: list[Context] = []
        self._time = False
        self._nodes: dict[str, Node] = {}
        self._start: dict[str, float] = {}
        self._layout: Optional[list[list[str]]] = None

    @staticmethod
    def make(context: Optional[Context] = None) -> Graph:
        """`context` is what the questions are about: text, or a JSON object or array."""
        graph = Graph()

        return graph if context is None else graph.context(context)

    @staticmethod
    def from_spec(spec: dict[str, Any]) -> Graph:
        """
        A graph as the page edits it, and as data/presets.json holds one. The layers are where
        the page draws the nodes, and only that: the order nodes are asked in comes from what each
        reads.
        """
        inputs = spec.get('inputs')
        layers = spec.get('layers')
        contexts = spec.get('contexts', spec.get('texts', []))
        if not isinstance(contexts, list) or any(not _given(c) for c in contexts):
            raise ValueError('contexts: a list of texts, JSON objects or arrays, none empty')
        if not isinstance(inputs, list) or (not inputs and not contexts and not spec.get('time')):
            raise ValueError('inputs: at least one, a context, or time')
        if not isinstance(layers, list) or not layers or any(not isinstance(l, list) or not l for l in layers):
            raise ValueError('layers: at least one, and no empty layer')
        graph = Graph.make().input(*map(str, inputs)).context(*contexts)
        if spec.get('time'):
            graph.time()
        for node in (n for layer in layers for n in layer):
            node = node if isinstance(node, dict) else {}
            graph.node(_node_from(node))
            if 'start' in node:
                if not is_number(node['start']):
                    raise ValueError(f"{node['name']}: start is a number")
                graph.starts_at(node['name'], float(node['start']))
        graph.layout(*[[str(n.get('name', '')) for n in layer] for layer in layers])
        graph.plan()

        return graph

    def input(self, *names: str) -> Graph:
        for name in names:
            self._claim(name)
            self._inputs.append(name)

        return self

    def context(self, *contexts: Context) -> Graph:
        """
        The `context` input, with the contexts a truth table runs a row each and a tick picks from.
        Each is text, or a JSON object or array.
        """
        self._contexts.extend(contexts)

        return self

    def text(self, *contexts: Context) -> Graph:
        return self.context(*contexts)

    def time(self) -> Graph:
        """The `time` input: the tick number times the tick interval, and 0 in a truth table."""
        self._time = True

        return self

    def gate(self, name: str, gate: str, *reads: str) -> Graph:
        return self.node(gates.gate(gate, list(reads), name))

    def weighted(self, name: str, weights: dict[str, float], bias: float) -> Graph:
        """`weights` is signal => weight."""
        return self.node(gates.weighted(list(weights.values()), bias, name, list(weights)))

    def ask(self, name: str, question: str, reads: Union[str, list[str]], yes: str = '', no: str = '') -> Graph:
        return self.node(gates.custom(name, question, [reads] if isinstance(reads, str) else reads, yes, no))

    def choose(self, name: str, question: str, reads: Union[str, list[str]], options: dict[str, str]) -> Graph:
        """See Node for what a later question and code read of a choice or a score."""
        return self.node(gates.choice(name, question, [reads] if isinstance(reads, str) else reads, options))

    def score(self, name: str, question: str, reads: Union[str, list[str]], levels: list[str]) -> Graph:
        return self.node(gates.score(name, question, [reads] if isinstance(reads, str) else reads, levels))

    def sum(self, name: str, *reads: str) -> Graph:
        return self.node(rules.sum_of(name, reads))

    def all(self, name: str, *reads: str) -> Graph:
        return self.node(rules.all_of(name, reads))

    def any(self, name: str, *reads: str) -> Graph:
        return self.node(rules.any_of(name, reads))

    def none(self, name: str, *reads: str) -> Graph:
        return self.node(rules.none_of(name, reads))

    def at_least(self, name: str, n: int, *reads: str) -> Graph:
        return self.node(rules.at_least(name, n, reads))

    def average(self, name: str, weights: dict[str, float]) -> Graph:
        """`weights` is signal => weight."""
        return self.node(rules.average(name, weights))

    def rule(self, name: str, compute: Compute, *reads: str) -> Graph:
        """`compute` is given the signals it reads by name."""
        return self.node(rules.rule(name, compute, reads))

    def node(self, node: Node) -> Graph:
        if _ALIAS in node.reads:
            node = replace(node, reads=tuple(CONTEXT if r == _ALIAS else r for r in node.reads))
        self._claim(node.name)
        self._nodes[node.name] = node

        return self

    def starts_at(self, name: str, value: float) -> Graph:
        """What a node in a loop holds before it is first asked."""
        self._start[name] = float(value)

        return self

    def layout(self, *columns: list[str]) -> Graph:
        """The columns a page draws the nodes in, if not by the level each is asked at."""
        self._layout = [list(c) for c in columns]

        return self

    def nodes(self) -> dict[str, Node]:
        return dict(self._nodes)

    def inputs(self) -> list[str]:
        """The on/off inputs."""
        return list(self._inputs)

    def contexts(self) -> list[Context]:
        """The contexts with something in them."""
        return [c for c in self._contexts if _given(c)]

    def texts(self) -> list[Context]:
        return self.contexts()

    def has_time(self) -> bool:
        return self._time

    def signals(self) -> list[str]:
        """Every input signal: the on/off inputs, then `context` and `time` where the graph has them."""
        return [*self._inputs, *([CONTEXT] if self.contexts() else []), *([TIME] if self._time else [])]

    def start(self) -> dict[str, float]:
        return dict(self._start)

    def output(self) -> str:
        return list(self._nodes)[-1] if self._nodes else ''

    def plan(self) -> Plan:
        known = set(self.known())
        reads: dict[str, list[str]] = {}
        for name, node in self._nodes.items():
            missing = [s for s in node.reads if s not in known]
            if missing:
                raise ValueError(f"{name}: reads {', '.join(missing)}, which is not an input or a node")
            picked = next((s for s in node.reads if s in self._nodes and self._nodes[s].type == 'choice'), None)
            if picked is not None and node.preset not in ('custom', 'choice', 'score'):
                raise ValueError(
                    f'{name}: reads the option {picked} picked, which is not a number; '
                    f"read one option's probability, such as {self._nodes[picked].parts()[0]}"
                )
            reads[name] = list(node.reads)

        return Plan(reads)

    def known(self) -> list[str]:
        """Every signal a node can read: the inputs, the nodes, and the parts of a choice or a score."""
        return [*self.signals(), *self._nodes, *self._parts()]

    def _parts(self) -> list[str]:
        return [p for n in self._nodes.values() for p in n.parts()]

    def simulation(self, oracle: Oracle, on_fire: Optional[OnFire] = None) -> Simulation:
        """
        An oracle that batches is asked each level's loop-free nodes in one request. A node worked
        out in code is never sent. A node is shown time as whole seconds where they are whole, and
        every other number as a float rounded to three places.
        """
        def fire(name: str, state: State) -> Union[Value, Answer]:
            node = self._nodes[name]

            return oracle.fire(node, _shown(state)) if node.compute is None else node.compute(_shown(state))

        def fire_many(items: list[tuple[str, State]]) -> dict[str, Union[Value, Answer]]:
            asked = [(n, self._nodes[n], _shown(s)) for n, s in items if self._nodes[n].compute is None]
            answers = oracle.fire_many(asked) if asked else {}

            return {n: answers[n] if n in answers else fire(n, s) for n, s in items}

        return Simulation(self.plan(), fire, on_fire, fire_many if oracle.batching() else None)

    def ideal(self) -> Simulation:
        """
        The same graph with every node answering exactly by its rule: None for a node with no
        rule, on its boundary, or reading one that is None.
        """
        def fire(name: str, state: State) -> Optional[float]:
            expected = None if any(v is None for v in state.values()) else self._nodes[name].expected(state)

            return None if expected is None else float(expected)

        return Simulation(self.plan(), fire)

    def run(self, oracle: Oracle, inputs: Optional[dict[str, float]] = None, context: int = 0, time: float = 0) -> Row:
        """Every node from its starting value, settled once against the inputs."""
        return self.tick(oracle, self.values(inputs or {}, context, time))

    def tick(
        self, oracle: Oracle, values: State, previous: Optional[State] = None,
        previous_ideal: Optional[State] = None, on_fire: Optional[OnFire] = None,
    ) -> Row:
        """One step: `values` settled from where `previous` ended, or from the starting values."""
        simulation = self.simulation(oracle, on_fire)
        if previous is None:
            return self._row(values, simulation.fresh(values, self._start), self.ideal().fresh(values, self._start))

        return self._row(
            values,
            simulation.step(self._restore(previous), values),
            self.ideal().step(self._restore(previous_ideal if previous_ideal is not None else previous), values),
        )

    def truth_table(
        self, oracle: Oracle, on_row: Optional[Callable[[int, Row], None]] = None, on_fire: Optional[OnRowFire] = None,
    ) -> list[Row]:
        """
        One row per combination of the on/off inputs, and per context: each from the starting values,
        so a latch forgets between rows.
        """
        rows: list[Row] = []
        for i, values in enumerate(self._combinations()):
            fired = None if on_fire is None else (lambda k, n, v, i=i: on_fire(i, k, n, v))
            row = self.tick(oracle, values, on_fire=fired)
            rows.append(row)
            if on_row is not None:
                on_row(i, row)

        return rows

    def ticker(self, oracle: Oracle, every: float = 1.0) -> Ticker:
        return Ticker(self, oracle, every)

    def values(self, inputs: dict[str, float], context: int = 0, time: float = 0) -> State:
        """
        The values a step runs with: each on/off input as 0.0 or 1.0, the context picked from those
        written, and time as whole seconds where it is whole.
        """
        values: State = {name: float(inputs.get(name, 0)) for name in self._inputs}
        contexts = self.contexts()
        if contexts:
            values[CONTEXT] = contexts[context % len(contexts)]
        if self._time:
            values[TIME] = seconds(time)

        return values

    def layers(self) -> list[list[str]]:
        """The columns the page draws: the layout, or else the level each node is asked at."""
        if self._layout is not None:
            return self._layout

        return [[n for c in level for n in c] for level in self.plan().levels]

    def spec(self) -> dict[str, Any]:
        """The graph as the page edits it."""
        spec: dict[str, Any] = {'inputs': list(self._inputs)}
        if self._contexts:
            spec['contexts'] = list(self._contexts)
        if self._time:
            spec['time'] = True
        spec['layers'] = [
            [{**self._nodes[n].spec(), **({'start': self._start[n]} if n in self._start else {})} for n in column]
            for column in self.layers()
        ]

        return spec

    def _combinations(self) -> list[State]:
        count = len(self._inputs)
        bits = [
            {name: float(i >> (count - 1 - j) & 1) for j, name in enumerate(self._inputs)}
            for i in range(2**count)
        ]
        contexts = range(len(self.contexts())) if self.contexts() else [0]

        return [self.values(b, context) for context in contexts for b in bits]

    def _restore(self, previous: State) -> State:
        """The signals a step starts from: what was given, where it names a signal of this graph."""
        signals: State = {node: self._start.get(node, 0.0) for node in self._nodes}
        known = set(self.known())
        signals.update({k: v for k, v in previous.items() if k in known})

        return signals

    def _row(self, values: State, settled: Settled, ideal: Settled) -> Row:
        return Row(
            values,
            {n: settled.signals[n] for n in [*self._nodes, *self._parts()] if n in settled.signals},
            {n: ideal.signals[n] for n in self._nodes},  # type: ignore[misc]
            self.output(),
            [n for c in settled.unsettled for n in c],
            settled.fires,
        )

    def _claim(self, name: str) -> None:
        if not _NAME.fullmatch(name):
            raise ValueError(f"'{name}': a name is a lowercase letter, then up to 11 letters, digits or _")
        if name in (CONTEXT, _ALIAS, TIME, *self._inputs, *self._nodes):
            raise ValueError(f'{name}: used twice')


def _shown(state: State) -> State:
    return {
        k: (seconds(v) if k == TIME else round_half_up(float(v), 3)) if is_number(v) else v  # type: ignore[arg-type]
        for k, v in state.items()
    }


def _given(context: Any) -> bool:
    """Whether a context has something in it: text that is not blank, or a non-empty object or array."""
    return context.strip() != '' if isinstance(context, str) else isinstance(context, (dict, list)) and len(context) > 0


def _node_from(spec: dict[str, Any]) -> Node:
    name = str(spec.get('name', ''))
    preset = spec.get('preset')
    reads = spec.get('reads')
    if not isinstance(reads, list) or not reads or len(set(reads)) != len(reads):
        raise ValueError(f'{name}: read at least one signal, each once')
    reads = [CONTEXT if str(r) == _ALIAS else str(r) for r in reads]
    if preset not in ('custom', 'choice', 'score') and CONTEXT in reads:
        raise ValueError(f'{name}: {preset} works on numbers, so only a question can read the context')
    weights = [float(w) for w in spec.get('weights', [])]
    if isinstance(preset, str) and preset in gates.templates():
        return gates.gate(preset, reads, name)
    built = {
        'all': lambda: rules.all_of(name, reads),
        'any': lambda: rules.any_of(name, reads),
        'none': lambda: rules.none_of(name, reads),
        'atLeast': lambda: rules.at_least(name, int(spec.get('n', 1)), reads),
        'sum': lambda: rules.sum_of(name, reads),
        'choice': lambda: gates.choice(name, str(spec.get('instructions', '')), reads, dict(spec.get('options', {}))),
        'score': lambda: gates.score(name, str(spec.get('instructions', '')), reads, list(spec.get('levels', []))),
    }
    if preset in built:
        return built[preset]()
    if preset == 'average':
        if len(weights) != len(reads):
            raise ValueError(f'{name}: one weight per signal read')
        return rules.average(name, dict(zip(reads, weights, strict=True)))
    if preset == 'weighted':
        return gates.weighted(weights, float(spec.get('bias', 0)), name, reads)
    if preset == 'custom':
        return gates.custom(name, str(spec.get('instructions', '')), reads, str(spec.get('yes', '')), str(spec.get('no', '')))
    raise ValueError(f'{name}: preset is a gate, a rule, weighted or custom')
