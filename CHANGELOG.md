# Changelog

## [0.1.0] — 2026-11-06

First public release.

- `generate`: an LLM writes PHPUnit tests for a class; failures are fed back with deterministic repair hints
  (PHPUnit 10+ attributes, string vs number in `assertSame`, exceptions that are not thrown) until they pass.
  If only a few tests still contradict the code, they are removed and reported (the code is the oracle).
- `improve`: Infection runs in isolation (one class, one test file, all mutants counted); the model either writes
  tests to kill each surviving mutant or claims it is equivalent with a reason. Red candidates are reverted.
  Equivalence claims are stored and never re-sent. Replies contain only new methods, merged into the file.
- `report`: readable summary of an Infection JSON log.
- LLM response cache (reproducible runs, no tokens on re-runs); token usage per run.
- Works with any OpenAI-compatible endpoint.
