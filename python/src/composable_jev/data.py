"""
The files in `data/` at the package root. They are not Python: the PHP and JavaScript packages
read the same files, so a gate's wording and a preset exist once.
"""
from __future__ import annotations

import json
from functools import cache
from pathlib import Path
from typing import Any

_HERE = Path(__file__).resolve().parent


@cache
def data(file: str) -> Any:
    shipped = _HERE / 'data' / file
    path = shipped if shipped.exists() else _HERE.parents[2] / 'data' / file

    return json.loads(path.read_text(encoding='utf-8'))
