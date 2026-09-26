from __future__ import annotations

import json
import re
from pathlib import Path

import pytest
from fake_typesafe import FakeTypeSafe

from composable_jev import Graph, Plan, Simulator, batches, gates, presets

PRESETS = json.loads((Path(__file__).resolve().parents[2] / 'data' / 'presets.json').read_text(encoding='utf-8'))


def test_builds_xor_fluently_which_one_node_cannot_compute() -> None:
    xor = (Graph.make()
        .input('a', 'b')
        .gate('or', 'or', 'a', 'b')
        .gate('nand', 'nand', 'a', 'b')
        .gate('xor', 'and', 'or', 'nand'))
    answers = [xor.run(Simulator(), {'a': a, 'b': b}).yes('xor') for a, b in [(0, 0), (0, 1), (1, 0), (1, 1)]]

    assert answers == [False, True, True, False]


def test_adds() -> None:
    assert presets.adder(8).run(Simulator(), presets.sums(8, [(100, 55)])[0]).number('s') == 155


@pytest.mark.parametrize(('bits', 'name'), [(4, '4-bit adder'), (8, '8-bit adder')])
def test_builds_the_adders_in_data_presets(bits: int, name: str) -> None:
    assert presets.adder(bits).spec() == PRESETS[name]['graph']


def test_holds_every_preset_to_its_spec() -> None:
    for name in presets.names():
        assert presets.get(name).spec() == PRESETS[name]['graph'], name


@pytest.mark.parametrize(('build', 'message'), [
    (lambda: Graph.make().input('a').gate('b', 'nor', 'a'), 'two or more signals'),
    (lambda: Graph.make().input('a', 'a'), 'used twice'),
    (lambda: Graph.make().input('A1'), 'lowercase letter'),
    (lambda: Graph.make().input('a').gate('b', 'buffer', 'c').plan(), 'which is not an input or a node'),
    (lambda: presets.get('nope'), 'is not a preset'),
    (lambda: presets.get('decay').run(Simulator()), 'only Jev can answer it'),
])
def test_refuses_what_it_cannot_build(build, message: str) -> None:
    with pytest.raises(ValueError, match=message):
        build()


def test_keeps_apart_states_that_call_different_signals_by_one_name() -> None:
    node = gates.gate('buffer', ['a'])
    items = [('x', node, {'a': 1, 'b': 0}), ('y', node, {'a': 0, 'b': 1}), ('z', node, {'a': 1, 'c': 1})]
    assert [[i[0] for i in b] for b in batches(items)] == [['x', 'z'], ['y']]


def test_finds_loops() -> None:
    assert Plan({'q': ['r', 'qbar'], 'qbar': ['s', 'q']}).looping == ['q', 'qbar']


def test_sends_on_off_inputs_as_floats_and_whole_seconds_as_whole_numbers() -> None:
    fake = FakeTypeSafe.answering(0.1)
    graph = presets.get('delay timer').input('a').gate('b', 'buffer', 'a')
    graph.run(fake.jev(batch=False), {'a': 1}, time=32.0)
    raw = '\n'.join(r['raw'] for r in fake.requests)

    assert '"a":1.0' in raw
    assert re.search(r'"time":32[,}]', raw)
    assert '32.0' not in raw


def test_asks_a_batched_adder_one_request_a_level_for_the_same_sum() -> None:
    adder = presets.adder(4)
    inputs = presets.sums(4, [(7, 5)])[0]
    apart, together = FakeTypeSafe.for_fixture(adder), FakeTypeSafe.for_fixture(adder)
    one = adder.run(apart.jev(batch=False), inputs)
    many = adder.run(together.jev(), inputs)

    assert many.signals == one.signals
    assert many.number('s') == 12
    assert (len(apart.requests), len(together.requests)) == (8, 4)


def test_caches_an_answer_and_counts_what_it_spent(tmp_path: Path) -> None:
    fake = FakeTypeSafe.answering(0.9)
    jev = fake.jev(cache_dir=tmp_path)
    node = gates.gate('buffer', ['a'])

    assert jev.fire(node, {'a': 0.7, 'unused': 1.0}) == 0.9
    assert jev.fire(node, {'a': 0.7}) == 0.9
    assert (jev.calls, jev.cached, jev.tokens) == (1, 1, 380)
    assert fake.requests[0]['body']['state'] == {'a': 0.7}


def test_takes_a_context_as_text_or_a_json_object_from_make_or_context() -> None:
    fake = FakeTypeSafe.answering(0.9)
    ticket = {'ticket': 'Refund me', 'customer': {'plan': 'enterprise', 'seats': 400}}
    Graph.make(ticket).ask('refund', 'Is the customer asking for money back?', Graph.CONTEXT).run(fake.jev())

    assert fake.requests[0]['body']['state'] == {'context': ticket}
    assert Graph.make('one').context('two').contexts() == ['one', 'two']
    assert Graph.make().context({}).contexts() == []


def test_takes_text_as_another_name_for_the_context() -> None:
    graph = Graph.make().text('one').ask('q', 'Is it?', 'text')
    node = {'name': 'q', 'preset': 'custom', 'reads': ['text'], 'instructions': 'Is it?'}
    old = Graph.from_spec({'inputs': [], 'texts': ['one'], 'layers': [[node]]})

    assert Graph.TEXT == Graph.CONTEXT
    assert graph.nodes()['q'].reads == ('context',)
    assert graph.texts() == ['one']
    assert old.spec() == graph.spec()
    with pytest.raises(ValueError, match='used twice'):
        Graph.make().input('text')
