from __future__ import annotations

import json
from pathlib import Path

import pytest
from fake_typesafe import FakeTypeSafe

from composable_jev import Answer, Graph, Simulator
from composable_jev.oracle.jev import _read

ROUTING = json.loads((Path(__file__).resolve().parents[2] / 'spec' / 'routing.json').read_text(encoding='utf-8'))


def test_asks_a_choice_and_a_score_with_the_questions_of_their_level_and_reads_their_parts() -> None:
    graph = Graph.from_spec(ROUTING)
    fake = FakeTypeSafe.for_fixture(graph)
    row = graph.run(fake.jev(), context=1)
    first = fake.requests[0]['body']['questions']

    assert len(fake.requests) == 2
    assert [first[k]['type'] for k in ('team', 'severity', 'angry')] == ['choice', 'score', 'noul']
    assert row.choice('team') == 'billing'
    assert row.probabilities('team') == {'billing': 0.7, 'bugs': 0.1, 'sales': 0.1, 'other': 0.1}
    assert row.value('serious') == 0.075 + 0.7
    assert fake.requests[1]['body']['state'] == {'serious': 0.775, 'team': 'billing', 'context': graph.contexts()[1]}


def test_puts_the_probabilities_typesafe_sends_in_any_order_into_the_order_of_the_options() -> None:
    graph = Graph.make('Refund me').choose('team', 'Which team?', Graph.CONTEXT, {'billing': 'Money', 'bugs': 'Broken'})
    node = graph.nodes()['team']
    answer = _read(node, {'type': 'choice', 'choice': 'billing', 'probabilities': {'bugs': 0.2, 'billing': 0.8}}, 'team')

    assert answer == Answer('billing', {'billing': 0.8, 'bugs': 0.2})
    assert list(answer.parts) == ['billing', 'bugs']


def test_refuses_a_rule_reading_the_option_a_choice_picked() -> None:
    with pytest.raises(ValueError, match=r"read one option's probability, such as pick\.one"):
        Graph.make('hi').choose('pick', 'Which?', Graph.CONTEXT, {'one': 'A', 'two': 'B'}).all('x', 'pick').plan()


def test_refuses_what_the_simulator_cannot_answer_or_cannot_be_built() -> None:
    with pytest.raises(ValueError, match='only Jev can answer it'):
        Graph.make().input('a').score('s', 'How much?', 'a', ['None', 'All']).run(Simulator())
    with pytest.raises(ValueError, match='two or more options'):
        Graph.make().input('a').choose('p', 'Which?', 'a', {'only': 'One'})
    with pytest.raises(ValueError, match='two or more levels'):
        Graph.make().input('a').score('p', 'How much?', 'a', ['One'])
