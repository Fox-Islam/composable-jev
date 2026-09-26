"""
TypeSafe's System One, answered by a function over each question's state, and every request
kept with the body as it went over the wire.
"""
from __future__ import annotations

import json
from collections.abc import Callable
from typing import Any

import httpx2

from composable_jev import Graph, Jev
from composable_jev.numbers import State, is_number, round_half_up

Answer = Callable[[State, dict[str, Any]], float]


def drifting(state: State) -> float:
    """0.2 plus 0.6 times the mean of the state's numbers, each held to 0 to 1, or 0.7 for a state with none."""
    numbers = [max(0.0, min(1.0, float(v))) for v in state.values() if is_number(v)]  # type: ignore[arg-type]

    return 0.7 if not numbers else round_half_up(0.2 + 0.6 * sum(numbers) / len(numbers), 3)


def answer(question: dict[str, Any], p: float) -> dict[str, Any]:
    """
    A noul answered `p`; a choice or a score with `p` on its first option or its top level and the
    rest shared among the others, picking the likeliest option, first where two tie, and scoring
    the expected level to two places.
    """
    if question['type'] == 'noul':
        return {'type': 'noul', 'noul': p}
    options = list(question['criteria']) if question['type'] == 'choice' else [str(i) for i in range(len(question['criteria']))]
    favoured = 0 if question['type'] == 'choice' else len(options) - 1
    rest = round_half_up((1 - p) / (len(options) - 1), 3)
    probabilities = {o: p if i == favoured else rest for i, o in enumerate(options)}
    if question['type'] == 'choice':
        top = max(probabilities.values())

        picked = next(o for o in options if probabilities[o] == top)

        return {'type': 'choice', 'choice': picked, 'confidence': top, 'probabilities': probabilities}
    expected = 0.0
    for level, chance in enumerate(probabilities.values()):
        expected += level * chance
    legend = dict(zip(options, question['criteria'], strict=True))

    return {'type': 'score', 'score': round_half_up(expected, 2), 'confidence': p, 'legend': legend,
            'probabilities': probabilities}


class FakeTypeSafe:
    def __init__(self, answer: Answer) -> None:
        self.requests: list[dict[str, Any]] = []
        self._answer = answer

    @staticmethod
    def answering(p: float) -> FakeTypeSafe:
        return FakeTypeSafe(lambda _state, _question: p)

    @staticmethod
    def for_fixture(graph: Graph) -> FakeTypeSafe:
        """
        As spec/fixtures.json is answered in every implementation: 0.99 or 0.01 by the node's rule,
        and for a question with none an answer that follows its numbers, so a loop drifts and
        settles as a leak does.
        """
        nodes = {n.instructions: n for n in graph.nodes().values()}

        def answer(state: State, question: dict[str, Any]) -> float:
            node = nodes[question['instructions']]
            own = {r: state[r] for r in node.reads}
            expected = node.expected(own)

            return drifting(own) if expected is None else 0.99 if expected else 0.01

        return FakeTypeSafe(answer)

    def _handle(self, request: httpx2.Request) -> httpx2.Response:
        raw = request.content.decode()
        body = json.loads(raw)
        self.requests.append({'raw': raw, 'body': body})
        answers = {key: answer(q, self._answer(body['state'], q)) for key, q in body['questions'].items()}

        return httpx2.Response(200, json={
            'model': 'jev-test', 'answers': answers,
            'usage': {'input_tokens': 300 + 60 * len(answers), 'output_tokens': 20 * len(answers)},
        })

    def jev(self, **options: Any) -> Jev:
        return Jev('ts-key', transport=httpx2.MockTransport(self._handle), **options)
