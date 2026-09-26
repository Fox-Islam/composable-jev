from __future__ import annotations

from typing import TYPE_CHECKING, Optional

from .row import Row
from .simulation import OnFire

if TYPE_CHECKING:
    from .graph import Graph
    from .oracle import Oracle


class Ticker:
    """
    Steps a graph one tick at a time, each settling from where the last ended. This is where a
    latch shows it remembers, and a node reading itself runs down.

        latch = presets.get('sr latch').ticker(jev)
        latch.tick({'s': 1})
        latch.tick({'s': 1})    # a loop goes round once a tick, so setting takes two
        latch.tick().yes('q')   # True: it holds
    """

    def __init__(self, graph: Graph, oracle: Oracle, every: float = 1.0) -> None:
        self.rows: list[Row] = []
        self._network = graph
        self._oracle = oracle
        self._every = every

    def tick(self, inputs: Optional[dict[str, float]] = None, context: int = 0, on_fire: Optional[OnFire] = None) -> Row:
        last = self.rows[-1] if self.rows else None
        values = self._network.values(inputs or {}, context, len(self.rows) * self._every)
        row = self._network.tick(
            self._oracle, values,
            last.state() if last else None, last.ideal_state() if last else None, on_fire,
        )
        self.rows.append(row)

        return row
