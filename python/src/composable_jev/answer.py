from __future__ import annotations

from dataclasses import dataclass, field
from typing import Union


@dataclass(frozen=True)
class Answer:
    """
    A choice's or a score's answer: the value a later question reads, and the probabilities code
    reads as the node's parts. A choice's value is the option Jev picked, a score's the score Jev
    gave; the parts are keyed by option, or by level from "0", in the order the node lists them.
    """

    value: Union[str, float]
    parts: dict[str, float] = field(default_factory=dict)
