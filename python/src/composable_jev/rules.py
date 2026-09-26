"""
Nodes worked out in code: no call, no tokens, no wait, and exact. Asking Jev an and between two
answers takes a request a level; for ticket triage that is 3 requests against 1, 746 ms against
268 ms and 1,223 tokens against 502, with the same decision on every ticket.

Each returns a probability, so a later question sees how sure the answers were, and each is
over 0.5 exactly where its logic is true: the lowest of some answers is over 0.5 only where all of
them are.
"""
from __future__ import annotations

import re
from collections.abc import Callable, Sequence
from typing import Any

from .data import data
from .gates import render
from .node import Compute, Node
from .numbers import State, text


def templates() -> dict[str, dict[str, Any]]:
    """data/rules.json: each rule's name on the page and what it works out."""
    return data('rules.json')


def is_rule(preset: str) -> bool:
    return preset in templates() or preset == 'rule'


def all_of(name: str, reads: Sequence[str]) -> Node:
    return _node(name, 'all', reads, min)


def any_of(name: str, reads: Sequence[str]) -> Node:
    return _node(name, 'any', reads, max)


def none_of(name: str, reads: Sequence[str]) -> Node:
    """Not, over one signal; nor, over more."""
    return _node(name, 'none', reads, lambda v: 1.0 - max(v))


def sum_of(name: str, reads: Sequence[str]) -> Node:
    """
    The chance one of them is true, where no two can be, as a choice's options or a score's levels
    cannot: `sum_of('serious', ['severity.3', 'severity.4'])`. Held to 1 at most.
    """
    return _node(name, 'sum', reads, lambda v: min(1.0, sum(v)))


def at_least(name: str, n: int, reads: Sequence[str]) -> Node:
    """The value `n` down from the highest: over 0.5 where at least `n` of them are."""
    if n < 1 or n > len(reads):
        raise ValueError(f'{name}: at least 1, and no more than the {len(reads)} signals it reads')

    return _node(name, 'atLeast', reads, lambda v: sorted(v, reverse=True)[n - 1], n=n)


def average(name: str, weights: dict[str, float]) -> Node:
    """`weights` is signal => weight, none below 0 and not all 0."""
    w = [float(x) for x in weights.values()]
    if not w or min(w) < 0 or sum(w) <= 0:
        raise ValueError(f'{name}: a weight for each signal, none below 0 and not all 0')
    total = sum(w)

    return _node(name, 'average', list(weights), lambda v: sum(x * y for x, y in zip(v, w, strict=True)) / total, w)


def rule(name: str, compute: Compute, reads: Sequence[str]) -> Node:
    """
    A function of your own over the signals read, returning 0 to 1. It has no spec, so the page and
    data/presets.json cannot hold it.
    """
    return _built(name, 'rule', reads, f"Worked out by a function of {name}'s own", lambda s: float(compute(s)), [], 0)


def describe(preset: str, reads: Sequence[str], weights: Sequence[float] = (), n: int = 0) -> str:
    words = {'n': str(n), 'weights': ', '.join(text(w) for w in weights)}

    return re.sub(r'\{(n|weights)\}', lambda m: words[m.group(1)], render(templates()[preset]['describe'], reads))


def _node(
    name: str, preset: str, reads: Sequence[str], of: Callable[[list[float]], float], weights: Sequence[float] = (), n: int = 0,
) -> Node:
    _reading(name, reads)
    read = tuple(reads)

    def compute(state: State) -> float:
        return of([float(state[r] or 0) for r in read])

    return _built(name, preset, read, describe(preset, read, weights, n), compute, weights, n)


def _built(
    name: str, preset: str, reads: Sequence[str], text_of: str, compute: Compute, weights: Sequence[float], n: int,
) -> Node:
    _reading(name, reads)

    margin = lambda s: compute(s) - 0.5  # noqa: E731

    return Node(name, preset, tuple(reads), text_of, '', '', margin, tuple(float(w) for w in weights), 0.0, compute, n)


def _reading(name: str, reads: Sequence[str]) -> None:
    if not reads:
        raise ValueError(f'{name}: read at least one signal')
