"""Numbers as Jev is shown them, the same in every implementation."""
from __future__ import annotations

import json
import math
from typing import Any, Union

# What the questions are about, as System One's `state` is: text, or a JSON object or array
Context = Union[str, dict[str, Any], list[Any]]

# A value a signal holds: an answer, an input, the context, or None where a rule has no answer
Value = Union[float, int, str, dict[str, Any], list[Any], None]

State = dict[str, Value]


def is_number(value: object) -> bool:
    return isinstance(value, (int, float)) and not isinstance(value, bool)


def round_half_up(value: float, places: int) -> float:
    """
    Half rounds up. Written out, since PHP's round() pre-rounds, Python's rounds half to even and
    JavaScript has no decimal rounding: 32.25 to one place is 32.3 in all three this way.
    """
    scale = 10**places

    return math.floor(value * scale + 0.5) / scale


def seconds(value: float) -> float | int:
    """
    Whole seconds as an int, as Jev is shown them: sent as 32.0, Jev says it ends in the digit 0
    and misses multiples of 10 it gets right as 32.
    """
    tenths = round_half_up(float(value), 1)

    return int(tenths) if tenths == math.floor(tenths) else tenths


def text(n: float) -> str:
    """A number as a question shows it: 1 and not 1.0, which Jev reads literally."""
    return str(int(n)) if float(n).is_integer() else repr(float(n))


def canonical(value: Any) -> str:
    """
    A body as its cache key hashes it and as tests compare it across implementations: keys sorted,
    no whitespace, a float keeping its decimal, slashes and Unicode unescaped.
    """
    return json.dumps(value, sort_keys=True, separators=(',', ':'), ensure_ascii=False)
