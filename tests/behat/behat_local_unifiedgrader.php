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

// NOTE: no MOODLE_INTERNAL test here, this file may be required by behat before including /config.php.
//
// This is not a style preference. Behat loads every registered context class
// while it builds the suite list, which happens before Moodle's config.php has
// run — so MOODLE_INTERNAL is not defined yet and the usual guard fires. Because
// die() here takes down the whole behat process with no message and exit code 0,
// a guard in this one file silently stopped every feature in the plugin from
// running, and made the CI step report success without executing anything.
// Core's own context classes carry this comment in place of the guard for the
// same reason.

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

use Behat\Gherkin\Node\TableNode;
use Behat\Mink\Exception\ExpectationException;

/**
 * Unified Grader steps.
 */
class behat_local_unifiedgrader extends behat_base {
    /** @var string Message of the last save refusal a step closed. */
    private string $lastrefusal = '';

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
     * Wait for a rating-graded forum's per-post rating rows to be rendered.
     *
     * The grade-input boundary the marking panel uses is no help here: on a
     * rated forum that field exists but is hidden, so it is present long before
     * the ratings arrive. A rendered row is the real signal, since it only
     * appears once get_post_ratings has come back and been drawn.
     *
     * Example:
     *   And the post ratings list has loaded
     *
     * @Given /^the post ratings list has loaded$/
     */
    public function the_post_ratings_list_has_loaded(): void {
        $this->execute('behat_general::wait_until_the_page_is_ready');
        $this->execute(
            'behat_general::wait_until_exists',
            ['[data-region="post-rating-row"]', 'css_element'],
        );
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
        // Blur the input itself rather than clicking somewhere else to steal
        // focus. This used to click the middle of [data-region="marking-content"]
        // — and the middle of that region is the Marking guide card header,
        // which is a Bootstrap collapse toggle, so entering a grade quietly
        // collapsed the guide and every later step that touched a criterion
        // score failed with "element not interactable". blur() fires the same
        // real focusout the listener is bound to, without depending on what
        // happens to be under the centre of the panel.
        $this->execute_script(
            "(function(){var i=document.querySelector('[data-action=\"grade-input\"]');"
            . "if (i) { i.blur(); }})();"
        );
        $this->execute('behat_general::wait_until_the_page_is_ready');
    }

    /**
     * Attach a marking guide to an assignment, with the given criteria.
     *
     * Core has no Behat data generator for grading forms, but it does ship a
     * PHPUnit one — gradingform_guide_generator::create_instance() — and that
     * generator already resolves its dependencies through
     * testing_util::get_data_generator(), so it works unchanged from here.
     * Hand-rolling a definition and calling update_definition() directly would
     * duplicate a helper core already maintains.
     *
     * The generator refuses to run as user 0; Behat's own before-scenario hook
     * has already switched to admin by the time a Given runs, so that holds.
     *
     * Example:
     *   Given a marking guide is attached to "Essay 1" with criteria:
     *     | shortname     | maxscore |
     *     | Argumentation | 10       |
     *
     * @Given /^a marking guide is attached to "(?P<activity>[^"]+)" with criteria:$/
     * @param string $activity Assignment name.
     * @param TableNode $criteria Rows of shortname + maxscore.
     */
    public function a_marking_guide_is_attached_to(string $activity, TableNode $criteria): void {
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

        $definition = [];
        foreach ($criteria->getHash() as $row) {
            $definition[$row['shortname']] = [
                // The panel keys its criterion inputs off the visible heading,
                // so description and markers mirror the shortname rather than
                // inventing prose the scenarios would then have to know about.
                'description' => $row['shortname'],
                'descriptionmarkers' => $row['shortname'],
                'maxscore' => (float) $row['maxscore'],
            ];
        }

        $generator = \testing_util::get_data_generator()->get_plugin_generator('gradingform_guide');
        $generator->create_instance(
            \context_module::instance((int) $cm->id),
            'mod_assign',
            'submissions',
            $activity . ' guide',
            '',
            $definition,
        );
    }

    /**
     * Attach a rubric to an assignment, with the given criteria and levels.
     *
     * Same approach as the marking guide step above, through core's PHPUnit
     * generator gradingform_rubric_generator::create_instance(). Levels are
     * "definition:score" pairs, comma separated.
     *
     * Example:
     *   Given a rubric is attached to "Essay 1" with criteria:
     *     | criterion | levels                         |
     *     | Argument  | Weak:0, Sound:5, Compelling:10 |
     *
     * @Given /^a rubric is attached to "(?P<activity>[^"]+)" with criteria:$/
     * @param string $activity Assignment name.
     * @param TableNode $criteria Rows of criterion + levels.
     */
    public function a_rubric_is_attached_to(string $activity, TableNode $criteria): void {
        $definition = [];
        foreach ($criteria->getHash() as $row) {
            $levels = [];
            foreach (explode(',', $row['levels']) as $pair) {
                [$name, $score] = array_map('trim', explode(':', $pair, 2));
                $levels[$name] = (int) $score;
            }
            $definition[$row['criterion']] = $levels;
        }

        $generator = \testing_util::get_data_generator()->get_plugin_generator('gradingform_rubric');
        $generator->create_instance(
            \context_module::instance($this->assign_cmid($activity)),
            'mod_assign',
            'submissions',
            $activity . ' rubric',
            '',
            $definition,
        );
    }

    /**
     * Assert the rubric or marking guide total badge shows the given text.
     *
     * Scoped to the badge on purpose: every rubric level button also prints its
     * own score ("10 pts"), so a page-wide "I should see" would pass on a level.
     *
     * @Then /^the rubric total shows "(?P<expected>[^"]*)"$/
     * @param string $expected Badge text.
     */
    public function the_rubric_total_shows(string $expected): void {
        $this->execute('behat_general::wait_until_the_page_is_ready');
        $actual = trim((string) $this->find('css', '[data-region="rubric-total"]')->getText());
        if ($actual !== $expected) {
            throw new ExpectationException(
                "Expected the rubric total to show '{$expected}', found '{$actual}'",
                $this->getSession()
            );
        }
    }

    /**
     * Override or lock a student's gradebook grade for an assignment.
     *
     * @Given /^the gradebook grade for "(?P<student>[^"]+)" on "(?P<activity>[^"]+)" is (?P<block>overridden|locked)$/
     * @param string $student Student username.
     * @param string $activity Assignment name.
     * @param string $block "overridden" or "locked".
     */
    public function the_gradebook_grade_is_blocked(string $student, string $activity, string $block): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/gradelib.php');

        $cmid = $this->assign_cmid($activity);
        $assignid = (int) $DB->get_field('course_modules', 'instance', ['id' => $cmid], MUST_EXIST);
        $studentid = (int) $DB->get_field('user', 'id', ['username' => $student], MUST_EXIST);
        $item = \grade_item::fetch([
            'itemtype' => 'mod',
            'itemmodule' => 'assign',
            'iteminstance' => $assignid,
            'itemnumber' => 0,
        ]);
        $grade = $item ? \grade_grade::fetch(['itemid' => $item->id, 'userid' => $studentid]) : false;
        if (!$grade) {
            throw new Exception("'{$student}' has no gradebook grade on '{$activity}' to block");
        }
        if ($block === 'overridden') {
            $grade->set_overridden(true, false);
        } else {
            $grade->locked = time();
            $grade->update();
        }
    }

    /**
     * Turn an assignment's Feedback comments off, typically after the grader has
     * loaded with them on, which is the stale page the server's refusal is for.
     *
     * @Given /^feedback comments are disabled on "(?P<activity>[^"]+)"$/
     * @param string $activity Assignment name.
     */
    public function feedback_comments_are_disabled_on(string $activity): void {
        global $DB;
        $assignid = (int) $DB->get_field('course_modules', 'instance', ['id' => $this->assign_cmid($activity)], MUST_EXIST);
        $DB->set_field('assign_plugin_config', 'value', '0', [
            'assignment' => $assignid,
            'plugin' => 'comments',
            'subtype' => 'assignfeedback',
            'name' => 'enabled',
        ]);
    }

    /**
     * Put text into the overall feedback editor the way typing would register it.
     *
     * Sets the TinyMCE content and dispatches the editor's input event, which is
     * what the marking panel listens to before marking feedback unsaved. Repeated
     * until the panel has registered the edit: its editor listener is attached on a
     * timer after the editor exists, so a single attempt can land before anything
     * listens, and the scenario would then fail on its precondition.
     *
     * @When /^I type "(?P<text>[^"]*)" as the overall feedback$/
     * @param string $text Feedback text (wrapped in a paragraph).
     */
    public function i_type_as_the_overall_feedback(string $text): void {
        $this->execute('behat_general::wait_until_the_page_is_ready');
        $html = json_encode('<p>' . s($text) . '</p>');
        $js = "(function(){"
            . "var t=document.querySelector('[data-action=\"feedback-input\"]');"
            . "var e=t && window.tinymce ? window.tinymce.get(t.id) : null;"
            . "if (!e) { return false; }"
            . "e.setContent({$html});"
            . "(e.dispatch || e.fire).call(e, 'input');"
            . "try { return require('local_unifiedgrader/dirty_tracker').isDirty('feedback'); }"
            . " catch (x) { return false; }"
            . "})()";
        if (!$this->getSession()->wait(self::get_timeout() * 1000, $js)) {
            throw new Exception('The marking panel never registered the overall feedback as unsaved.');
        }
    }

    /**
     * Type a grade and leave the box, where the server is expected to refuse the
     * save, then close the refusal dialogue.
     *
     * @When /^I enter "(?P<value>[^"]*)" as the overall grade and close the refusal$/
     * @param string $value Grade to type.
     */
    public function i_enter_as_the_overall_grade_and_close_the_refusal(string $value): void {
        $this->find('css', '[data-action="grade-input"]')->setValue($value);
        $this->execute_script(
            "(function(){var i=document.querySelector('[data-action=\"grade-input\"]');"
            . "if (i) { i.blur(); }})();"
        );
        $this->close_save_refusal();
    }

    /**
     * Type a grade the server is expected to refuse, leave the box so it is sent,
     * then type another grade while that save is still in flight, and close the
     * refusal dialogue.
     *
     * One synchronous script, like the concurrent-save steps: the panel raises its
     * in-flight flag before the AJAX promise can settle, so the second value is
     * guaranteed to land during the round trip. The box is not left a second time,
     * so no second save is requested.
     *
     * @When /^I enter "(?P<first>[^"]*)" as the overall grade, type "(?P<second>[^"]*)" before the refusal lands, and close it$/
     * @param string $first Grade that is sent and refused.
     * @param string $second Grade typed during the round trip.
     */
    public function i_enter_type_before_the_refusal_lands_and_close_it(string $first, string $second): void {
        $this->execute('behat_general::wait_until_exists', ['[data-action="grade-input"]', 'css_element']);
        $a = json_encode($first);
        $b = json_encode($second);
        $this->execute_script(
            "(function(){var i=document.querySelector('[data-action=\"grade-input\"]');"
            . "function type(v) { i.value = v; i.dispatchEvent(new Event('input', {bubbles: true})); }"
            . "type({$a});"
            . "i.dispatchEvent(new Event('focusout', {bubbles: true}));"
            . "type({$b});"
            . "})();"
        );
        $this->close_save_refusal();
    }

    /**
     * Press "Save feedback", where the server is expected to refuse the save, then
     * close the refusal dialogue.
     *
     * @When /^I save the grade and close the refusal$/
     */
    public function i_save_the_grade_and_close_the_refusal(): void {
        $this->execute_script(
            "(function(){var b=document.querySelector('[data-action=\"save-grade\"]');"
            . "if (b) { b.click(); }})();"
        );
        $this->close_save_refusal();
    }

    /**
     * Assert whether the marking panel still counts grade or feedback as unsaved.
     *
     * Reads the panel's own dirty tracker, which is what drives the leave-page
     * warning, so a refusal marked as saved is visible here and nowhere else on
     * the page.
     *
     * @Then /^the Unified Grader (?P<state>has|has no) unsaved (?P<type>grade|feedback) changes$/
     * @param string $state "has" or "has no".
     * @param string $type "grade" or "feedback".
     */
    public function the_unified_grader_has_unsaved_changes(string $state, string $type): void {
        $this->execute('behat_general::wait_until_the_page_is_ready');
        $dirty = (bool) $this->evaluate_script(
            "require('local_unifiedgrader/dirty_tracker').isDirty('{$type}')"
        );
        if ($dirty !== ($state === 'has')) {
            throw new ExpectationException(
                $dirty
                    ? "Expected no unsaved {$type} changes, but the panel still counts them as unsaved"
                    : "Expected unsaved {$type} changes, but the panel counts them as saved",
                $this->getSession()
            );
        }
    }

    /**
     * Assert what the last save refusal a step closed said.
     *
     * The refusal steps close the dialogue before they end, so its message is kept
     * for this step to read.
     *
     * @Then /^the refusal said "(?P<fragment>[^"]+)"$/
     * @param string $fragment Text the message must contain.
     */
    public function the_refusal_said(string $fragment): void {
        if (strpos($this->lastrefusal, $fragment) === false) {
            throw new ExpectationException(
                "Expected the refusal to say '{$fragment}', but it said '{$this->lastrefusal}'",
                $this->getSession()
            );
        }
    }

    /**
     * Wait for the save refusal dialogue and close it, inside the step that caused it.
     *
     * Core Behat fails any step that leaves a [data-rel="fatalerror"] element on the
     * page, and the exception dialogue a refused AJAX save raises carries one. So a
     * scenario can only exercise a refusal if the same step closes the dialogue and
     * waits for it to go: it destroys itself a second after being hidden. The wait
     * for it to appear doubles as the proof that the save really was refused.
     */
    private function close_save_refusal(): void {
        $appeared = $this->getSession()->wait(
            self::get_extended_timeout() * 1000,
            "!!document.querySelector('[data-rel=\"fatalerror\"]')"
        );
        if (!$appeared) {
            throw new ExpectationException('The save was not refused: no error dialogue appeared.', $this->getSession());
        }
        $this->lastrefusal = (string) $this->evaluate_script(
            "(function(){var m=document.querySelector('.moodle-dialogue-exception .moodle-exception-message');"
            . "return m ? m.textContent : '';})()"
        );
        $this->execute_script(
            "(function(){var b=document.querySelector('.moodle-dialogue-exception .closebutton');"
            . "if (b) { b.click(); }})();"
        );
        $gone = $this->getSession()->wait(
            self::get_extended_timeout() * 1000,
            "!document.querySelector('[data-rel=\"fatalerror\"]')"
        );
        if (!$gone) {
            throw new ExpectationException('The save refusal dialogue did not close.', $this->getSession());
        }
    }

    /**
     * Resolve an assignment's course module id from its name.
     *
     * @param string $activity Assignment name.
     * @return int Course module id.
     */
    private function assign_cmid(string $activity): int {
        global $DB;
        $cmid = $DB->get_field_sql(
            "SELECT cm.id
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module AND m.name = 'assign'
               JOIN {assign} a ON a.id = cm.instance
              WHERE a.name = :name",
            ['name' => $activity],
        );
        if (!$cmid) {
            throw new Exception("No assignment named '{$activity}' found");
        }
        return (int) $cmid;
    }

    /**
     * Hide an assignment's grades from the grader's post-grades menu.
     *
     * Accepts the browser confirmation for the teacher, then waits for the server to
     * hide the grade item and for the menu to re-enable, so the next step runs once
     * the panel has handled the end of the change.
     *
     * @When /^I hide the grades of "(?P<activity>[^"]+)"$/
     * @param string $activity Assignment name.
     */
    public function i_hide_the_grades_of(string $activity): void {
        global $DB;
        $assignid = (int) $DB->get_field('course_modules', 'instance', ['id' => $this->assign_cmid($activity)], MUST_EXIST);

        $this->execute_script(
            "(function(){window.confirm = function() { return true; };"
            . "var b=document.querySelector('[data-action=\"hide-grades\"]');"
            . "if (b) { b.click(); }})();"
        );
        $this->spin(
            function () use ($assignid) {
                global $DB;
                $hidden = $DB->get_field('grade_items', 'hidden', [
                    'itemtype' => 'mod',
                    'itemmodule' => 'assign',
                    'iteminstance' => $assignid,
                    'itemnumber' => 0,
                ]);
                if ((int) $hidden !== 1) {
                    throw new ExpectationException('The grades were not hidden', $this->getSession());
                }
                return true;
            },
            [],
            self::get_extended_timeout()
        );
        $settled = $this->getSession()->wait(
            self::get_timeout() * 1000,
            "(function(){var b=document.querySelector('[data-action=\"post-grades-status\"]');"
                . "return !!b && !b.disabled;})()"
        );
        if (!$settled) {
            throw new ExpectationException('The post-grades menu did not settle.', $this->getSession());
        }
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

        // Re-find on every attempt instead of typing into a node located
        // earlier. _renderAdvancedGrading rebuilds the criterion DOM on the
        // render that follows a save, so a node found before that lands is
        // detached by the time the keystrokes arrive and the driver reports it
        // as not interactable. Setting a score right after a save-triggering
        // step is an ordinary thing for a scenario to do, so the step absorbs
        // the rebuild rather than making every caller sequence around it.
        $this->execute('behat_general::wait_until_the_page_is_ready');
        $this->spin(
            function () use ($xpath, $score) {
                $input = $this->find('xpath', $xpath);
                $input->setValue($score);
                // Confirm the value survived. _renderGuide repopulates every
                // criterion input from server fill data on the render that
                // follows a save, so a score typed while that is in flight is
                // silently overwritten a moment later - and the scenario then
                // asserts against a panel that never saw the edit. Retrying
                // until the value sticks is what makes this step mean what it
                // says; without it a scenario setting a score after a save
                // passes while proving nothing.
                if ((string) $this->find('xpath', $xpath)->getValue() !== $score) {
                    throw new ExpectationException(
                        "The criterion score did not stick as '{$score}'",
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
     * Extended timeout, not the standard one: this step is used after clicking
     * save, so it waits on a full server round-trip rather than a DOM tick. The
     * standard 6s was enough locally and on most CI jobs but timed out on a
     * loaded runner, failing the scenario three times in a row in one job while
     * the same commit passed everywhere else. The condition asserted is
     * unchanged — only the patience for it is.
     *
     * On failure it reports what it actually saw, because the DOM state it
     * waits for has more than one cause. _toggleFeedbackMode only shows the
     * card when there is feedback AND the panel is not in editing mode, so
     * "no card" is produced identically by an editing flag that stayed set and
     * by a save that wiped the feedback. Naming one of them in the message
     * would be a guess; dumping the observed state lets whoever reads the
     * failure tell them apart without a faildump. (Diagnosing exactly this
     * cost a full instrumentation cycle when the v2.8.5 collapse bug was
     * being chased.)
     *
     * @Then /^the overall feedback is shown as a saved card$/
     */
    public function the_overall_feedback_is_shown_as_a_saved_card(): void {
        $js = "(function(){"
            . "var d=document.querySelector('[data-region=\"feedback-display\"]');"
            . "var e=document.querySelector('[data-region=\"feedback-editor-wrapper\"]');"
            . "return !!(d && !d.classList.contains('d-none') && e && e.classList.contains('d-none'));"
            . "})()";
        if ($this->getSession()->wait(self::get_extended_timeout() * 1000, $js)) {
            return;
        }
        $observed = (string) $this->evaluate_script(
            "(function(){"
            . "function seen(sel){var n=document.querySelector(sel);"
            . "return n ? (n.classList.contains('d-none') ? 'hidden' : 'visible') : 'absent';}"
            . "function len(sel){var n=document.querySelector(sel);"
            . "return n ? (n.textContent || '').trim().length : -1;}"
            . "var f=document.querySelector('.tox-edit-area__iframe');"
            . "var editorchars=-1;"
            . "try { editorchars = f && f.contentDocument"
            . " ? (f.contentDocument.body.textContent || '').trim().length : -1; } catch (e) {}"
            . "return 'card=' + seen('[data-region=\"feedback-display\"]')"
            . " + ', editor=' + seen('[data-region=\"feedback-editor-wrapper\"]')"
            . " + ', card text=' + len('[data-region=\"feedback-display-content\"]') + ' chars'"
            . " + ', editor text=' + editorchars + ' chars';"
            . "})()"
        );
        throw new ExpectationException(
            'The overall feedback did not collapse to the saved card. Observed: ' . $observed
                . '. An editor still holding text points at the editing flag; an empty one points at the'
                . ' save having wiped the feedback.',
            $this->getSession()
        );
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
     * Score a marking-guide criterion and save, then change the score and leave
     * the guide while that save is still in flight.
     *
     * The second change reaches the server only through the panel's debounced
     * autosave, which is the path every rubric level click and every ranged
     * rubric slider takes. It used to return early whenever a save was in
     * flight, and the finishing save then marked the unsent change clean.
     *
     * One synchronous script for the same reason as the grade-box variant
     * above: the "Save feedback" click raises the in-flight flag before the
     * AJAX promise can settle, so the change is guaranteed to land mid-flight.
     * Focus is handed to the page body so the guide's focus-out handler takes
     * its immediate branch rather than deferring.
     *
     * @When /^I score "(?P<criterion>[^"]+)" "(?P<first>[^"]*)", save, and change it to "(?P<second>[^"]*)" before the save lands$/
     * @param string $criterion Criterion shortname, as shown in its heading.
     * @param string $first Score saved first.
     * @param string $second Score set while that save is in flight.
     */
    public function i_score_save_and_change_before_the_save_lands(string $criterion, string $first, string $second): void {
        $this->execute(
            'behat_general::wait_until_exists',
            ['input[data-criterionid]:not([data-levelid])', 'css_element'],
        );
        $this->execute('behat_general::wait_until_the_page_is_ready');
        $name = json_encode($criterion);
        $a = json_encode($first);
        $b = json_encode($second);
        $js = "(function(){"
            . "var input = Array.from(document.querySelectorAll('input[data-criterionid]:not([data-levelid])'))"
            . ".find(function(el) {"
            . "var row = el.closest('.border-bottom');"
            . "var heading = row && row.querySelector('.fw-bold');"
            . "return heading && heading.textContent.trim() === {$name};"
            . "});"
            . "function score(v) {"
            . "input.focus();"
            . "input.value = v;"
            . "input.dispatchEvent(new Event('input', {bubbles: true}));"
            . "input.dispatchEvent(new Event('change', {bubbles: true}));"
            . "}"
            . "score({$a});"
            . "document.querySelector('[data-action=\"save-grade\"]').click();"
            . "score({$b});"
            . "input.dispatchEvent(new FocusEvent('focusout', {bubbles: true, relatedTarget: document.body}));"
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
     * Assert whether the overall grade box accepts typing.
     *
     * Reads the live readOnly property rather than the readonly attribute: the
     * panel sets the property during render, and the template ships no
     * attribute, so an attribute check would report every activity as editable.
     *
     * @Then /^the overall grade box is (?P<state>read\-only|editable)$/
     * @param string $state Expected state.
     */
    public function the_overall_grade_box_is(string $state): void {
        $this->execute('behat_general::wait_until_exists', ['[data-action="grade-input"]', 'css_element']);
        $actual = (bool) $this->evaluate_script(
            "(document.querySelector('[data-action=\"grade-input\"]') || {}).readOnly === true"
        );
        $expected = $state === 'read-only';
        if ($actual !== $expected) {
            throw new ExpectationException(
                'Expected the overall grade box to be ' . $state
                    . ', found it ' . ($actual ? 'read-only' : 'editable'),
                $this->getSession()
            );
        }
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
