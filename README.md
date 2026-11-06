# mutagent

[![tests](https://github.com/js199069/mutagent/actions/workflows/tests.yml/badge.svg)](https://github.com/js199069/mutagent/actions/workflows/tests.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

**An LLM writes PHPUnit tests for your class. Mutation testing decides whether they are any good.**

Coverage tells you which lines ran. It does not tell you whether your tests would notice a bug.
On a small example class, a test that executed every mutated line **killed only 42% of the mutants**:
change `+` to `-` in the price calculation, and the suite stays green.

mutagent closes that gap:

1. **generate** — an LLM writes tests; PHPUnit runs them; failures go back to the model with a precise diagnosis.
2. **improve** — [Infection](https://infection.github.io) mutates the class; for every mutant that survives, the model
   either writes a test that kills it, or argues that it is *equivalent* (no input can tell the two apart).
3. You review the equivalence claims. The tool never hides them inside a better-looking score.

~~~bash
composer require --dev js199069/mutagent infection/infection

vendor/bin/mutagent generate src/Parser/DecimalMoneyParser.php
vendor/bin/mutagent improve  src/Parser/DecimalMoneyParser.php
~~~

~~~
  round 0: MSI 97.87% — 46 killed, 1 escaped, 0 not covered, 47 mutants
  round 1: MSI 97.87% — 46 killed, 1 escaped, 0 not covered, 47 mutants
  MSI excluding claimed-equivalent   100%

Claimed equivalent (review these)
- LessThan, line 66: When fractionDigits equals subunit, str_pad adds an empty string, so the result is unchanged.
~~~

---

## Results

Measured on [moneyphp/money](https://github.com/moneyphp/money) (commit `a519944`). All generated tests start from
nothing: the model never sees the project's own tests. Full details, costs and caveats: [BENCHMARK.md](BENCHMARK.md).

| Class | Mutants | Human tests (2,419) | LLM, single shot | mutagent | Excluding claimed-equivalent |
|---|---|---|---|---|---|
| `DecimalMoneyFormatter` | 31 | 100% | 100% | 100% | 100% |
| `DecimalMoneyParser` | 47 | 93.6% | **97.9%** | 97.9% | 100% |
| `Number` | 222 | 67.1% | *not completed (budget)* | — | — |

What this shows, honestly:

- **On small, well-specified classes, a single LLM pass is already enough** — it matched or beat a mature library's
  hand-written tests. The mutation loop added no kills there; its value was separating the one equivalent mutant
  from the rest, with a reason a human can check.
- **On complex logic, the model is not reliable.** For `Number` (string-based rounding and decimal shifting), 17 of
  the generated tests contradicted the code after three repair attempts and were removed. This is where mutation
  feedback should matter, and where this benchmark needs more budget.
- **Equivalence claims were precise:** on the example class, all 5 claims were correct; the 6th equivalent mutant was
  not recognised and was reported as unresolved rather than silently counted.

---

## How it works

~~~
class ─► LLM writes tests ─► PHPUnit (random order) ─► red? ─► output + repair hint ─► LLM fixes ─► … (3 attempts)
                                                         └─► still a few red? remove them, keep the rest, report them

tests ─► Infection (isolated: one class, one test file, uncovered mutants counted)
      ─► surviving mutants ─► LLM: "kill" (new test methods) or "equivalent" (with a reason)
      ─► new methods merged ─► must be green on the original code, or reverted
      ─► Infection again ─► … (each mutant targeted at most twice)
~~~

### Design decisions

**The code is the oracle.** Generated tests describe what the code does today, not what it should do. If a test
still contradicts the code after repairs, the test is wrong. mutagent writes regression tests; it does not find
bugs in your specification.

**Deterministic knowledge beats retrying.** A model that keeps writing `@dataProvider` (ignored since PHPUnit 10)
does not learn from "Too few arguments". mutagent recognises known failure patterns and states the cause:
attributes instead of annotations, `'-1000'` (string) vs `-1000` (int) in `assertSame`, an exception that is never
thrown. On one class, the type hint alone took a run from 18 failing tests to 1.

**A score is not allowed to hide anything.** Three numbers are always shown: raw MSI, MSI excluding *claimed*
equivalent mutants, and the list of claims with reasons. Mutants the model could not kill or explain are listed
as unresolved.

**Isolated measurement.** Infection runs with its own configuration in `.mutagent/`: only the class under test, only
the generated test file, and `--with-uncovered`, so the denominator always contains every mutant of the class.

**Tests that kill no mutant are reported, not deleted.** "Throws on a negative weight" may be redundant for mutation
purposes and still document a business rule. `--minimize` removes them, and only if Infection confirms that no kill
is lost.

**Built for a small budget.** LLM responses are cached (re-runs cost nothing and are reproducible), equivalence
claims are stored and never re-sent, and the model returns only new test methods instead of the whole file.

---

## Configuration

Any OpenAI-compatible endpoint:

~~~bash
export MUTAGENT_LLM_URL=https://your-endpoint/v1
export MUTAGENT_LLM_KEY=...
export MUTAGENT_LLM_MODEL=...
~~~

The project under test needs PHPUnit 10+, Infection, and a coverage driver (Xdebug or PCOV).

| Command | Options |
|---|---|
| `generate <class-file>` | `--project`, `--out` (default `tests/Generated`), `--attempts` (3), `--model`, `--no-cache` |
| `improve <class-file>` | `--project`, `--test`, `--rounds` (3; 0 = measure only), `--minimize`, `--model`, `--no-cache` |
| `report [log]` | Summary of an Infection JSON log |

Add `.mutagent/` to your project's `.gitignore`.

---

## Limitations

- **Local models on a CPU are impractical.** Prompts carry the class and the whole test file; a 3B model on a laptop
  either ran out of memory or exceeded the timeout. Use an API or a GPU.
- **Equivalence claims are claims.** They are shown for review, never proven.
- **The benchmark is small** (two complete classes from one library, plus an example class) because it was run on a
  personal budget. Numbers show direction, not precision.
- **Generated tests lock in current behaviour**, including existing bugs.
- One run reported removing a test that was still in the file; the cause was not identified. Removal is now verified
  by re-parsing the file, and salvage at the start of `improve` uses a full PHPUnit run.
- Classes with heavy dependencies (database, framework container) are not a target yet.

## Testing

~~~bash
composer install
vendor/bin/phpunit
~~~

## License

MIT
