# composable-jev

A library to chain Jev calls, for the deranged and enlightened

## Quick start

| | install | needs |
| --- | --- | --- |
| PHP | `composer require phox/composable-jev` | PHP 8.3+ |
| JavaScript | `npm install @phox-js/composable-jev` | Node 22+ |
| Python | `pip install composable-jev` | Python 3.10+ |

```php
// PHP
use Phox\ComposableJev\Graph;
use Phox\ComposableJev\Oracle\Jev;

$triage = Graph::make('I was charged twice this month. This is the third time. Refund me now.')
    ->ask('refund', 'Is the customer asking for money back?', Graph::CONTEXT)
    ->ask('angry', 'Is the customer angry or out of patience?', Graph::CONTEXT)
    ->all('escalate', 'refund', 'angry');

$triage->run(Jev::make())->yes('escalate');                      // true
```

```ts
// JavaScript
import { Graph, Jev } from '@phox-js/composable-jev';

const triage = Graph.make('I was charged twice this month. This is the third time. Refund me now.')
    .ask('refund', 'Is the customer asking for money back?', Graph.CONTEXT)
    .ask('angry', 'Is the customer angry or out of patience?', Graph.CONTEXT)
    .all('escalate', 'refund', 'angry');

(await triage.run(Jev.make())).yes('escalate');                   // true
```

```python
# Python
from composable_jev import Graph, Jev

triage = (Graph.make('I was charged twice this month. This is the third time. Refund me now.')
    .ask('refund', 'Is the customer asking for money back?', Graph.CONTEXT)
    .ask('angry', 'Is the customer angry or out of patience?', Graph.CONTEXT)
    .all('escalate', 'refund', 'angry'))

triage.run(Jev.make()).yes('escalate')                            # True
```

`Jev::make()` is the TypeSafe SDK's client in each language, reading `TYPESAFE_API_KEY`; a key
passed to it is used instead.

## Running a graph

- **`make($context)`** or **`context()`** sets what the questions are about, text, 
  or a JSON object or array.
- **`run()`** settles the graph once from its starting values.
- **`truthTable()`** runs every combination of the on/off inputs, and every context, each from
  the start.
- There's a lot of other weird stuff too but idk you can figure it out when you use it

## Rules

Rules compare and evaluate the outputs of Jev queries 

| | works out | over 0.5 where |
| --- | --- | --- |
| `all('step_label', 'input_a', 'input_b')` | the lowest | each is |
| `any('step_label', 'input_a', 'input_b')` | the highest | at least one is |
| `sum('step_label', 'team.billing', 'team.bugs')` | the sum, at most 1 | one is, where no two can be: options, levels |
| `none('step_label', 'input_a', 'input_b')` | 1 minus the highest | none is: not over one signal, nor over more |
| `atLeast('step_label', 2, 'input_a', 'input_b', 'input_c')` | the value 2 down from the highest | at least 2 are |
| `average('step_label', ['input_a' => 3, 'input_b' => 1])` | the weighted average | the weighted average is |
| `rule('step_label', fn (array $s) => ..., 'input_a', 'input_b')` | your function, 0 to 1 | - |

Each gives a probability, not a yes or no, so a question after it sees how sure the answers
were. A `rule()` is a custom function. 

## Choices and scores

A node can ask Jev any of its primitives. A choice's value is the option Jev picked and a score's
the score it gave, which is what a later question reads; the probability of each option or level
is a part of the node, `team.billing` or `severity.3`, which is what code reads:

```php
Graph::make('The export button has thrown an error for three days. Fix it today.')
    ->choose('team', 'Which team should handle this ticket?', Graph::CONTEXT, [
        'billing' => 'Payments, charges and refunds',
        'bugs' => 'Something in the product is broken',
        'other' => 'Anything else',
    ])
    ->score('severity', 'How much harm is the problem doing?', Graph::CONTEXT,
        ['None', 'A little', 'Some', 'A lot', 'It stops the customer working'])
    ->sum('serious', 'severity.3', 'severity.4')
    ->ask('senior', 'The ticket is for the team named, and serious is the chance its problem is '
        . 'serious. Should someone senior reply today?', [Graph::CONTEXT, 'team', 'serious']);
```

`team`, `severity` and the questions beside them go to Jev in one request; `senior` reads the
ticket, the team picked and a rule's answer, in a second. `$row->choice('team')` is the option
picked and `$row->probabilities('team')` each option's chance. A rule cannot read `team`, which
is a word; it reads `team.billing`.

## Gates asked of Jev

Jev can do the logic too: a graph of gates asked of Jev computes, remembers and keeps time (poorly). 
For real work a rule is always better, but it's pretty fun to use Jev to do it

Two nors reading each other make a latch: `s` sets `q`, `r` resets it, and with both off `q`
holds whatever it was.

```php
$latch = Graph::make()
    ->input('s', 'r')
    ->gate('q', 'nor', 'r', 'qbar')
    ->gate('qbar', 'nor', 's', 'q')
    ->startsAt('q', 0.0)
    ->startsAt('qbar', 1.0)
    ->ticker(Jev::make());

$latch->tick(['s' => 1]);
$latch->tick(['s' => 1]);          // a loop goes round once a tick, so setting takes two
$latch->tick()->yes('q');          // true: q holds with s off
$latch->tick(['r' => 1])->yes('q'); // false
```

The gates are `buffer`, `not`, `and`, `or`, `nand`, `nor`, `xor` and `majority`, over any number of
signals where it makes sense; each asks about the signals by name ("Are `input_a` and `input_b` both greater
than 0.5?" is what's used for an AND gate, for example)

## Asking Jev

`Jev` batches: a level's nodes go in one request, their inputs merged into one state, so the fixed
part of a call is paid once a level instead of once a node. Over the adders, xor, half-adder and
ticket triage, with every node asked of Jev, batched and separate answers fell on the same side of
0.5 for 2,722 of 2,731 nodes, batched was right as often or more, and it used 26 to 39% fewer
tokens in about half the requests. `Jev::make($key, batch: false)` asks each node on its own.

A float is sent with its decimal (`1.0`) and a whole second without (`32`): asked whether an odd
number of (1, 1, 0.96) is above 0.5, Jev answered 0.50 to 0.58 with the ones sent as `1` and
0.55 to 0.66 as `1.0`. JavaScript has one number type, so its `Jev` writes floats with
`JSON.rawJSON`, which is why it needs Node 22.

Answers are cached by request body in the cache directory. The key is the same in all three
languages, so they can share one.

`Jev` calls TypeSafe through each language's SDK:
[`phox/typesafe-sdk-php`](https://github.com/Fox-Islam/typesafe-sdk-php),
[`@typesafe-ai/sdk`](https://www.npmjs.com/package/@typesafe-ai/sdk) and
[`typesafe-sdk`](https://pypi.org/project/typesafe-sdk/). `new Jev($client)` takes a client you
have set up yourself, for its provider, timeout or retries.

`Simulator` answers every node from its rule, for free, and cannot answer a question of your own.

## Development

```bash
composer install && composer test && composer lint
npm install && npm test
uv sync && uv run pytest && uv run ruff check python
```

Tests run offline, against a fake TypeSafe in each language.

`spec/fixtures.json` is what the PHP implementation gives for a set of runs, with every request
body the fake was sent, and for the rules, plans and numbers under them. `composer fixtures`
writes it, the JavaScript and Python tests hold theirs to it, and a PHP test fails when it is out
of date.

`site/index.html` is this README as a page, published by `.github/workflows/pages.yml` on every push
to `main`. `python3 bin/site.py` writes it again, and a Python test fails when it is out of date.

