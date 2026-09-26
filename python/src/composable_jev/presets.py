"""
The graphs in data/presets.json, each with what it shows and the inputs its first tick starts
from.
"""
from __future__ import annotations

from typing import Any

from .data import data
from .graph import Graph


def names() -> list[str]:
    return list(data('presets.json'))


def get(name: str) -> Graph:
    return Graph.from_spec(_entry(name)['graph'])


def about(name: str) -> str:
    return _entry(name)['about']


def starts(name: str) -> dict[str, int]:
    return dict(_entry(name).get('starts', {}))


def adder(bits: int) -> Graph:
    """
    A ripple-carry adder of a and b into s, least significant bit first, of any width. Each bit is
    two nodes: the sum is xor of a, b and the carry in, and the carry out is their majority, the
    last carry being the top bit of the sum. The sum row is drawn the other way from the carries,
    so it reads most significant bit first.
    """
    graph = Graph.make()
    graph.input(*[f'a{i}' for i in range(bits)])
    graph.input(*[f'b{i}' for i in range(bits)])
    carries = []
    for i in range(bits):
        carry = f'c{i + 1}' if i < bits - 1 else f's{bits}'
        reads = ['a0', 'b0'] if i == 0 else [f'a{i}', f'b{i}', f'c{i}']
        graph.gate(f's{i}', 'xor', *reads).gate(carry, 'and' if i == 0 else 'majority', *reads)
        carries.append(carry)

    return graph.layout(*[[carry, f's{bits - 1 - i}'] for i, carry in enumerate(carries)])


def sums(bits: int, pairs: list[tuple[int, int]]) -> list[dict[str, int]]:
    """Each adder input set to a pair of numbers."""
    return [
        {k: v for i in range(bits) for k, v in ((f'a{i}', a >> i & 1), (f'b{i}', b >> i & 1))}
        for a, b in pairs
    ]


def _entry(name: str) -> dict[str, Any]:
    found = data('presets.json').get(name)
    if found is None:
        raise ValueError(f"{name} is not a preset; the presets are {', '.join(names())}")

    return found
