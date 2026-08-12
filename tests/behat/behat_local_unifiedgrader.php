<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Custom Behat step definitions for the Unified Grader.
 *
 * Kept deliberately small. Where Moodle already ships a step (navigation,
 * forms, data generators, JS waits) we use it directly from feature files.
 * The steps here cover the handful of plugin-specific affordances —
 * mainly "open the grader for this cmid" and "wait for the marking panel
 * to settle" — that don't exist in core Behat.
 *
 * @package    local_unifiedgrader
 * @category   test
 * @copyright  2026 South African Theological Seminary (mathieu@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// NOTE: no MOODLE_INTERNAL check here, this file is required by behat before including /config.php.

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

use Behat\Mink\Exception\ExpectationException;

/**
 * Unified Grader steps.
 */
class behat_local_unifiedgrader extends behat_base {
    /**
     * Open the Unified Grader for the activity with the given name in the
     * current course. Resolves the cmid by name lookup so feature files
     * don't have to chase numeric IDs across scenarios.
     *
     * Example:
     *   Given I am on the Unified Grader for activity "Essay 1"
     *
     * @Given /^I am on the Unified Grader for activity "(?P<activityname>(?:[^"]|\\")*)"$/
     * @param string $activityname
     */
    public function i_am_on_the_unified_grader_for_activity(string $activityname): void {
        global $DB;
        $cm = $DB->get_record_sql(
            "SELECT cm.id
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
              WHERE (
                   (m.name = 'assign' AND cm.instance IN (SELECT id FROM {assign} WHERE name = :n1))
                OR (m.name = 'forum'  AND cm.instance IN (SELECT id FROM {forum}  WHERE name = :n2))
                OR (m.name = 'quiz'   AND cm.instance IN (SELECT id FROM {quiz}   WHERE name = :n3))
              )",
            ['n1' => $activityname, 'n2' => $activityname, 'n3' => $activityname],
        );
        if (!$cm) {
            throw new Exception("No activity named '{$activityname}' found");
        }
        $url = new moodle_url('/local/unifiedgrader/grade.php', ['cmid' => $cm->id]);
        $this->execute('behat_general::i_visit', [$url]);
    }

    /**
     * Wait for the marking panel to finish its initial render.
     *
     * This has to wait on something the JavaScript PRODUCES, not on an element
     * the template ships. Both selectors this step used to wait for —
     * [data-region="rubric-body"] and [data-action="grade-input"] — are static
     * markup in marking_panel.mustache, present in the very first byte of HTML,
     * so the wait returned on its first evaluation and proved nothing. Scenarios
     * only survived because the step after it happened to wait for real.
     *
     * The two signals used instead:
     *
     *  - the navigator's current-student name, which starts as the literal "--"
     *    placeholder and is only rewritten once participants and the current
     *    student have loaded from the server (student_navigator.js
     *    _renderCurrentStudent). This is the strong one: it cannot be true
     *    before the reactive state holds server data, and the marking panel's
     *    own stateReady — which attaches every listener the scenarios depend
     *    on — runs before that.
     *  - the grade input's max attribute, which the template does not set and
     *    _updateMaxGrade stamps on during hydration. Only meaningful when the
     *    points input is the visible one, so scale-graded and grading-disabled
     *    activities are exempted rather than made to hang.
     *
     * @Given /^the marking panel has loaded$/
     */
    public function the_marking_panel_has_loaded(): void {
        $this->execute('behat_general::wait_until_the_page_is_ready');
        $js = "(function(){"
            . "var n = document.querySelector('[data-region=\"current-student-name\"]');"
            . "if (!n) { return false; }"
            . "var name = (n.textContent || '').trim();"
            . "if (name === '' || name === '--') { return false; }"
            . "var simple = document.querySelector('[data-region=\"simple-grade\"]');"
            . "var input = document.querySelector('[data-action=\"grade-input\"]');"
            . "if (!simple || !input || simple.classList.contains('d-none')) { return true; }"
            . "return input.hasAttribute('max');"
            . "})()";
        if (!$this->getSession()->wait(self::get_timeout() * 1000, $js)) {
            throw new ExpectationException(
                'The marking panel did not finish hydrating.',
                $this->getSession()
            );
        }
    }

    /**
     * Type a value into the top-level grade input and trigger the focus-out
     * autosave by clicking elsewhere. Mirrors what a teacher actually does
     * so the override / dirty / reset code paths fire naturally.
     *
     * Example:
     *   When I enter "18" as the overall grade
     *
     * @When /^I enter "(?P<value>(?:[^"]|\\")*)" as the overall grade$/
     * @param string $value
     */
    public function i_enter_as_the_overall_grade(string $value): void {
        $node = $this->find('css', '[data-action="grade-input"]');
        $node->setValue($value);
        // Force a focusout — most reliable cross-browser way is to focus
        // a different element. The save button is always present.
        $this->execute('behat_general::i_click_on', [
            '[data-region="marking-content"]', 'css_element',
        ]);
    }

    /**
     * Set the value of a marking-guide criterion score input by its
     * visible criterion shortname / heading. Useful for "fill the rubric
     * with some scores" steps without hardcoding criterion IDs.
     *
     * Example:
     *   When I set the rubric score for "Argumentation" to "3.5"
     *
     * @When /^I set the rubric score for "(?P<criterion>(?:[^"]|\\")*)" to "(?P<score>[^"]+)"$/
     * @param string $criterion
     * @param string $score
     */
    public function i_set_the_rubric_score_for(string $criterion, string $score): void {
        // The criterion header is .fw-bold sibling to the score input.
        // Find the row containing the heading text, then the input within.
        $xpath = "//div[contains(@class,'border-bottom')"
            . " and .//div[contains(@class,'fw-bold') and normalize-space(text())="
            . behat_context_helper::escape($criterion)
            . "]]"
            . "//input[@data-criterionid and not(@data-levelid)]";
        $input = $this->find('xpath', $xpath);
        $input->setValue($score);
    }

    /**
     * Assert the *active annotation layer* reports the given tool. Reads the
     * `data-current-tool` attribute stamped on the canvas wrapper by
     * AnnotationLayer._notifyToolChange — which only fires when the layer
     * actually accepted the tool change, distinct from the toolbar button's
     * .active class which can drift away from the layer's state when a
     * propagation race silently no-ops the dispatch (the exact regression
     * v2.5.1 + v2.5.2 chased).
     *
     * The "active" layer is the page slot whose annotation wrapper is the
     * most recent one to receive a tool stamp. We pick the last wrapper in
     * document order that has the attribute set — matches the toolbar's
     * current binding after a normal scroll/zoom sequence.
     *
     * Example:
     *   Then the active annotation layer should report tool "pen"
     *
     * @Then /^the active annotation layer should report tool "(?P<tool>[a-z]+)"$/
     * @param string $tool Expected tool key (e.g. pen, highlight, texthighlight).
     */
    public function the_active_annotation_layer_should_report_tool(string $tool): void {
        // Wait for the propagation tick to settle before reading.
        $this->execute('behat_general::wait_until_the_page_is_ready');
        // Any wrapper carrying the attribute will do — propagation keeps
        // every page in sync, so picking the first is sufficient.
        $node = $this->find('css', '[data-current-tool="' . $tool . '"]');
        if (!$node) {
            throw new Exception(
                "Expected an annotation layer reporting tool '{$tool}'; none found"
            );
        }
    }

    /** @var string|null Current student recorded for change/unchanged assertions. */
    protected $notedstudent = null;

    /**
     * Record the Unified Grader's current student so a later step can assert it
     * did (or did not) change. Avoids depending on participant sort order.
     *
     * @Given /^I note the current Unified Grader student$/
     */
    public function i_note_the_current_unified_grader_student(): void {
        $this->notedstudent = $this->current_unifiedgrader_student();
    }

    /**
     * Fire an arrow keydown originating from either the feedback editor surface
     * (a parent-document .tox element — an editing context the navigator must
     * ignore) or the page body (a legitimate navigation context). This is the
     * regression behind feedback / grades landing on the wrong submission: a
     * stray arrow while editing used to switch students because the guard only
     * excluded INPUT/TEXTAREA/SELECT by tag name and missed editor chrome.
     *
     * The "where" picks the keydown origin: an editing context the navigator
     * must ignore (the editor toolbar = a .tox surface; the grade input = an
     * INPUT — representative of every rubric score box, remark and comment
     * textarea), or the page body (a legitimate navigation gesture).
     *
     * @When /^I press the (?P<dir>left|right) arrow key from the (?P<where>editor toolbar|grade input|page body)$/
     * @param string $dir
     * @param string $where
     */
    public function i_press_arrow_key_from(string $dir, string $where): void {
        $key = $dir === 'left' ? 'ArrowLeft' : 'ArrowRight';
        if ($where === 'page body') {
            // Neutralise focus so the active element is not an editor, then fire
            // from the body — the genuine "I want to navigate" gesture.
            $js = "(function(){"
                . "if (document.activeElement && document.activeElement.blur) "
                . "{ try { document.activeElement.blur(); } catch (e) {} }"
                . "document.body.dispatchEvent(new KeyboardEvent('keydown', "
                . "{key: '{$key}', bubbles: true, cancelable: true}));"
                . "})();";
        } else {
            $selector = $where === 'grade input' ? '[data-action=grade-input]' : '.tox';
            $this->execute('behat_general::wait_until_exists', [$selector, 'css_element']);
            $js = "(function(){"
                . "var el = document.querySelector('{$selector}') || document.body;"
                . "if (el.focus) { try { el.focus(); } catch (e) {} }"
                . "el.dispatchEvent(new KeyboardEvent('keydown', "
                . "{key: '{$key}', bubbles: true, cancelable: true}));"
                . "})();";
        }
        $this->execute_script($js);
        $this->execute('behat_general::wait_until_the_page_is_ready');
    }

    /**
     * Assert the Unified Grader's current student changed / stayed put relative
     * to the one recorded by "I note the current Unified Grader student". The
     * "changed" case waits, since a real navigation loads asynchronously.
     *
     * @Then /^the Unified Grader student should be (?P<state>unchanged|changed)$/
     * @param string $state
     */
    public function the_unified_grader_student_should_be(string $state): void {
        $region = '[data-region="current-student-name"]';
        if ($state === 'changed') {
            $noted = addslashes($this->notedstudent ?? '');
            $this->getSession()->wait(
                self::get_timeout() * 1000,
                "((document.querySelector('{$region}')||{}).textContent||'').trim() !== '{$noted}'"
            );
            $now = $this->current_unifiedgrader_student();
            if ($now === $this->notedstudent) {
                throw new Exception(
                    "Expected the student to change from '{$this->notedstudent}' but it stayed"
                );
            }
            return;
        }
        $now = $this->current_unifiedgrader_student();
        if ($now !== $this->notedstudent) {
            throw new Exception(
                "Expected the student to stay '{$this->notedstudent}' but it became '{$now}'"
            );
        }
    }

    /**
     * Read the Unified Grader's current student fullname from the navigator's
     * current-student region (updated on every student switch).
     *
     * @return string
     */
    protected function current_unifiedgrader_student(): string {
        return trim((string) $this->evaluate_script(
            "(document.querySelector('[data-region=\"current-student-name\"]') || {}).textContent || ''"
        ));
    }

    /**
     * Seed a saved grade + overall feedback for a student on an assignment, so a
     * scenario can start from the "already graded, feedback shows as a card"
     * state without driving the (TinyMCE) first-save through the browser. Done
     * server-side via the adapter so it is deterministic.
     *
     * @Given /^"(?P<student>[^"]+)" has been graded with feedback "(?P<feedback>[^"]+)" on "(?P<activity>[^"]+)"$/
     * @param string $student Student username.
     * @param string $feedback Feedback text (wrapped in a paragraph).
     * @param string $activity Assignment name.
     */
    public function user_has_been_graded_with_feedback(string $student, string $feedback, string $activity): void {
        global $DB;
        $cm = $DB->get_record_sql(
            "SELECT cm.id
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module AND m.name = 'assign'
               JOIN {assign} a ON a.id = cm.instance
              WHERE a.name = :name",
            ['name' => $activity],
        );
        if (!$cm) {
            throw new Exception("No assignment named '{$activity}' found");
        }
        $studentrec = $DB->get_record('user', ['username' => $student], '*', MUST_EXIST);
        // Grade as the site admin (has the capability). The grader identity is
        // irrelevant to how the saved feedback renders for the teacher.
        \core\session\manager::set_user(get_admin());
        $adapter = \local_unifiedgrader\adapter\adapter_factory::create((int) $cm->id);
        $adapter->save_grade((int) $studentrec->id, 15.0, '<p>' . s($feedback) . '</p>');
    }

    /**
     * Wait until the overall feedback is shown as the read-only saved card
     * (display visible, editor hidden). The post-save collapse is async (AJAX
     * save + reactive re-render), so this spins rather than checking once.
     *
     * @Then /^the overall feedback is shown as a saved card$/
     */
    public function the_overall_feedback_is_shown_as_a_saved_card(): void {
        $js = "(function(){"
            . "var d=document.querySelector('[data-region=\"feedback-display\"]');"
            . "var e=document.querySelector('[data-region=\"feedback-editor-wrapper\"]');"
            . "return !!(d && !d.classList.contains('d-none') && e && e.classList.contains('d-none'));"
            . "})()";
        if (!$this->getSession()->wait(self::get_timeout() * 1000, $js)) {
            throw new Exception('The overall feedback did not collapse to the saved card.');
        }
    }

    /**
     * Wait until the overall feedback is open for editing (editor visible,
     * saved card hidden).
     *
     * @Then /^the overall feedback is open for editing$/
     */
    public function the_overall_feedback_is_open_for_editing(): void {
        $js = "(function(){"
            . "var d=document.querySelector('[data-region=\"feedback-display\"]');"
            . "var e=document.querySelector('[data-region=\"feedback-editor-wrapper\"]');"
            . "return !!(e && !e.classList.contains('d-none') && d && d.classList.contains('d-none'));"
            . "})()";
        if (!$this->getSession()->wait(self::get_timeout() * 1000, $js)) {
            throw new Exception('The overall feedback editor did not open.');
        }
    }

    /**
     * Seed N submitted-and-graded submission attempts (0-based) for a student
     * on an assignment, each with its own file.
     *
     * Written directly against the DB/assign API (bypassing the browser
     * submit-resubmit flow) rather than via the core "mod_assign > submissions"
     * generator, because that generator has no attempt-number control — it
     * always writes to the student's current attempt. It also deliberately
     * does NOT touch the activity's maxattempts / attemptreopenmethod
     * settings: a teacher can manually reopen a submission (attemptreopenmethod:
     * manual) regardless of the configured maxattempts cap, so real multi-attempt
     * data can exist even when maxattempts is 1 — exactly the scenario this
     * step exists to reproduce (see attempt_selector_ignores_maxattempts.feature).
     *
     * @Given /^"(?P<student>[^"]+)" has (?P<n>\d+) graded submission attempts on "(?P<activity>[^"]+)"$/
     * @param string $student Student username.
     * @param int $n Number of attempts to create (0-based attempt numbers 0..n-1).
     * @param string $activity Assignment name.
     */
    public function user_has_n_graded_attempts(string $student, int $n, string $activity): void {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        $cmrow = $DB->get_record_sql(
            "SELECT cm.id
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module AND m.name = 'assign'
               JOIN {assign} a ON a.id = cm.instance
              WHERE a.name = :name",
            ['name' => $activity],
        );
        if (!$cmrow) {
            throw new Exception("No assignment named '{$activity}' found");
        }
        $studentrec = $DB->get_record('user', ['username' => $student], '*', MUST_EXIST);

        [$course, $cm] = get_course_and_cm_from_cmid((int) $cmrow->id, 'assign');
        $context = context_module::instance($cm->id);
        $assign = new assign($context, $cm, $course);
        $fs = get_file_storage();

        $previous = null;
        for ($attempt = 0; $attempt < $n; $attempt++) {
            if ($attempt === 0) {
                $submission = $assign->get_user_submission($studentrec->id, true, 0);
            } else {
                // Directly insert the next attempt row — mirrors what a manual
                // reopen produces, without driving the actual reopen UI.
                $submission = clone $previous;
                unset($submission->id);
                $submission->attemptnumber = $attempt;
                $submission->timecreated = time();
                $submission->id = $DB->insert_record('assign_submission', $submission);
                $previous->latest = 0;
                $DB->update_record('assign_submission', $previous);
            }
            $submission->status = ASSIGN_SUBMISSION_STATUS_SUBMITTED;
            $submission->timemodified = time();
            $submission->latest = 1;
            $DB->update_record('assign_submission', $submission);

            $fs->create_file_from_string([
                'contextid' => $context->id,
                'component' => 'assignsubmission_file',
                'filearea' => 'submission_files',
                'itemid' => $submission->id,
                'filepath' => '/',
                'filename' => "attempt{$attempt}.pdf",
            ], '%PDF-1.4 test file content');

            $grade = $assign->get_user_grade($studentrec->id, true, $attempt);
            $grade->grade = 10 + $attempt;
            $grade->grader = get_admin()->id;
            $grade->timemodified = time() + $attempt;
            $DB->update_record('assign_grades', $grade);

            $previous = $submission;
        }
    }

    /**
     * Assert the overall grade input currently holds the given value.
     *
     * Core's "the field ... matches value" resolves its locator as a FIELD - name,
     * id, label or placeholder - so a CSS selector never matches and it reports the
     * field as missing even though the element is right there. Core's attribute step
     * is no better here: it reads the value ATTRIBUTE, which keeps the markup's
     * initial value and does not follow what the user typed.
     *
     * So read the live value off the node, which is what the scenarios mean.
     *
     * Example:
     *   Then the overall grade shows "18"
     *
     * @Then /^the overall grade shows "(?P<expected>[^"]*)"$/
     * @param string $expected Value the input should hold; empty string for cleared.
     */
    public function the_overall_grade_shows(string $expected): void {
        $this->execute('behat_general::wait_until_the_page_is_ready');
        $actual = (string) $this->find('css', '[data-action="grade-input"]')->getValue();
        if ($actual !== $expected) {
            throw new ExpectationException(
                "Expected the overall grade input to show '{$expected}', found '{$actual}'",
                $this->getSession()
            );
        }
    }

    /**
     * Type a grade and leave the field — which fires the panel's immediate
     * focus-out save — then correct it and leave the field again, so the second
     * save is requested while the first is still in flight.
     *
     * Driven as one synchronous script on purpose. The panel raises its
     * "save in flight" flag synchronously inside the first handler, before the
     * AJAX promise can settle, so the second request is *guaranteed* to land
     * mid-flight; spacing the two out as separate Behat steps would turn the
     * scenario into a race against the network.
     *
     * The focus-out save is used rather than the "Save feedback" button because
     * the button is disabled for the duration of a save, so a teacher cannot
     * reach that path — the reachable overlaps are this one, "Delete feedback",
     * and the save the navigator requests before switching student.
     *
     * @When /^I enter "(?P<first>[^"]*)" as the overall grade and correct it to "(?P<second>[^"]*)" before the save lands$/
     * @param string $first Grade typed first, whose save is still in flight.
     * @param string $second Correction typed during that round trip.
     */
    public function i_enter_and_correct_the_grade_before_the_save_lands(string $first, string $second): void {
        $this->execute('behat_general::wait_until_exists', ['[data-action="grade-input"]', 'css_element']);
        $a = addslashes($first);
        $b = addslashes($second);
        $js = "(function(){"
            . "var input = document.querySelector('[data-action=\"grade-input\"]');"
            . "function typeandleave(v) {"
            . "input.value = v;"
            . "input.dispatchEvent(new Event('input', {bubbles: true}));"
            . "input.dispatchEvent(new Event('focusout', {bubbles: true}));"
            . "}"
            . "typeandleave('{$a}');"
            . "typeandleave('{$b}');"
            . "})();";
        $this->execute_script($js);
    }

    /**
     * Assert the grade the server actually stored for a student, waiting for it.
     *
     * Reads the database rather than the page, because the point of the
     * scenarios that use it is whether a save reached the server at all — a
     * reload would race the very round trip under test (core/ajax registers no
     * pending-JS marker, so Behat's page-ready wait does not cover it).
     *
     * @Then /^the saved grade for "(?P<student>[^"]+)" on "(?P<activity>[^"]+)" is "(?P<expected>[^"]*)"$/
     * @param string $student Student username.
     * @param string $activity Assignment name.
     * @param string $expected Expected grade; empty string for "no grade".
     */
    public function the_saved_grade_for_user_is(string $student, string $activity, string $expected): void {
        global $DB;
        $assign = $DB->get_record_sql(
            "SELECT a.id
               FROM {assign} a
              WHERE a.name = :name",
            ['name' => $activity],
        );
        if (!$assign) {
            throw new Exception("No assignment named '{$activity}' found");
        }
        $studentrec = $DB->get_record('user', ['username' => $student], '*', MUST_EXIST);
        $wanted = $expected === '' ? -1.0 : (float) $expected;

        $this->spin(
            function () use ($assign, $studentrec, $wanted, $expected) {
                global $DB;
                $stored = $DB->get_field_sql(
                    "SELECT g.grade
                       FROM {assign_grades} g
                      WHERE g.assignment = :assignment AND g.userid = :userid
                   ORDER BY g.attemptnumber DESC",
                    ['assignment' => $assign->id, 'userid' => $studentrec->id],
                    IGNORE_MULTIPLE,
                );
                $actual = $stored === false ? -1.0 : (float) $stored;
                if (abs($actual - $wanted) > 0.0001) {
                    throw new ExpectationException(
                        "Expected the stored grade to be '{$expected}', found '{$actual}'",
                        $this->getSession()
                    );
                }
                return true;
            },
            [],
            self::get_timeout()
        );
    }

    /**
     * Set one admin setting, named the way get_config() reads it back.
     *
     * Core ships "the following config values are set as admin:" for a table of
     * settings, which is a heavy shape for the single flag most scenarios need in
     * their Background. This is the one-line form, and it takes the plugin-qualified
     * name so the feature file reads the same way the setting is written in code.
     *
     * A bare name with no slash sets a core setting, matching set_config().
     *
     * Example:
     *   Given the "local_unifiedgrader/enable_assign" admin setting is "1"
     *
     * @Given /^the "(?P<setting>[^"]+)" admin setting is "(?P<value>[^"]*)"$/
     * @param string $setting Setting name, optionally qualified as "plugin/name".
     * @param string $value Value to store.
     */
    public function the_admin_setting_is(string $setting, string $value): void {
        if (str_contains($setting, '/')) {
            [$plugin, $name] = explode('/', $setting, 2);
            set_config($name, $value, $plugin);
            return;
        }
        set_config($setting, $value);
    }
}
