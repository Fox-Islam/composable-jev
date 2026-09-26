from __future__ import annotations

from ..numbers import State
from ..simulation import same
from .oracle import Item


def batches(items: list[Item]) -> list[list[Item]]:
    """
    Splits nodes into batches whose states agree wherever they share a key, so one state holds a
    batch. Nodes that call different signals by the same name go in different batches.
    """
    found: list[tuple[State, list[Item]]] = []
    for item in items:
        for merged, batch in found:
            if all(k not in merged or same(merged[k], v) for k, v in item[2].items()):
                merged.update({k: v for k, v in item[2].items() if k not in merged})
                batch.append(item)
                break
        else:
            found.append((dict(item[2]), [item]))

    return [batch for _merged, batch in found]
