# Behat tests for local_unifiedgrader

A small `@local_unifiedgrader_critical` smoke pack targeting the user-flow
regressions PHPUnit can't catch — the ones we've actually shipped fixes
for and want to lock in.

## Scope

These tests are deliberately narrow. The plugin already has 392 PHPUnit
tests covering managers, adapters, web services, hooks and privacy at
the server-call boundary. Behat covers what PHPUnit *can't*:

- Sequences of UI events (focus → input → focusout → autosave)
- Reactive-framework rendering after AJAX completes
- Cross-page persistence (preferences, dirty state)
- DOM affordances that look correct in isolation but interact badly
  (override badge, dash escape hatches, group filter)

If a regression you're worried about can be exercised by a single
`function test_*` then it belongs in PHPUnit, not here.

## What's covered

Each feature file targets a specific user flow we've shipped a fix for
and want a regression test against. Scenarios tagged `@local_unifiedgrader_wip`
are intentional stubs that document the intended check but require
either a new custom step or a generator helper before they can run —
they're flagged for follow-up rather than left as broken tests.

| File | Critical flow |
|---|---|
| `grade_override.feature` | Manual grade override survives subsequent marking-guide edits |
| `grade_reset.feature` | `-` clears the grade and the clear reaches the server; stray characters reset without throwing |
| `group_filter.feature` | Default group selection + per-cmid persistence across refreshes |
| `quiz_grade_readonly.feature` | The quiz total is a read-only readout; an assignment grade stays editable |
| `annotation_toolbar_after_zoom.feature` | Tool clicks still dispatch to the active annotation layer after a zoom — the v2.5.1 / v2.5.2 stuck-tool regression |
| `feedback_card_reedit.feature` | Re-editing saved overall feedback collapses back to the read-only card on save |
| `concurrent_save.feature` | A save requested while another is in flight is held and re-run, not dropped |
| `marking_keyboard_navigation.feature` | Arrow keys navigate students from the page, and never from an editing context |
| `marking_panel_attempt_selector.feature` | The attempt selector appears for a genuinely multi-attempt submission |

Worth adding next (not in this scaffold):

- Comment library pill scoping (system + current course only)
- Universal comment cross-course visibility
- Quiz post-grades dialog mentions only Marks / Max Marks / Overall feedback
- Override indicator + Reset to rubric total action

## Running locally

From the Moodle root:

```bash
# One-time: initialise the Behat environment
php admin/tool/phpunit/cli/init.php  # only needed for the parallel DBs
php admin/tool/behat/cli/init.php

# Run only this plugin's critical smoke pack
php admin/tool/behat/cli/run.php --tags='@local_unifiedgrader_critical&&~@local_unifiedgrader_wip'

# Run a single feature
php admin/tool/behat/cli/run.php tests/behat/grade_override.feature
```

The `~@local_unifiedgrader_wip` exclusion skips the WIP stubs so the
green run stays meaningful.

## Running in CI

`.github/workflows/ci.yml` already has a Behat step conditional on
`plugin/tests/behat` existing. Adding this directory turns it on — no
workflow edit needed. The CI step runs the entire plugin Behat suite
across the matrix (PHP 8.2 / 8.3 × MariaDB / Postgres).

If the Behat job's runtime becomes a bottleneck, narrow the CI to just
the critical tag:

```yaml
- name: Behat features
  run: moodle-plugin-ci behat --profile chrome --tags '@local_unifiedgrader_critical&&~@local_unifiedgrader_wip' ./plugin
```

## Custom step definitions

`behat_local_unifiedgrader.php` ships these plugin-specific steps:

Navigation and input

- `I am on the Unified Grader for activity "<name>"` — resolves cmid by activity name
- `the marking panel has loaded` — waits for the reactive panel to settle
- `I enter "<value>" as the overall grade` — types into the grade input and triggers focusout
- `I set the rubric score for "<criterion>" to "<score>"` — fills a marking-guide score input by criterion name
- `I press the <left|right> arrow key from the <editor toolbar|grade input|page body>` — fires a keydown from a chosen origin

Seeding

- `"<student>" has been graded with feedback "<text>" on "<activity>"` — saves a grade + overall feedback server-side
- `"<student>" has <n> graded submission attempts on "<activity>"` — seeds multi-attempt submission data
- `the "<plugin/setting>" admin setting is "<value>"` — one-line `set_config()`

Assertions

- `the overall grade shows "<value>"` — reads the live input value (core's field steps cannot target it by CSS)
- `the overall feedback is shown as a saved card` / `is open for editing` — waits for the display/editor swap
- `the active annotation layer should report tool "<tool>"` — reads the layer's own `data-current-tool` stamp
- `I note the current Unified Grader student` / `the Unified Grader student should be <unchanged|changed>`
- `the saved grade for "<student>" on "<activity>" is "<value>"` — spins on the stored grade, for scenarios about
  whether a save reached the server at all

Race construction

- `I enter "<a>" as the overall grade and correct it to "<b>" before the save lands` — drives both saves from one
  synchronous script, so the overlap is deterministic rather than a race against the network

Everything else uses core Moodle steps (`behat_general`, `behat_forms`,
`behat_navigation`, `behat_data_generators`). Prefer extending core
behaviour through scenarios before adding more step definitions here —
the maintenance burden is proportional to how custom you go.

## WIP scenarios — follow-up work

Two scenarios are stubbed out with `@local_unifiedgrader_wip`:

1. **Group filter persistence (group_filter.feature)** — needs a step
   that interacts with the multi-select group dropdown (`student_navigator.js`).
   Prefer setting the value and dispatching `change` over simulating clicks:
   the scenario is about persistence, not about the dropdown's mechanics, and
   a collapsed container makes its controls non-interactable.
2. **Tool survives a zoom (annotation_toolbar_after_zoom.feature)** —
   every step it needs exists; what it lacks is a PDF submission to
   annotate. That means a real fixture, not a stub string: the existing
   helper writes `%PDF-1.4 test file content`, which PDF.js cannot parse,
   so no annotation layer is ever created. Even with one, the scenario
   drives PDF.js rendering, zoom and a Fabric canvas — the headless-fragile
   shape this pack is meant to stay away from. Covering the propagation
   logic without the rendering stack is the better trade.

Two former entries are gone rather than done:

- **Override survives a rubric edit** is now a live scenario in
  `grade_override.feature`; the missing piece was a marking-guide generator,
  and core ships one (`gradingform_guide_generator`) that works from a Behat
  context unchanged.
- **`--` removes the orphan submission** was removed as unwritable. None of
  what the deliberate reset does is visible through the browser — a row with
  status `new` already reads as "not submitted", and the row is never deleted
  — so it is asserted against the database in
  `assign_adapter_test::test_full_reset_strips_the_orphan_only_with_the_capability`
  instead.

Pick these up when they become important enough to justify the step
definitions. Until then, the WIP tag keeps them in the file as
documentation of intent without contaminating the green run.
