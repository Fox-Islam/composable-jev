"""
Runs a graph for one step. No node is asked more than once a step, so a step is one trip round
a loop, the way a gate delay is in a circuit: a latch takes a step or two to settle, and a node
that reads itself moves once a step, which is what lets it run down over time.

A node with no loop is asked only when something it reads has changed, so a step that flips one
input re-asks only what that input reaches; a level's such nodes go to `fire_many` in one call. A
loop is asked every step, a node at a time in design order, each reading the latest values:
asking a loop all at once would set a latch oscillating.
"""
from __future__ import annotations

from collections.abc import Callable, Sequence
from dataclasses import dataclass
from typing import Optional, Union

from .answer import Answer
from .numbers import State, Value, is_number
from .plan import Plan

# An answer moving less than this is treated as unchanged, so Jev answering 0.98 then 0.99 does
# not re-ask everything that reads it.
CHANGE = 0.02

Fire = Callable[[str, State], Union[Value, Answer]]
FireMany = Callable[[list[tuple[str, State]]], dict[str, Union[Value, Answer]]]
# kind (`firing` or `fired`), node, value
OnFire = Callable[[str, str, Value], None]


@dataclass
class Settled:
    """Every signal once a step has settled, the loops that had not, and how many answers it took."""

    signals: State
    # loops with a node that crossed 0.5 in the step
    unsettled: list[list[str]]
    fires: int


def changed(old: Value, new: Value) -> bool:
    return abs(float(old) - float(new)) > CHANGE if is_number(old) and is_number(new) else old != new  # type: ignore[arg-type]


def flipped(old: Value, new: Value) -> bool:
    """Whether an answer crossed from one side of 0.5 to the other, or a choice picked another option."""
    if isinstance(old, str) and isinstance(new, str):
        return old != new

    return is_number(old) and is_number(new) and (old > 0.5) != (new > 0.5)  # type: ignore[operator]


def same(a: Value, b: Value) -> bool:
    return float(a) == float(b) if is_number(a) and is_number(b) else a == b  # type: ignore[arg-type]


class Simulation:
    def __init__(self, plan: Plan, fire: Fire, on_fire: Optional[OnFire] = None, fire_many: Optional[FireMany] = None) -> None:
        self._plan = plan
        self._fire = fire
        self._on_fire = on_fire
        self._fire_many = fire_many

    def fresh(self, values: State, start: dict[str, float]) -> Settled:
        """Every node from its starting value, then settled against `values`."""
        signals: State = {node: start.get(node, 0.0) for node in self._plan.reads}

        return self._settle({**signals, **values}, set(self._plan.reads))

    def step(self, previous: State, values: State) -> Settled:
        """
        The last step's signals with `values` changed, settled again. A node that reads itself, or
        sits in a loop, is asked every step.
        """
        signals = dict(previous)
        dirty = set(self._plan.looping)
        for key, value in values.items():
            if key not in signals or not same(signals[key], value):
                dirty.update(self._plan.readers.get(key, []))
            signals[key] = value

        return self._settle(signals, dirty)

    def _settle(self, signals: State, dirty: set[str]) -> Settled:
        unsettled: list[list[str]] = []
        fires = 0
        for level in self._plan.levels:
            single = [c[0] for c in level if not self._plan.looped(c) and c[0] in dirty]
            for node, value in self._ask_all(single, signals).items():
                self._record(node, value, signals, dirty)
            fires += len(single)
            for component in level:
                if self._plan.looped(component):
                    fires += len(component)
                    if self._round_loop(component, signals, dirty):
                        unsettled.append(component)

        return Settled(signals, unsettled, fires)

    def _round_loop(self, component: Sequence[str], signals: State, dirty: set[str]) -> bool:
        """
        Asks a loop once round, in order. True where a node with others in its loop crossed 0.5,
        as a latch's nodes do for a step after it is set: the loop has not settled. A loop that only
        drifts, as a leak does, and one node reading only itself are not reported.
        """
        moved = False
        for node in component:
            old = signals.get(node)
            self._record(node, self._ask(node, signals), signals, dirty)
            moved = moved or flipped(old, signals[node])

        return moved and len(component) > 1

    def _ask_all(self, nodes: list[str], signals: State) -> dict[str, Union[Value, Answer]]:
        if self._fire_many is None or len(nodes) < 2:
            return {node: self._ask(node, signals) for node in nodes}
        for node in nodes:
            self._notify('firing', node, None)
        given = self._fire_many([(n, self._state(n, signals)) for n in nodes])
        answers = {node: given[node] for node in nodes}
        for node in nodes:
            self._notify('fired', node, _shown_of(answers[node]))

        return answers

    def _ask(self, node: str, signals: State) -> Union[Value, Answer]:
        self._notify('firing', node, None)
        value = self._fire(node, self._state(node, signals))
        self._notify('fired', node, _shown_of(value))

        return value

    def _record(self, node: str, value: Union[Value, Answer], signals: State, dirty: set[str]) -> None:
        """
        Stores an answer, a choice's or a score's parts with it, and marks what reads each to be
        asked, if it moved.
        """
        dirty.discard(node)
        given: State = {node: value.value if isinstance(value, Answer) else value}
        if isinstance(value, Answer):
            given.update({f'{node}.{p}': v for p, v in value.parts.items()})
        for signal, new in given.items():
            old = signals.get(signal)
            signals[signal] = new
            if changed(old, new):
                dirty.update(r for r in self._plan.readers.get(signal, []) if r != node)

    def _state(self, node: str, signals: State) -> State:
        return {signal: signals.get(signal) for signal in self._plan.reads[node]}

    def _notify(self, kind: str, node: str, value: Value) -> None:
        if self._on_fire is not None:
            self._on_fire(kind, node, value)


def _shown_of(value: Union[Value, Answer]) -> Value:
    return value.value if isinstance(value, Answer) else value
