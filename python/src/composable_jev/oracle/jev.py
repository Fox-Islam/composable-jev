from __future__ import annotations

import hashlib
import json
import os
import secrets
from pathlib import Path
from typing import Any, Optional, Union

import httpx2
from typesafe_sdk import TypeSafeClient

from ..answer import Answer
from ..node import Node
from ..numbers import State, canonical
from .batches import batches
from .oracle import Item


class Jev:
    """
    Asks Jev through TypeSafe's System One. Answers are cached on disk by request body, so a rerun
    costs only what changed; the key is the same in every implementation, so they share a cache.

    Batching asks a level's nodes in one request, their inputs merged into one state: the fixed
    part of a call is paid once, not once a node. Over the adders, xor, half-adder and ticket
    triage, with every node asked of Jev, batched and separate answers fell on the same side of 0.5
    for 2,722 of 2,731 nodes, batched was right as often or more, and it used 26 to 39% fewer
    tokens in about half the requests.

    A float goes over the wire with its decimal and whole seconds without, as the graph hands
    them over: asked whether an odd number of (1, 1, 0.96) is above 0.5, Jev answered 0.50 to 0.58
    with the ones sent as `1` and 0.55 to 0.66 as `1.0`.
    """

    def __init__(
        self,
        api_key: Optional[str] = None,
        batch: bool = True,
        cache_dir: Optional[str | os.PathLike[str]] = None,
        model: str = 'jev-latest',
        base_url: Optional[str] = None,
        transport: Optional[httpx2.BaseTransport] = None,
    ) -> None:
        """`api_key` falls back to TYPESAFE_API_KEY; `transport` swaps the transport, for a test that calls nothing."""
        self.calls = 0
        self.cached = 0
        self.tokens = 0
        self._client = TypeSafeClient(api_key=api_key, model=model, base_url=base_url, transport=transport)
        self._batch = batch
        self._cache_dir = None if cache_dir is None else Path(cache_dir)
        self._model = model

    @staticmethod
    def make(api_key: Optional[str] = None, **options: Any) -> Jev:
        return Jev(api_key, **options)

    def fire(self, node: Node, state: State) -> Union[float, Answer]:
        return self._ask(_only(node, state), {'out': node.question()}, {'out': node})['out']

    def fire_many(self, items: list[Item]) -> dict[str, Union[float, Answer]]:
        answers: dict[str, Union[float, Answer]] = {}
        for batch in batches(items):
            if len(batch) == 1:
                key, node, state = batch[0]
                answers[key] = self.fire(node, state)
                continue
            merged: State = {}
            questions: dict[str, Any] = {}
            nodes: dict[str, Node] = {}
            for key, node, given in batch:
                nodes[key] = node
                for signal, value in _only(node, given).items():
                    merged.setdefault(signal, value)
                questions[key] = node.question()
            answers.update(self._ask(dict(sorted(merged.items())), questions, nodes))

        return answers

    def batching(self) -> bool:
        return self._batch

    def close(self) -> None:
        self._client.close()

    def _ask(self, state: State, questions: dict[str, Any], nodes: dict[str, Node]) -> dict[str, Union[float, Answer]]:
        """One request, or its cached answers."""
        body = {'model': self._model, 'state': state, 'questions': questions}
        digest = hashlib.sha256(canonical(body).encode()).hexdigest()
        path = None if self._cache_dir is None else self._cache_dir / f'{digest}.json'
        if path is not None and path.is_file():
            self.cached += 1
            raw = json.loads(path.read_text(encoding='utf-8'))['answers']
        else:
            result = self._client.system_one(state, questions, model=self._model)
            raw = {}
            for key in questions:
                answer = result.answers.get(key)
                if answer is None:
                    raise RuntimeError(f'TypeSafe gave no answer to {key}')
                raw[key] = answer.model_dump(mode='json')
            self.calls += 1
            self.tokens += (result.usage.input_tokens or 0) + (result.usage.output_tokens or 0)
            if path is not None:
                _save(path, {'request': body, 'model': result.model, 'answers': raw})

        return {key: _read(nodes[key], raw[key], key) for key in questions}


def _read(node: Node, answer: Any, key: str) -> Union[float, Answer]:
    """
    An answer as the API sends it: a noul's probability, or a choice's pick or a score with a
    probability for each option or level, which come in no set order and are put in the node's.
    """
    # A cache written before choices and scores holds a noul's probability alone.
    if node.type == 'noul' and isinstance(answer, (int, float)):
        return float(answer)
    if not isinstance(answer, dict) or answer.get(node.type) is None:
        raise RuntimeError(f'TypeSafe gave no {node.type} answer to {key}')
    if node.type == 'noul':
        return float(answer['noul'])
    given = answer.get('probabilities') or {}
    parts = {str(p): float(given.get(str(p), 0.0)) for p in (node.options if node.type == 'choice' else range(len(node.options)))}

    return Answer(str(answer['choice']) if node.type == 'choice' else float(answer['score']), parts)


def _only(node: Node, state: State) -> State:
    return {signal: state[signal] for signal in node.reads}


def _save(path: Path, entry: Any) -> None:
    """Written aside and moved into place, so a reader never meets half a file."""
    path.parent.mkdir(parents=True, exist_ok=True)
    aside = path.with_name(f'{path.name}.{secrets.token_hex(8)}.tmp')
    aside.write_text(json.dumps(entry), encoding='utf-8')
    aside.replace(path)
