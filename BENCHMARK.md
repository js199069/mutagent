# Benchmark

## Setup

| | |
|---|---|
| Library | [moneyphp/money](https://github.com/moneyphp/money) @ `a519944` |
| Project tooling | PHPUnit 10.5.66, Infection 0.35.9 (`--with-uncovered`), Xdebug 3.3.2, PHP 8.3.14, Windows 11 |
| Model | Qwen 3.5 class model through an OpenAI-compatible gateway, temperature 0.2 |
| Human baseline | The library's full suite: 2,419 tests. `ComparatorTest.php` was excluded: it fails on Windows only, because of line endings in a diff string, and does not test money logic. |

"LLM, single shot" is round 0 of `improve`: the tests produced by `generate`, before any mutation feedback.
All generated tests start from nothing; the model never sees the library's tests.

## Results

| Class | Mutants | Human | Single shot | mutagent | Excl. claimed-equivalent | Tokens (final run) |
|---|---|---|---|---|---|---|
| `Formatter/DecimalMoneyFormatter` | 31 | 100% (31) | 100% (31) | 100% | 100% | 15,235 (2 requests) |
| `Parser/DecimalMoneyParser` | 47 | 93.62% (44) | 97.87% (46) | 97.87% | 100% | 23,228 + 10,414 |
| `Number` | 222 | 67.12% (149) | not completed | — | — | 42,338 for `generate` |

### Notes per class

**DecimalMoneyFormatter.** Passed on the second attempt. Every mutant killed in round 0; `improve` made no requests.
19 generated tests kill no mutant (kept).

**DecimalMoneyParser.** The first two development runs failed: the model expected `getAmount()` to return an
integer (it returns a string) and expected an exception for an input the parser accepts. Adding deterministic repair
hints for both cases fixed it; those runs cost 51,436 tokens and are not included in the table. One surviving mutant
(`LessThan`, line 66) was claimed equivalent: when the number of fraction digits equals the currency's subunit,
`str_pad` pads nothing, so `<` and `<=` behave the same. Author's review: correct.

**Number.** Three repair attempts did not converge; 17 tests that still contradicted the code were removed, and the
remaining file still had two red tests under Infection. The `improve` run did not complete because the token budget
ran out. This is the class where mutation feedback is expected to matter most.

## Example class (not part of the library benchmark)

`ShippingCalculator` (in `tests/Fixtures`), 33 mutants with all branches covered:

| | MSI |
|---|---|
| Generated tests, round 0 | 78.79% |
| After the mutation loop | 81.82% |
| Excluding claimed-equivalent | 96.43% |

All 6 survivors are equivalent (checked by hand: e.g. costs are always multiples of 5,000, so `round`, `floor` and
`ceil` of `cost × 1.5` agree). The model claimed 5 of them, all correctly, and failed to recognise the 6th
(`>=` vs `>` at the 500,000 cap), which was reported as unresolved after two attempts.

## Reproducing

~~~bash
git clone https://github.com/moneyphp/money.git && cd money && git checkout a519944
composer install && composer require --dev infection/infection
# exclude tests/ComparatorTest.php on Windows (see above)
vendor/bin/mutagent generate src/Parser/DecimalMoneyParser.php
vendor/bin/mutagent improve  src/Parser/DecimalMoneyParser.php
~~~

The LLM responses used for these numbers are in `benchmark/cache/`. Copy them to `.mutagent/llm-cache/` in the
library checkout, with the same model and endpoint settings, and the runs replay without any tokens.
