"""
The questions a node asks: logic gates, a weighted sum, and questions written by hand - a noul, a
choice or a score. Every gate thresholds at 0.5 and states which side 0.5 itself falls on, so the
boundary is defined in the question. The wording is a template over the names of the signals
the gate reads: {x} is the one signal, {list} is them all ("a and b", "a, b and c"), {both} is
"both" or "all".
"""
from __future__ import annotations

import re
from collections.abc import Sequence
from typing import Any, Optional

from .data import data
from .node import Node
from .numbers import State, text


def templates() -> dict[str, dict[str, Any]]:
    """data/gates.json. Arity 1 reads exactly one signal; arity 2 reads two or more."""
    return data('gates.json')


def gate(name_of_gate: str, reads: Sequence[str], name: Optional[str] = None) -> Node:
    template = templates().get(name_of_gate)
    if template is None:
        raise ValueError(f'{name_of_gate} is not a gate')
    if not reads or (template['arity'] == 1) != (len(reads) == 1):
        raise ValueError(f"{name_of_gate} reads {'one signal' if template['arity'] == 1 else 'two or more signals'}")
    read = tuple(reads)

    return Node(
        name or name_of_gate,
        name_of_gate,
        read,
        render(template['instructions'], read),
        render(template['yes'], read),
        render(template['no'], read),
        lambda state: _margin(name_of_gate, [float(state[r] or 0) for r in read]),
    )


def built_in() -> dict[str, Node]:
    """Each gate over `a`, or `a` and `b`: the Gates tab's single node."""
    return {name: gate(name, ['a'] if t['arity'] == 1 else ['a', 'b']) for name, t in templates().items()}


def weighted(weights: Sequence[float], bias: float, name: str = 'weighted', inputs: Optional[Sequence[str]] = None) -> Node:
    """
    A classic perceptron: yes where the weighted sum of the inputs exceeds the bias. It asks Jev to
    do the sum, which is the arithmetic its documentation names as a weakness.
    """
    reads = tuple(inputs) if inputs is not None else tuple(chr(97 + i) for i in range(len(weights)))
    if not weights or len(reads) != len(weights):
        raise ValueError(f'{name}: one weight per signal read')
    w = tuple(float(x) for x in weights)
    total = ' + '.join(f'{text(x)} × {r}' for x, r in zip(w, reads, strict=True))
    b = text(bias)

    def margin(state: State) -> float:
        return sum(x * float(state[r] or 0) for x, r in zip(w, reads, strict=True)) - bias

    return Node(
        name, 'weighted', reads,
        f'Is {total} greater than {b}?', f'{total} is greater than {b}.', f'{total} is {b} or less.',
        margin, w, float(bias),
    )


def custom(name: str, question: str, reads: Sequence[str], yes: str = '', no: str = '') -> Node:
    """A question written by hand, over the signals it names. It has no rule."""
    if question.strip() == '':
        raise ValueError(f'{name}: write a question')

    return Node(name, 'custom', tuple(reads), question.strip(), yes.strip(), no.strip())


def choice(name: str, question: str, reads: Sequence[str], options: dict[str, str]) -> Node:
    """A question Jev answers by picking one of the options, option => what it means."""
    if question.strip() == '':
        raise ValueError(f'{name}: write a question')
    if len(options) < 2 or any(not isinstance(o, str) or o.strip() == '' or '.' in o for o in options):
        raise ValueError(f'{name}: two or more options, each named, with no . in the name')

    return Node(name, 'choice', tuple(reads), question.strip(), type='choice', options={o: str(d) for o, d in options.items()})


def score(name: str, question: str, reads: Sequence[str], levels: Sequence[str]) -> Node:
    """A question Jev answers with a score, from 0 for the first level up; the levels lowest first."""
    if question.strip() == '':
        raise ValueError(f'{name}: write a question')
    if isinstance(levels, (str, dict)) or len(levels) < 2:
        raise ValueError(f'{name}: two or more levels, lowest first')

    return Node(name, 'score', tuple(reads), question.strip(), type='score', options=tuple(str(level) for level in levels))


def render(template: str, reads: Sequence[str]) -> str:
    listed = reads[0] if len(reads) == 1 else ', '.join(reads[:-1]) + ' and ' + reads[-1]
    words = {'x': reads[0], 'list': listed, 'both': 'both' if len(reads) == 2 else 'all'}

    return re.sub(r'\{(x|list|both)\}', lambda m: words[m.group(1)], template)


def _margin(name: str, v: list[float]) -> float:
    high = sum(1 for x in v if x > 0.5)
    rules = {
        'buffer': lambda: v[0] - 0.5,
        'not': lambda: 0.5 - v[0],
        'and': lambda: min(v) - 0.5,
        'or': lambda: max(v) - 0.5,
        'nand': lambda: 0.5 - min(v),
        'nor': lambda: 0.5 - max(v),
        'xor': lambda: 0.5 if high % 2 == 1 else -0.5,
    }

    return rules.get(name, lambda: high - len(v) / 2 - 0.25)()
