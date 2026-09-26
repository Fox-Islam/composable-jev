from __future__ import annotations

from dataclasses import dataclass
from typing import Any, Optional

from .numbers import State


@dataclass(frozen=True)
class Row:
    """
    What one step gave: the values it ran with, each node's answer, and each node's right answer
    where it has a rule.
    """

    inputs: State
    # node => Jev's answer, and a choice's or a score's parts
    signals: State
    # node => 1.0, 0.0, or None with no rule
    ideal: dict[str, Optional[float]]
    output: str
    # nodes in a loop that had not settled
    unsettled: list[str]
    fires: int

    def value(self, node: str) -> float:
        return float(self.signals[node])  # type: ignore[arg-type]

    def yes(self, node: str) -> bool:
        return self.value(node) > 0.5

    def choice(self, node: str) -> str:
        """The option a choice picked."""
        return str(self.signals[node])

    def probabilities(self, node: str) -> dict[str, float]:
        """Each option's or level's probability, from a choice's or a score's parts."""
        return {s[len(node) + 1:]: float(v) for s, v in self.signals.items() if s.startswith(f'{node}.')}  # type: ignore[arg-type]

    def number(self, prefix: str) -> int:
        """The number held in bits named `{prefix}0`, `{prefix}1`…, least significant first."""
        total = 0
        i = 0
        while f'{prefix}{i}' in self.signals:
            total += 1 << i if self.yes(f'{prefix}{i}') else 0
            i += 1

        return total

    def state(self) -> State:
        """Inputs and nodes, as the next step starts from them."""
        return {**self.signals, **self.inputs}

    def ideal_state(self) -> State:
        return {**self.ideal, **self.inputs}

    def to_dict(self) -> dict[str, Any]:
        want = self.ideal.get(self.output)

        return {
            'inputs': self.inputs, 'signals': self.signals, 'ideal': self.ideal,
            'p': self.signals.get(self.output), 'want': None if want is None else want > 0.5,
            'unsettled': self.unsettled, 'fires': self.fires,
        }
