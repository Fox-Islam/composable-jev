from __future__ import annotations

import math

from ..node import Node
from ..numbers import State
from .oracle import Item


class Simulator:
    """
    A sigmoid of each node's own rule, for tests and for trying a graph before paying for it. A
    node with no rule is left to Jev.
    """

    def __init__(self, sharpness: float = 20.0) -> None:
        self.calls = 0
        self._sharpness = sharpness

    def fire(self, node: Node, state: State) -> float:
        if node.margin is None:
            raise ValueError(f'{node.name} has no rule, so only Jev can answer it')
        self.calls += 1

        return 1 / (1 + math.exp(-self._sharpness * node.margin(state)))

    def fire_many(self, items: list[Item]) -> dict[str, float]:
        return {key: self.fire(node, state) for key, node, state in items}

    def batching(self) -> bool:
        return False
