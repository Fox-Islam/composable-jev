from __future__ import annotations

from typing import Protocol, Union

from ..answer import Answer
from ..node import Node
from ..numbers import State

# a node to ask, keyed as its answer comes back, with the signals it reads
Item = tuple[str, Node, State]


class Oracle(Protocol):
    """What answers a node's question: Jev, or the simulator."""

    def fire(self, node: Node, state: State) -> Union[float, Answer]:
        """A noul's probability, or a choice's or a score's answer."""
        ...

    def fire_many(self, items: list[Item]) -> dict[str, Union[float, Answer]]:
        """Several nodes at once, keyed as given."""
        ...

    def batching(self) -> bool:
        """Whether a level's nodes should go to fire_many in one call instead of fire each."""
        ...
