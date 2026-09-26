# composable-jev

The library is in PHP (`php/`), TypeScript (`js/`) and Python (`python/`). The page for building
graphs in a browser, and the server behind it, are a consumer of it in the separate
composable-jev-demo project.

## One library, three languages

- The files in `data/` are read by all three. Gate wording, rule descriptions and presets are
  edited there, never in code.
- `spec/fixtures.json` is written by PHP (`composer fixtures`, from `php/tests/Fixtures.php`) and
  read by the JavaScript and Python tests. A change to how a graph runs, rounds, batches or
  encodes goes into all three, then the fixtures are rewritten and every suite runs.
- `site/index.html` is the README, written by `python3 bin/site.py`. A README change is followed
  by that, or the site test fails.
- The implementations mirror each other: the same names, in the same order, in each language's
  casing.
- A float goes over the wire as a float (`1.0`), and whole seconds of `time` as an integer. The
  JavaScript package does this with `JSON.rawJSON`, so it needs Node 22.

## Code

- PHP 8.3, strict types, PSR-12. `pint.json` at the package root is authoritative.
- TypeScript strict, ESM, compiled by `tsc` to `js/dist`. Python 3.10+, `ruff` as configured in
  `pyproject.toml`.
- `Jev` calls TypeSafe through each language's SDK, one client for every call in a run: a fresh
  TLS connection per request costs ~540ms against ~205ms on a reused one. The PHP SDK sends a
  float with its `.0` from 0.4.2, which is why that is the lowest version required.
- Tests for every behaviour, in every language. Tests run offline against a fake TypeSafe in
  each. No test calls a paid API.
- Question wording - gate templates, preset questions - is behaviour: a change moves Jev's answers
  and the measurements quoted beside them. Change it as measured work, never as a tidy-up.

## Writing

- A comment states what the code is now, never what it was. Git holds history.
- A comment carries a fact the code cannot: a wire fact, a trap, an invariant, a why-not, the
  measurement behind a decision. If the name and type already say it, delete it.
- Keep the number that justifies a decision, stated in the present.
- Cite only what a reader can open. Name things instead of counting them.
- One fact per sentence. No closing epigram, no section banners, no docblock restating a signature.
- No temporal markers ("now", "currently", "still"), no "actually", "simply", "genuinely". Use
  " - " for a dash, and "instead of" for "rather than".
- Commit messages: imperative subject describing the behaviour change; the body says what was
  broken and what the fix does.
