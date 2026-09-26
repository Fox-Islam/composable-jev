from __future__ import annotations

import pytest
from fake_typesafe import FakeTypeSafe

from composable_jev import Graph, Simulator, presets, rules


def test_works_out_each_rule_as_a_probability_that_is_over_half_where_its_logic_is_true() -> None:
    state = {'a': 0.9, 'b': 0.3, 'c': 0.6}

    def value(node) -> float:
        return round(node.compute(state), 6)

    assert value(rules.all_of('x', ['a', 'b', 'c'])) == 0.3
    assert value(rules.any_of('x', ['a', 'b', 'c'])) == 0.9
    assert value(rules.none_of('x', ['b'])) == 0.7
    assert value(rules.at_least('x', 2, ['a', 'b', 'c'])) == 0.6
    assert value(rules.average('x', {'a': 3, 'b': 1})) == 0.75


def test_never_sends_a_rule_to_jev() -> None:
    graph = presets.get('ticket triage')
    fake = FakeTypeSafe.for_fixture(graph)
    row = graph.run(fake.jev(), context=1)

    assert len(fake.requests) == 1
    assert sorted(fake.requests[0]['body']['questions']) == ['angry', 'enterprise', 'refund']
    assert row.value('escalate') == min(row.value('angry'), max(row.value('refund'), row.value('enterprise')))


def test_runs_a_function_of_your_own_which_a_spec_cannot_hold() -> None:
    graph = Graph.make().input('a', 'b').rule('both', lambda s: s['a'] * s['b'], 'a', 'b').none('neither', 'a', 'b')

    assert graph.run(Simulator(), {'a': 1, 'b': 1}).value('both') == 1.0
    assert graph.run(Simulator(), {'a': 0, 'b': 0}).value('neither') == 1.0
    with pytest.raises(TypeError, match='which a spec cannot hold'):
        graph.spec()


@pytest.mark.parametrize(('build', 'message'), [
    (lambda: rules.at_least('x', 3, ['a', 'b']), 'no more than the 2 signals'),
    (lambda: rules.average('x', {'a': -1, 'b': 2}), 'none below 0'),
    (lambda: rules.all_of('x', []), 'read at least one signal'),
    (
        lambda: Graph.from_spec({
            'inputs': [], 'texts': ['hi'], 'layers': [[{'name': 'x', 'preset': 'all', 'reads': ['text']}]],
        }),
        'works on numbers',
    ),
])
def test_refuses_a_rule_it_cannot_work_out(build, message: str) -> None:
    with pytest.raises(ValueError, match=message):
        build()
