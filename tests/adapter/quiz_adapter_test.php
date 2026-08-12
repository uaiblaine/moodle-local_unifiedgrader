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

namespace local_unifiedgrader\adapter;

/**
 * Tests for the quiz_adapter class.
 *
 * @package    local_unifiedgrader
 * @category   test
 * @copyright  2026 South African Theological Seminary (mathieu@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_unifiedgrader\adapter\quiz_adapter
 */
final class quiz_adapter_test extends \advanced_testcase {
    /**
     * Helper: create a quiz scenario and return the adapter.
     *
     * @param array $options Scenario options.
     * @return object{adapter: quiz_adapter, scenario: \stdClass}
     */
    private function create_scenario(array $options = []): object {
        $plugingen = $this->getDataGenerator()->get_plugin_generator('local_unifiedgrader');
        $scenario = $plugingen->create_grading_scenario('quiz', $options);
        $this->setUser($scenario->teacher);
        $adapter = adapter_factory::create($scenario->cm->id);
        return (object) ['adapter' => $adapter, 'scenario' => $scenario];
    }

    /**
     * Test get_activity_info returns correct basic info.
     */
    public function test_get_activity_info_basic(): void {
        $this->resetAfterTest();

        $s = $this->create_scenario();
        $info = $s->adapter->get_activity_info();

        $this->assertEquals($s->scenario->cm->id, $info['id']);
        $this->assertEquals('quiz', $info['type']);
        $this->assertArrayHasKey('name', $info);
        $this->assertArrayHasKey('duedate', $info);
        $this->assertArrayHasKey('maxgrade', $info);
        $this->assertArrayHasKey('gradingmethod', $info);
    }

    /**
     * Test get_activity_info includes duedate plugin flags.
     */
    public function test_get_activity_info_duedate_plugin_flags(): void {
        $this->resetAfterTest();

        $s = $this->create_scenario();
        $info = $s->adapter->get_activity_info();

        $this->assertArrayHasKey('hasduedateplugin', $info);
        $this->assertArrayHasKey('canmanageextensions', $info);
        // The flag depends on whether quizaccess_duedate is installed.
        $this->assertIsBool($info['hasduedateplugin']);
    }

    /**
     * Test get_participants returns students with no attempts as nosubmission.
     */
    public function test_get_participants_no_attempts(): void {
        $this->resetAfterTest();

        $s = $this->create_scenario();
        $participants = $s->adapter->get_participants();

        $this->assertCount(3, $participants);
        foreach ($participants as $p) {
            $this->assertEquals('nosubmission', $p['status']);
        }
    }

    /**
     * Test get_submission_data with no attempt.
     */
    public function test_get_submission_data_no_attempt(): void {
        $this->resetAfterTest();

        $s = $this->create_scenario();
        $data = $s->adapter->get_submission_data($s->scenario->students[0]->id);

        $this->assertEquals($s->scenario->students[0]->id, $data['userid']);
        $this->assertEquals('nosubmission', $data['status']);
    }

    /**
     * Test get_grade_data with no grade.
     */
    public function test_get_grade_data_no_grade(): void {
        $this->resetAfterTest();

        $s = $this->create_scenario();
        $data = $s->adapter->get_grade_data($s->scenario->students[0]->id);

        $this->assertNull($data['grade']);
    }

    /**
     * Test get_type returns 'quiz'.
     */
    public function test_get_type(): void {
        $this->resetAfterTest();

        $s = $this->create_scenario();
        $this->assertEquals('quiz', $s->adapter->get_type());
    }

    /**
     * Test get_effective_duedate returns timeclose when no override.
     */
    public function test_get_effective_duedate_no_override(): void {
        $this->resetAfterTest();

        $timeclose = time() + DAYSECS * 7;
        $s = $this->create_scenario(['modparams' => ['timeclose' => $timeclose]]);

        $effective = $s->adapter->get_effective_duedate($s->scenario->students[0]->id);

        if (class_exists('\quizaccess_duedate\override_manager')) {
            // Duedate plugin returns its own effective date (may differ from native timeclose).
            $this->assertIsInt($effective);
        } else {
            $this->assertEquals($timeclose, $effective);
        }
    }

    /**
     * Test get_effective_duedate with native quiz override.
     */
    public function test_get_effective_duedate_native_override(): void {
        global $DB;
        $this->resetAfterTest();

        if (class_exists('\quizaccess_duedate\override_manager')) {
            // When duedate plugin is installed, native overrides are bypassed.
            // Test the duedate override path instead.
            $s = $this->create_scenario();
            $overridetime = time() + DAYSECS * 14;

            // The duedate plugin requires an instance-level settings record.
            $DB->insert_record('quizaccess_duedate_instances', (object) [
                'quizid' => $s->scenario->activity->id,
                'duedate' => time() + DAYSECS * 7,
                'timemodified' => time(),
            ]);
            \quizaccess_duedate\override_manager::save_override((object) [
                'quizid' => $s->scenario->activity->id,
                'userid' => $s->scenario->students[0]->id,
                'duedate' => $overridetime,
            ]);
            $effective = $s->adapter->get_effective_duedate($s->scenario->students[0]->id);
            $this->assertEquals($overridetime, $effective);
            return;
        }

        $s = $this->create_scenario();
        $overridetime = time() + DAYSECS * 14;
        $DB->insert_record('quiz_overrides', (object) [
            'quiz' => $s->scenario->activity->id,
            'userid' => $s->scenario->students[0]->id,
            'timeclose' => $overridetime,
        ]);
        $effective = $s->adapter->get_effective_duedate($s->scenario->students[0]->id);
        $this->assertEquals($overridetime, $effective);
    }

    /**
     * Test are_grades_posted defaults to true.
     */
    public function test_are_grades_posted_default(): void {
        $this->resetAfterTest();

        $s = $this->create_scenario();
        $this->assertTrue($s->adapter->are_grades_posted());
    }

    /**
     * Test set_grades_posted hides grades.
     */
    public function test_set_grades_posted(): void {
        $this->resetAfterTest();

        $s = $this->create_scenario();
        $s->adapter->set_grades_posted(1);
        $this->assertFalse($s->adapter->are_grades_posted());
    }

    /**
     * Test set_grades_posted flips ONLY marks + maxmarks + overallfeedback
     * for the LATER_WHILE_OPEN and AFTER_CLOSE timeframes, and leaves
     * every other review option exactly as configured. Bug #13: teachers
     * who deliberately disabled reviewattempt to hide the attempt
     * contents would see UG silently revealing it; this test pins down
     * that UG never touches those bits.
     */
    public function test_set_grades_posted_only_touches_marks_maxmarks_overallfeedback(): void {
        global $DB;
        $this->resetAfterTest();

        $s = $this->create_scenario();
        $quizid = $s->scenario->activity->id;

        // Seed a "marks visible, attempt hidden" baseline — the exact case
        // bug #13 reports. reviewattempt = 0 everywhere; everything else
        // also off so we can detect any stray flip.
        $DB->update_record('quiz', (object) [
            'id' => $quizid,
            'reviewattempt'          => 0,
            'reviewcorrectness'      => 0,
            'reviewmarks'            => 0,
            'reviewmaxmarks'         => 0,
            'reviewspecificfeedback' => 0,
            'reviewgeneralfeedback'  => 0,
            'reviewrightanswer'      => 0,
            'reviewoverallfeedback'  => 0,
        ]);

        $s->adapter->set_grades_posted(0);
        $after = $DB->get_record('quiz', ['id' => $quizid], '*', MUST_EXIST);

        // The mask UG should flip: LATER_WHILE_OPEN | AFTER_CLOSE = 0x110.
        $expectedmask = 0x00100 | 0x00010;

        // The three review options UG flips on post — each should now
        // have the LATER_WHILE_OPEN + AFTER_CLOSE bits set.
        $this->assertSame($expectedmask, (int) $after->reviewmarks);
        $this->assertSame($expectedmask, (int) $after->reviewmaxmarks);
        $this->assertSame($expectedmask, (int) $after->reviewoverallfeedback);

        // The whole point of bug #13: these must remain at zero. UG
        // must never touch reviewattempt or its dependent fields.
        $this->assertSame(0, (int) $after->reviewattempt);
        $this->assertSame(0, (int) $after->reviewcorrectness);
        $this->assertSame(0, (int) $after->reviewspecificfeedback);
        $this->assertSame(0, (int) $after->reviewgeneralfeedback);
        $this->assertSame(0, (int) $after->reviewrightanswer);

        // Unposting should clear the same three back to zero without
        // disturbing the others (still zero in this scenario).
        $s->adapter->set_grades_posted(1);
        $after = $DB->get_record('quiz', ['id' => $quizid], '*', MUST_EXIST);
        $this->assertSame(0, (int) $after->reviewmarks);
        $this->assertSame(0, (int) $after->reviewmaxmarks);
        $this->assertSame(0, (int) $after->reviewoverallfeedback);
        $this->assertSame(0, (int) $after->reviewattempt);
    }

    /**
     * Test that pre-existing bits on untouched review options survive a
     * post / unpost cycle. A teacher who enabled "Right answer" only for
     * AFTER_CLOSE should still have that bit set after UG flips Post
     * grades — UG must not collateral-clear what it never set.
     */
    public function test_set_grades_posted_preserves_unrelated_review_bits(): void {
        global $DB;
        $this->resetAfterTest();

        $s = $this->create_scenario();
        $quizid = $s->scenario->activity->id;

        // Teacher's manual config: reviewrightanswer on for AFTER_CLOSE,
        // reviewspecificfeedback on for LATER_WHILE_OPEN. Marks etc. off.
        $afterclose = 0x00010;
        $laterwhileopen = 0x00100;
        $DB->update_record('quiz', (object) [
            'id' => $quizid,
            'reviewmarks'            => 0,
            'reviewmaxmarks'         => 0,
            'reviewoverallfeedback'  => 0,
            'reviewrightanswer'      => $afterclose,
            'reviewspecificfeedback' => $laterwhileopen,
        ]);

        // Post grades — teacher's reviewrightanswer + reviewspecificfeedback bits must survive.
        $s->adapter->set_grades_posted(0);
        $after = $DB->get_record('quiz', ['id' => $quizid], '*', MUST_EXIST);
        $this->assertSame($afterclose, (int) $after->reviewrightanswer);
        $this->assertSame($laterwhileopen, (int) $after->reviewspecificfeedback);

        // Unpost grades — same survival expectation.
        $s->adapter->set_grades_posted(1);
        $after = $DB->get_record('quiz', ['id' => $quizid], '*', MUST_EXIST);
        $this->assertSame($afterclose, (int) $after->reviewrightanswer);
        $this->assertSame($laterwhileopen, (int) $after->reviewspecificfeedback);
    }

    /**
     * Test get_user_override returns null when no override.
     */
    public function test_get_user_override_returns_null(): void {
        $this->resetAfterTest();

        $s = $this->create_scenario();
        $this->assertNull($s->adapter->get_user_override($s->scenario->students[0]->id));
    }

    /**
     * Test get_user_override returns data when override exists.
     */
    public function test_get_user_override_returns_data(): void {
        global $DB;
        $this->resetAfterTest();

        $s = $this->create_scenario();

        $overridetime = time() + DAYSECS * 14;
        $DB->insert_record('quiz_overrides', (object) [
            'quiz' => $s->scenario->activity->id,
            'userid' => $s->scenario->students[0]->id,
            'timeclose' => $overridetime,
        ]);

        $override = $s->adapter->get_user_override($s->scenario->students[0]->id);
        $this->assertNotNull($override);
        $this->assertArrayHasKey('id', $override);
    }

    /**
     * Test get_participants structure.
     */
    public function test_get_participants_structure(): void {
        $this->resetAfterTest();

        $s = $this->create_scenario(['studentcount' => 1]);
        $participants = $s->adapter->get_participants();

        $this->assertCount(1, $participants);
        $p = $participants[0];

        $expectedkeys = ['id', 'fullname', 'status', 'submittedat', 'gradevalue'];
        foreach ($expectedkeys as $key) {
            $this->assertArrayHasKey($key, $p, "Missing key: {$key}");
        }
    }

    /**
     * Test perform_submission_action throws for quiz.
     */
    public function test_perform_submission_action_throws(): void {
        $this->resetAfterTest();

        $s = $this->create_scenario();

        $this->expectException(\moodle_exception::class);
        $s->adapter->perform_submission_action($s->scenario->students[0]->id, 'lock');
    }

    /**
     * Test supports_feature for quiz.
     */
    public function test_supports_feature(): void {
        $this->resetAfterTest();

        $s = $this->create_scenario();
        $this->assertFalse($s->adapter->supports_feature('filesubmission'));
        $this->assertFalse($s->adapter->supports_feature('annotations'));
    }

    // Per-attempt feedback tests.

    /**
     * Test save_grade stores per-attempt feedback in local_unifiedgrader_qfb.
     */
    public function test_save_grade_stores_per_attempt_feedback(): void {
        global $DB;
        $this->resetAfterTest();

        $s = $this->create_scenario(['studentcount' => 1]);
        $student = $s->scenario->students[0];

        $s->adapter->save_grade($student->id, null, '<p>Feedback for attempt 1</p>', FORMAT_HTML, [], 0, 0, 1);

        $record = $DB->get_record('local_unifiedgrader_qfb', [
            'cmid' => $s->scenario->cm->id,
            'userid' => $student->id,
            'attemptnumber' => 1,
        ]);

        $this->assertNotFalse($record);
        $this->assertStringContainsString('Feedback for attempt 1', $record->feedback);
    }

    /**
     * Test save_grade stores different feedback per attempt.
     */
    public function test_save_grade_different_feedback_per_attempt(): void {
        global $DB;
        $this->resetAfterTest();

        $s = $this->create_scenario(['studentcount' => 1]);
        $student = $s->scenario->students[0];

        $s->adapter->save_grade($student->id, null, '<p>Attempt 1 feedback</p>', FORMAT_HTML, [], 0, 0, 1);
        $s->adapter->save_grade($student->id, null, '<p>Attempt 2 feedback</p>', FORMAT_HTML, [], 0, 0, 2);

        $rec1 = $DB->get_record('local_unifiedgrader_qfb', [
            'cmid' => $s->scenario->cm->id,
            'userid' => $student->id,
            'attemptnumber' => 1,
        ]);
        $rec2 = $DB->get_record('local_unifiedgrader_qfb', [
            'cmid' => $s->scenario->cm->id,
            'userid' => $student->id,
            'attemptnumber' => 2,
        ]);

        $this->assertNotFalse($rec1);
        $this->assertNotFalse($rec2);
        $this->assertStringContainsString('Attempt 1 feedback', $rec1->feedback);
        $this->assertStringContainsString('Attempt 2 feedback', $rec2->feedback);
    }

    /**
     * Test save_grade updates existing per-attempt feedback record.
     */
    public function test_save_grade_updates_existing_feedback(): void {
        global $DB;
        $this->resetAfterTest();

        $s = $this->create_scenario(['studentcount' => 1]);
        $student = $s->scenario->students[0];

        $s->adapter->save_grade($student->id, null, '<p>Original</p>', FORMAT_HTML, [], 0, 0, 1);
        $s->adapter->save_grade($student->id, null, '<p>Updated</p>', FORMAT_HTML, [], 0, 0, 1);

        $count = $DB->count_records('local_unifiedgrader_qfb', [
            'cmid' => $s->scenario->cm->id,
            'userid' => $student->id,
            'attemptnumber' => 1,
        ]);
        $this->assertEquals(1, $count);

        $record = $DB->get_record('local_unifiedgrader_qfb', [
            'cmid' => $s->scenario->cm->id,
            'userid' => $student->id,
            'attemptnumber' => 1,
        ]);
        $this->assertStringContainsString('Updated', $record->feedback);
    }

    /**
     * Test gradebook syncs with the latest attempt's feedback.
     */
    public function test_save_grade_syncs_gradebook_with_latest_attempt(): void {
        $this->resetAfterTest();

        $s = $this->create_scenario(['studentcount' => 1]);
        $student = $s->scenario->students[0];

        // Save feedback for attempt 2 (latest).
        $s->adapter->save_grade($student->id, null, '<p>Latest attempt</p>', FORMAT_HTML, [], 0, 0, 2);
        // Save feedback for attempt 1 (earlier) — gradebook should still have attempt 2's.
        $s->adapter->save_grade($student->id, null, '<p>Earlier attempt</p>', FORMAT_HTML, [], 0, 0, 1);

        $gradeitem = \grade_item::fetch([
            'itemtype' => 'mod',
            'itemmodule' => 'quiz',
            'iteminstance' => $s->scenario->activity->id,
            'itemnumber' => 0,
            'courseid' => $s->scenario->course->id,
        ]);
        if ($gradeitem) {
            $gradegrade = \grade_grade::fetch([
                'itemid' => $gradeitem->id,
                'userid' => $student->id,
            ]);
            if ($gradegrade) {
                $this->assertStringContainsString('Latest attempt', $gradegrade->feedback);
            }
        }
    }

    /**
     * Test get_grade_data_for_attempt returns per-attempt feedback when available.
     */
    public function test_get_grade_data_for_attempt_returns_per_attempt_feedback(): void {
        global $DB;
        $this->resetAfterTest();

        $s = $this->create_scenario(['studentcount' => 1]);
        $student = $s->scenario->students[0];

        $DB->insert_record('local_unifiedgrader_qfb', (object) [
            'cmid' => $s->scenario->cm->id,
            'userid' => $student->id,
            'attemptnumber' => 1,
            'feedback' => '<p>Per-attempt feedback</p>',
            'feedbackformat' => FORMAT_HTML,
            'grader' => $s->scenario->teacher->id,
            'timemodified' => time(),
        ]);

        $data = $s->adapter->get_grade_data_for_attempt($student->id, 1);

        $this->assertStringContainsString('Per-attempt feedback', $data['feedback']);
    }

    /**
     * Test get_grade_data falls back to gradebook when no per-attempt record exists.
     */
    public function test_get_grade_data_falls_back_to_gradebook(): void {
        $this->resetAfterTest();

        $s = $this->create_scenario(['studentcount' => 1]);
        $student = $s->scenario->students[0];

        $data = $s->adapter->get_grade_data_for_attempt($student->id, 1);

        $this->assertEmpty(strip_tags($data['feedback']));
    }

    /**
     * Test prepare_feedback_draft returns per-attempt feedback.
     */
    public function test_prepare_feedback_draft_per_attempt(): void {
        global $DB;
        $this->resetAfterTest();

        $s = $this->create_scenario(['studentcount' => 1]);
        $student = $s->scenario->students[0];

        $DB->insert_record('local_unifiedgrader_qfb', (object) [
            'cmid' => $s->scenario->cm->id,
            'userid' => $student->id,
            'attemptnumber' => 1,
            'feedback' => '<p>Draft test feedback</p>',
            'feedbackformat' => FORMAT_HTML,
            'grader' => $s->scenario->teacher->id,
            'timemodified' => time(),
        ]);

        $draftitemid = file_get_unused_draft_itemid();
        $result = $s->adapter->prepare_feedback_draft($student->id, $draftitemid, 1);

        $this->assertArrayHasKey('feedbackhtml', $result);
        $this->assertStringContainsString('Draft test feedback', $result['feedbackhtml']);
    }

    /**
     * Build a quiz attempt with one manually-graded essay, mark it, and let
     * mod_quiz compute the overall grade from it.
     *
     * Shaped after core's own helper in
     * mod/quiz/tests/quiz_notify_attempt_manual_grading_completed_test.php,
     * which is the file that already absorbed the traps here: the attempt has
     * to be started, submitted and grade-submitted before a manual mark means
     * anything, quiz_attempts.sumgrades has to be written back from the usage,
     * and only then does recompute_final_grade() populate quiz_grades.
     *
     * @param \stdClass $scenario Scenario from create_grading_scenario('quiz').
     * @param \stdClass $student The student to build the attempt for.
     * @param float $mark Mark to award the essay question.
     */
    private function build_manually_graded_attempt(\stdClass $scenario, \stdClass $student, float $mark): void {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $cat = $questiongenerator->create_question_category();
        $essay = $questiongenerator->create_question('essay', null, ['category' => $cat->id]);
        // Weight 10 so a mark of 6 is in range: manual_grade() takes a MARK, and
        // the essay generator's default maxmark is 1.
        quiz_add_quiz_question($essay->id, $scenario->activity, 0, 10);

        $quizobj = \mod_quiz\quiz_settings::create($scenario->activity->id);
        $quizobj->get_grade_calculator()->recompute_quiz_sumgrades();
        $quizobj = \mod_quiz\quiz_settings::create($scenario->activity->id);

        $quba = \question_engine::make_questions_usage_by_activity('mod_quiz', $quizobj->get_context());
        $quba->set_preferred_behaviour($quizobj->get_quiz()->preferredbehaviour);

        $now = time();
        $attempt = quiz_create_attempt($quizobj, 1, false, $now - HOURSECS, false, $student->id);
        quiz_start_new_attempt($quizobj, $quba, $attempt, 1, $now - HOURSECS);
        quiz_attempt_save_started($quizobj, $quba, $attempt);

        $attemptobj = \mod_quiz\quiz_attempt::create($attempt->id);
        $attemptobj->process_submitted_actions(
            $now - 30 * MINSECS,
            false,
            [1 => ['answer' => 'An essay answer', 'answerformat' => FORMAT_HTML]],
        );
        $attemptobj->process_submit($now - 20 * MINSECS, false);
        $attemptobj->process_grade_submission($now - 10 * MINSECS);

        // Award the manual mark, which is the only sanctioned way to move a
        // quiz total: mod_quiz derives quiz_grades from the attempt sumgrades.
        $attemptobj->get_question_usage()->manual_grade(1, 'Marked.', $mark, FORMAT_HTML);
        \question_engine::save_questions_usage_by_activity($attemptobj->get_question_usage());
        $DB->update_record('quiz_attempts', (object) [
            'id' => $attemptobj->get_attemptid(),
            'timemodified' => $now,
            'sumgrades' => $attemptobj->get_question_usage()->get_total_mark(),
        ]);
        $quizobj->get_grade_calculator()->recompute_final_grade($student->id);
    }

    /**
     * A quiz total typed into the marking panel is deliberately NOT persisted.
     *
     * quiz_grades is derived state: mod_quiz\grade_calculator rewrites it from
     * the attempt sumgrades and quiz.grademethod on every recompute, and the
     * recomputes fire from everywhere - a per-question save, deleting another
     * attempt, a regrade, a max-grade change, and the update_overdue_attempts
     * cron task with no human action at all. quiz_grades has no override column
     * (mod/quiz/db/install.xml even documents the field as "not affected by
     * overrides in the gradebook"), and core's own manual-grading report offers
     * per-question marks only, never a total.
     *
     * So save_grade() ignoring its $grade argument for quizzes is correct, not
     * an oversight: honouring it would write a value that survives the request,
     * passes a manual test, and is then silently reverted by cron hours later -
     * a worse failure than not accepting it. Teachers move a quiz total by
     * changing the question marks, which this adapter does persist.
     *
     * This test exists so nobody "fixes" the ignored argument later.
     */
    public function test_save_grade_does_not_persist_a_typed_quiz_total(): void {
        global $DB;
        $this->resetAfterTest();

        $s = $this->create_scenario(['studentcount' => 1]);
        $student = $s->scenario->students[0];
        $this->build_manually_graded_attempt($s->scenario, $student, 6.0);
        $this->setUser($s->scenario->teacher);

        $before = $DB->get_field('quiz_grades', 'grade', [
            'quiz' => $s->scenario->activity->id,
            'userid' => $student->id,
        ]);
        $this->assertNotFalse($before, 'Precondition: the attempt must have produced a quiz_grades row.');
        $this->assertGreaterThan(0, (float) $before, 'Precondition: the computed total must be non-zero.');

        // Save a total deliberately different from the computed one.
        $result = $s->adapter->save_grade($student->id, 99.0, '<p>Typed marker</p>', FORMAT_HTML, [], 0, 0, 1);
        $this->assertTrue($result);

        $after = $DB->get_field('quiz_grades', 'grade', [
            'quiz' => $s->scenario->activity->id,
            'userid' => $student->id,
        ]);
        $this->assertEqualsWithDelta(
            (float) $before,
            (float) $after,
            0.0001,
            'A typed quiz total must not overwrite the engine-computed grade.'
        );

        /* Control. Without it the assertion above passes for the wrong reason -
           it would hold just as well if save_grade() had thrown, or done
           nothing at all. The feedback proves the call really ran and really
           did the work it IS responsible for. */
        $qfb = $DB->get_record('local_unifiedgrader_qfb', [
            'cmid' => $s->scenario->cm->id,
            'userid' => $student->id,
            'attemptnumber' => 1,
        ]);
        $this->assertNotEmpty($qfb, 'Control: save_grade must still have stored the feedback.');
        $this->assertStringContainsString('Typed marker', $qfb->feedback);
    }
}
