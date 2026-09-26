"""site/index.html is the README; `python3 bin/site.py` writes it again."""
from __future__ import annotations

import importlib.util
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


def _site():
    spec = importlib.util.spec_from_file_location('site_builder', ROOT / 'bin' / 'site.py')
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)

    return module


def test_is_in_step_with_the_readme() -> None:
    written = (ROOT / 'site' / 'index.html').read_text(encoding='utf-8')

    assert written == _site().page((ROOT / 'README.md').read_text(encoding='utf-8')), 'run python3 bin/site.py'


def test_leaves_no_tag_unclosed() -> None:
    text = (ROOT / 'site' / 'index.html').read_text(encoding='utf-8')
    for tag in ('main', 'p', 'table', 'code', 'pre', 'ul', 'li', 'tbody'):
        assert len(re.findall(rf'<{tag}[ >]', text)) == text.count(f'</{tag}>'), tag


def test_links_every_section_it_has() -> None:
    text = (ROOT / 'site' / 'index.html').read_text(encoding='utf-8')
    for target in re.findall(r'href="#([^"]+)"', text):
        assert f'id="{target}"' in text, target
