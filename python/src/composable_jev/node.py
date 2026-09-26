from __future__ import annotations

from collections.abc import Callable
from dataclasses import dataclass, field, replace
from typing import Any, Optional, Union

from .numbers import State

Margin = Callable[[State], float]

# a node's value worked out in code, from the signals it reads
Compute = Callable[[State], float]


@dataclass(frozen=True)
class Node:
    """
    One question about some signals. A noul's value is the probability Jev gives for yes; a
    choice's is the option Jev picks and a score's the score it gives, with the probability of each
    option or level as the node's parts, read as `name.option` or `name.2`. Its state is keyed by
    the names of the signals it reads.

    The margin is the rule as code, positive where the right answer is yes. Jev never sees it: it
    is what a run is checked against and what the simulator answers from. A question written by
    hand - custom, choice or score - has none.

    A node with `compute` is worked out in code and never asked: its value is what `compute`
    returns, and its instructions describe what that is.
    """

    name: str
    # a gate from data/gates.json, a rule from data/rules.json, `rule`, `weighted`, `custom`, `choice` or `score`
    preset: str
    reads: tuple[str, ...]
    instructions: str
    yes: str = ''
    no: str = ''
    margin: Optional[Margin] = field(default=None, compare=False)
    # one per signal read, for a weighted sum or average
    weights: tuple[float, ...] = ()
    bias: float = 0.0
    compute: Optional[Compute] = field(default=None, compare=False)
    # how many must be over 0.5, for `atLeast`
    n: int = 0
    # `noul`, `choice` or `score`
    type: str = 'noul'
    # a choice's options, option => what it means, or a score's levels, lowest first
    options: Union[dict[str, str], tuple[str, ...]] = field(default=(), compare=False)

    def named(self, name: str) -> Node:
        return replace(self, name=name)

    def question(self) -> dict[str, Any]:
        """The question as System One is sent it."""
        if self.type == 'choice':
            return {'type': 'choice', 'instructions': self.instructions, 'criteria': dict(self.options)}
        if self.type == 'score':
            return {'type': 'score', 'instructions': self.instructions, 'criteria': list(self.options)}

        return {'type': 'noul', 'instructions': self.instructions, 'criteria': {'true': self.yes, 'false': self.no}}

    def parts(self) -> list[str]:
        """The names of a choice's or a score's parts, `name.option` or `name.0`, in order."""
        if self.type == 'choice':
            return [f'{self.name}.{o}' for o in self.options]
        if self.type == 'score':
            return [f'{self.name}.{i}' for i in range(len(self.options))]

        return []

    def expected(self, state: State) -> Optional[bool]:
        """The right answer, or None where the input sits on the boundary or there is no rule."""
        if self.margin is None:
            return None
        margin = self.margin(state)

        return None if margin == 0 else margin > 0

    def spec(self) -> dict[str, Any]:
        """The node as the page edits it."""
        base: dict[str, Any] = {'name': self.name, 'preset': self.preset, 'reads': list(self.reads)}
        if self.preset == 'custom':
            return {**base, 'instructions': self.instructions, 'yes': self.yes, 'no': self.no}
        if self.preset == 'weighted':
            return {**base, 'weights': list(self.weights), 'bias': self.bias}
        if self.preset == 'choice':
            return {**base, 'instructions': self.instructions, 'options': dict(self.options)}
        if self.preset == 'score':
            return {**base, 'instructions': self.instructions, 'levels': list(self.options)}
        if self.preset == 'average':
            return {**base, 'weights': list(self.weights)}
        if self.preset == 'atLeast':
            return {**base, 'n': self.n}
        if self.preset == 'rule':
            raise TypeError(f'{self.name} is worked out by a function, which a spec cannot hold')

        return base

    def describe(self) -> dict[str, Any]:
        return {
            'name': self.name, 'inputs': list(self.reads), 'instructions': self.instructions,
            'yes': self.yes, 'no': self.no, 'rule': self.margin is not None, 'code': self.compute is not None,
        }
