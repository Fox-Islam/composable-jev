from __future__ import annotations

from collections.abc import Sequence


class Plan:
    """
    The order a graph's nodes are asked in: levels of strongly connected components. A component
    with no loop is one node, asked once what it reads is final; a component with a loop is asked
    a node at a time. Kosaraju's passes put the components in the order they depend on each other.

    A node reading another's part, `team.billing`, depends on that node.
    """

    def __init__(self, reads: dict[str, Sequence[str]]) -> None:
        # node => the signals it reads
        self.reads = {node: list(signals) for node, signals in reads.items()}
        # signal => the nodes that read it
        self.readers: dict[str, list[str]] = {}
        for node, signals in self.reads.items():
            for signal in dict.fromkeys(signals):
                self.readers.setdefault(signal, []).append(node)
        self.levels = self._levels()
        # the nodes that can reach themselves
        self.looping = [n for level in self.levels for c in level if self.looped(c) for n in c]

    def looped(self, component: Sequence[str]) -> bool:
        return len(component) > 1 or component[0] in self.sources(component[0])

    def sources(self, node: str) -> list[str]:
        """The nodes a node reads, or reads a part of, in the order it reads them."""
        return [b for b in dict.fromkeys(s.split('.', 1)[0] for s in self.reads[node]) if b in self.reads]

    def _levels(self) -> list[list[list[str]]]:
        order = list(self.reads)
        position = {n: i for i, n in enumerate(order)}

        def ordered(nodes: list[str]) -> list[str]:
            return sorted(nodes, key=position.__getitem__)

        backward = {n: self.sources(n) for n in order}
        forward: dict[str, list[str]] = {n: [] for n in order}
        for node in order:
            for source in backward[node]:
                forward[source].append(node)
        forward = {n: ordered(readers) for n, readers in forward.items()}

        return _grouped(_components(_finished(order, forward), backward, ordered), backward)


def _finished(order: list[str], forward: dict[str, list[str]]) -> list[str]:
    """Nodes in the order a depth-first pass over readers finishes them."""
    done: list[str] = []
    seen: set[str] = set()
    for root in order:
        if root in seen:
            continue
        seen.add(root)
        stack = [[root, 0]]
        while stack:
            top = stack[-1]
            node, following = top[0], top[1]
            children = forward[node]
            if following >= len(children):
                stack.pop()
                done.append(node)
                continue
            top[1] = following + 1
            child = children[following]
            if child not in seen:
                seen.add(child)
                stack.append([child, 0])

    return done


def _components(done: list[str], backward: dict[str, list[str]], ordered) -> list[list[str]]:
    """The components, walked back along what each node reads, in dependency order."""
    components: list[list[str]] = []
    assigned: set[str] = set()
    for root in reversed(done):
        if root in assigned:
            continue
        members: list[str] = []
        stack = [root]
        assigned.add(root)
        while stack:
            node = stack.pop()
            members.append(node)
            for source in backward[node]:
                if source not in assigned:
                    assigned.add(source)
                    stack.append(source)
        components.append(ordered(members))

    return components


def _grouped(components: list[list[str]], backward: dict[str, list[str]]) -> list[list[list[str]]]:
    of = {node: i for i, members in enumerate(components) for node in members}
    level: list[int] = []
    groups: dict[int, list[list[str]]] = {}
    for i, members in enumerate(components):
        level.append(0)
        for node in members:
            for source in backward[node]:
                j = of[source]
                level[i] = level[i] if j == i else max(level[i], level[j] + 1)
        groups.setdefault(level[i], []).append(members)

    return [groups[k] for k in sorted(groups)]
