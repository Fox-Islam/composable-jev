"""The fixtures the PHP implementation wrote; `composer fixtures` writes them again."""
from __future__ import annotations

import json
from pathlib import Path
from typing import Any

import pytest
from fake_typesafe import FakeTypeSafe

from composable_jev import Graph, Plan, Simulator, Ticker, canonical, gates, presets, round_half_up, seconds
from composable_jev.numbers import is_number, text

FIXTURES = json.loads((Path(__file__).resolve().parents[2] / 'spec' / 'fixtures.json').read_text(encoding='utf-8'))


def test_rounds_and_shows_numbers_the_same_way() -> None:
    assert [round_half_up(v, p) for v, p, _ in FIXTURES['numbers']['round']] == [w for *_, w in FIXTURES['numbers']['round']]
    for value, want in FIXTURES['numbers']['seconds']:
        assert seconds(value) == want and type(seconds(value)) is type(want), value
    assert [text(v) for v, _ in FIXTURES['numbers']['text']] == [w for _, w in FIXTURES['numbers']['text']]


def test_words_every_gate_the_same_way() -> None:
    for key, want in FIXTURES['questions'].items():
        gate, _, reads = key.partition(' ')
        if gate == 'weighted':
            node = gates.weighted([0.6, 1.0, -2.5], 1.0)
        elif gate == 'choice':
            node = gates.choice('x', 'Which?', ['context'], {'one': 'The first', 'two': 'The second'})
        elif gate == 'score':
            node = gates.score('x', 'How much?', ['context'], ['None', 'Some', 'All'])
        else:
            node = gates.gate(gate, reads.split(','))
        assert node.question() == want, key


def test_works_out_and_describes_every_rule_the_same_way() -> None:
    for want in FIXTURES['rules']:
        node = Graph.from_spec({'inputs': ['a', 'b', 'c'], 'layers': [[want['spec']]]}).nodes()[want['spec']['name']]
        assert node.instructions == want['describe']
        assert node.spec() == want['spec']
        for state, value in zip(want['states'], want['values'], strict=True):
            _close(node.compute(state), value, want['describe'])


def test_orders_the_nodes_of_a_network_the_same_way() -> None:
    for want in FIXTURES['plans']:
        plan = Plan(want['reads'])
        assert (plan.levels, plan.looping) == (want['levels'], want['looping']), want['reads']


def _close(actual: Any, expected: Any, where: str) -> None:
    if is_number(actual) and is_number(expected):
        assert abs(actual - expected) < 1e-12, f'{where}: {actual} is not {expected}'
    else:
        assert actual == expected, where


@pytest.mark.parametrize('run', FIXTURES['runs'], ids=[r['name'] for r in FIXTURES['runs']])
def test_gives_the_same_rows_and_requests(run: dict[str, Any]) -> None:
    graph = Graph.from_spec(run['graph']) if 'graph' in run else presets.get(run['preset'])
    fake = FakeTypeSafe.for_fixture(graph) if run['oracle'] == 'jev' else None
    oracle = Simulator() if fake is None else fake.jev(batch=run.get('batch', True))
    asked: dict[int, list[str]] = {}

    def fired(i: int, kind: str, node: str, _value: Any) -> None:
        if kind == 'fired':
            asked.setdefault(i, []).append(node)

    if run['mode'] == 'truth':
        rows = graph.truth_table(oracle, on_fire=fired)
    else:
        ticker = Ticker(graph, oracle, run['every'])
        for i, tick in enumerate(run['ticks']):
            ticker.tick(tick['inputs'], tick['context'], lambda kind, node, value, i=i: fired(i, kind, node, value))
        rows = ticker.rows

    assert len(rows) == len(run['rows'])
    for i, (row, want) in enumerate(zip(rows, run['rows'], strict=True)):
        where = f"{run['name']}, row {i}"
        assert sorted(row.signals) == sorted(want['signals']), where
        for key, value in want['signals'].items():
            _close(row.signals[key], value, f'{where}, {key}')
        for key, value in want['inputs'].items():
            _close(row.inputs[key], value, f'{where}, input {key}')
        assert row.ideal == want['ideal'], where
        assert row.unsettled == want['unsettled'], where
        assert row.fires == want['fires'], where
        assert asked.get(i, []) == want['asked'], where
    if fake is not None:
        assert [canonical(r['body']) for r in fake.requests] == run['requests']
