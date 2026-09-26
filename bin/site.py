"""
Writes site/index.html from the README, in the shape of jevlint's site. The page is the README,
so the header links to its sections; `python/tests/test_site.py` fails when the two differ.

    python3 bin/site.py
"""
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent

REPOSITORY = 'https://github.com/Fox-Islam/composable-jev'

GITHUB = (
    f'<a class="gh" href="{REPOSITORY}" aria-label="composable-jev on GitHub">'
    '<svg viewBox="0 0 16 16" width="26" height="26" aria-hidden="true" focusable="false">'
    '<path fill="currentColor" d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 '
    '0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 '
    '1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 '
    '0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82a7.4 7.4 0 0 1 2-.27c.68 0 1.36.09 '
    '2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 '
    '3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.01 8.01 0 0 0 16 8c0-4.42-3.58-8-8-8Z"/>'
    '</svg></a>'
)


def escape(text):
    """`html.escape` also turns an apostrophe into an entity, which the page has no need for."""
    return text.replace('&', '&amp;').replace('<', '&lt;').replace('>', '&gt;').replace('"', '&quot;')


def slug(text):
    return re.sub(r'[^a-z0-9]+', '-', text.lower()).strip('-')


def inline(text):
    """
    Links first, then the code and the emphasis inside each label: a label holding inline code
    is two constructs nested, and taking the code out first leaves the link nothing to match.
    """
    out = ''
    at = 0
    for found in re.finditer(r'\[([^\]]+)\]\(([^)]+)\)', text):
        out += _emphasis(text[at:found.start()])
        out += f'<a href="{found.group(2)}">{_emphasis(found.group(1))}</a>'
        at = found.end()

    return out + _emphasis(text[at:])


def _emphasis(text):
    """Bold, then the code inside it, since bold wrapping inline code is the outer construct."""
    out = ''
    at = 0
    for found in re.finditer(r'\*\*(.+?)\*\*', text, re.S):
        out += _spans(text[at:found.start()])
        out += '<strong>' + _spans(found.group(1)) + '</strong>'
        at = found.end()

    return out + _spans(text[at:])


def _spans(text):
    out = ''
    at = 0
    for found in re.finditer(r'`([^`]+)`', text):
        out += escape(text[at:found.start()])
        out += '<code>' + escape(found.group(1)) + '</code>'
        at = found.end()

    return out + escape(text[at:])


def body(lines):
    out = []
    paragraph = []
    index = 0

    def flush():
        if paragraph:
            out.append('<p>' + inline('\n'.join(paragraph)) + '</p>')
            paragraph.clear()

    while index < len(lines):
        line = lines[index]
        if line.startswith('```'):
            flush()
            language = line[3:].strip()
            code = []
            index += 1
            while index < len(lines) and not lines[index].startswith('```'):
                code.append(lines[index])
                index += 1
            opening = f'<pre><code class="language-{language}">' if language else '<pre><code>'
            out.append(opening + escape('\n'.join(code)) + '\n</code></pre>')
            index += 1
        elif line.startswith('#'):
            flush()
            level = len(line) - len(line.lstrip('#'))
            text = line[level:].strip()
            if level > 1:
                out.append(f'<h{level} id="{slug(text)}">{inline(text)}</h{level}>')
            index += 1
        elif line.startswith('|'):
            flush()
            rows = []
            while index < len(lines) and lines[index].startswith('|'):
                rows.append([cell.strip() for cell in lines[index].strip().strip('|').split('|')])
                index += 1
            out.append('<div class="scroll"><table>')
            out.append('<thead>\n<tr>\n' + '\n'.join(f'<th>{inline(c)}</th>' for c in rows[0]) + '\n</tr>\n</thead>')
            out.append('<tbody>')
            for row in rows[2:]:
                out.append('<tr>\n' + '\n'.join(f'<td>{inline(c)}</td>' for c in row) + '\n</tr>')
            out.append('</tbody>')
            out.append('</table></div>')
        elif line.startswith('- '):
            flush()
            items = []
            while index < len(lines) and (lines[index].startswith('- ') or lines[index].startswith('  ')):
                if lines[index].startswith('- '):
                    items.append(lines[index][2:])
                else:
                    items[-1] += '\n' + lines[index].strip()
                index += 1
            out.append('<ul>\n' + '\n'.join(f'<li>{inline(item)}</li>' for item in items) + '\n</ul>')
        elif line.strip() == '':
            flush()
            index += 1
        else:
            paragraph.append(line)
            index += 1
    flush()

    return out


def page(readme):
    lines = readme.split('\n')
    title = lines[0].lstrip('# ').strip()
    sections = [line[3:].strip() for line in lines if line.startswith('## ')]
    nav = ''.join(f'<a href="#{slug(s)}">{inline(s)}</a>' for s in sections)

    return (
        '<!doctype html>\n<html lang="en">\n<head>\n<meta charset="utf-8">\n'
        '<meta name="viewport" content="width=device-width, initial-scale=1">\n'
        f'<title>{title}</title>\n'
        '<link rel="stylesheet" href="style.css">\n</head>\n<body>\n'
        f'<header><nav>{nav}</nav></header>\n<main>\n'
        f'<div class="title-row"><h1 id="{slug(title)}">{title}</h1>{GITHUB}</div>\n'
        + '\n'.join(body(lines))
        + '\n</main>\n<footer><p>Jev questions composed into graphs, in PHP, JavaScript and Python. '
        f'<a href="{REPOSITORY}">Source on GitHub</a>.</p></footer>\n'
        '</body>\n</html>\n'
    )


if __name__ == '__main__':
    target = ROOT / 'site' / 'index.html'
    target.write_text(page((ROOT / 'README.md').read_text(encoding='utf-8')), encoding='utf-8')
    sys.stdout.write(f'wrote {target}\n')
