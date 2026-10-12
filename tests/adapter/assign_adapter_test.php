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
 * Tests for the assign_adapter class.
 *
 * @package    local_unifiedgrader
 * @category   test
 * @copyright  2026 South African Theological Seminary (mathieu@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_unifiedgrader\adapter\assign_adapter
 */
final class assign_adapter_test extends \advanced_testcase {
    /**
     * Helper: create a scenario and return the adapter.
     *
     * @param array $options Scenario options.
     * @return object{adapter: assign_adapter, scenario: \stdClass}
     */
    private function create_scenario(array $options = []): object {
        $plugingen = $this->getDataGenerator()->get_plugin_generator('local_unifiedgrader');
        $scenario = $plugingen->create_grading_scenario('assign', $options);
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
        $this->assertEquals('assign', $info['type']);
        $this->assertArrayHasKey('name', $info);
        $this->assertArrayHasKey('duedate', $info);
        $this->assertArrayHasKey('cutoffdate', $info);
        $this->assertArrayHasKey('maxgrade', $info);
        $this->assertArrayHasKey('gradingmethod', $info);
        $this->assertArrayHasKey('teamsubmission', $info);
        $this->assertArrayHasKey('blindmarking', $info);
        $this->assertGreaterThan(0, $info['duedate']);
        $this->assertEquals('simple', $info['gradingmethod']);
    }

    /**
     * Test get_activity_info with blind marking.
     */
    public function test_get_activity_info_blind_marking(): void {
        $this->resetAfterTest();

        $s = $this->create_scenario(['modparams' => ['blindmarking' => 1]]);
        $info = $s->adapter->get_activity_info();

        $this->assertTrue($info['blindmarking']);
    }

    /**
     * Test get_participants returns empty when no students enrolled.
     */
    public function test_get_participants_empty(): void {
        $this->resetAfterTest();

        $s = $this->create_scenario(['studentcount' => 0]);
        $participants = $s->adapter->get_participants();

        $this->assertIsArray($participants);
        $this->assertEmpty($participants);
    }

    /**
     * Test get_participants returns students with correct submission status.
     */
    public function test_get_participants_with_submissions(): void {
        $this->resetAfterTest();

        $plugingen = $this->getDataGenerator()->get_plugin_generator('local_unifiedgrader');
        $s = $this->create_scenario();

        // Submit for first student.
        $this->setUser($s->scenario->students[0]);
        $plugingen->create_assign_submission($s->scenario->activity, $s->scenario->students[0]->id);

        $this->setUser($s->scenario->teacher);
        $participants = $s->adapter->get_participants();

        $this->assertCount(3, $participants);

        // Find the submitted student.
        $submitted = array_values(array_filter($participants, fn($p) => $p['id'] == $s->scenario->students[0]->id));
        $this->assertCount(1, $submitted);
        $this->assertEquals('submitted', $submitted[0]['status']);

        // Others should have no submission.
        $notsubmitted = array_values(array_filter($participants, fn($p) => $p['id'] != $s->scenario->students[0]->id));
        foreach ($notsubmitted as $p) {
            $this->assertEquals('nosubmission', $p['status']);
        }
    }

    /**
     * Test get_participants status filter for submitted.
     */
    public function test_get_participants_filter_submitted(): void {
        $this->resetAfterTest();

        $plugingen = $this->getDataGenerator()->get_plugin_generator('local_unifiedgrader');
        $s = $this->create_scenario();

        $this->setUser($s->scenario->students[0]);
        $plugingen->create_assign_submission($s->scenario->activity, $s->scenario->students[0]->id);

        $this->setUser($s->scenario->teacher);
        $participants = $s->adapter->get_participants(['status' => 'submitted']);

        $this->assertCount(1, $participants);
        $this->assertEquals($s->scenario->students[0]->id, $participants[0]['id']);
    }

    /**
     * Test get_participants status filter for notsubmitted.
     */
    public function test_get_participants_filter_notsubmitted(): void {
        $this->resetAfterTest();

        $plugingen = $this->getDataGenerator()->get_plugin_generator('local_unifiedgrader');
        $s = $this->create_scenario();

        $this->setUser($s->scenario->students[0]);
        $plugingen->create_assign_submission($s->scenario->activity, $s->scenario->students[0]->id);

        $this->setUser($s->scenario->teacher);
        $participants = $s->adapter->get_participants(['status' => 'notsubmitted']);

        $this->assertCount(2, $participants);
    }

    /**
     * Test get_participants search filter.
     */
    public function test_get_participants_filter_search(): void {
        $this->resetAfterTest();

        $gen = $this->getDataGenerator();
        $plugingen = $gen->get_plugin_generator('local_unifiedgrader');
        $scenario = $plugingen->create_grading_scenario('assign', ['studentcount' => 0]);

        // Create students with specific names.
        $alice = $gen->create_user(['firstname' => 'Alice', 'lastname' => 'Wonder']);
        $bob = $gen->create_user(['firstname' => 'Bob', 'lastname' => 'Builder']);
        $gen->enrol_user($alice->id, $scenario->course->id, 'student');
        $gen->enrol_user($bob->id, $scenario->course->id, 'student');

        $this->setUser($scenario->teacher);
        $adapter = adapter_factory::create($scenario->cm->id);

        $participants = $adapter->get_participants(['search' => 'alice']);
        $this->assertCount(1, $participants);
        $this->assertStringContainsString('Alice', $participants[0]['fullname']);
    }

    /**
     * Test get_participants default sort by fullname.
     */
    public function test_get_participants_sort_by_fullname(): void {
        $this->resetAfterTest();

        $gen = $this->getDataGenerator();
        $plugingen = $gen->get_plugin_generator('local_unifiedgrader');
        $scenario = $plugingen->create_grading_scenario('assign', ['studentcount' => 0]);

        $charlie = $gen->create_user(['firstname' => 'Charlie', 'lastname' => 'Z']);
        $alice = $gen->create_user(['firstname' => 'Alice', 'lastname' => 'A']);
        $gen->enrol_user($charlie->id, $scenario->course->id, 'student');
        $gen->enrol_user($alice->id, $scenario->course->id, 'student');

        $this->setUser($scenario->teacher);
        $adapter = adapter_factory::create($scenario->cm->id);

        $participants = $adapter->get_participants(['sort' => 'fullname', 'sortdir' => 'asc']);
        $this->assertEquals($alice->id, $participants[0]['id']);
        $this->assertEquals($charlie->id, $participants[1]['id']);
    }

    /**
     * Test get_participants late detection.
     */
    public function test_get_participants_late_detection(): void {
        $this->resetAfterTest();

        $plugingen = $this->getDataGenerator()->get_plugin_generator('local_unifiedgrader');
        // Due date is in the past.
        $s = $this->create_scenario(['modparams' => ['duedate' => time() - DAYSECS]]);

        // Submit after due date.
        $this->setUser($s->scenario->students[0]);
        $plugingen->create_assign_submission($s->scenario->activity, $s->scenario->students[0]->id);

        $this->setUser($s->scenario->teacher);
        $participants = $s->adapter->get_participants();

        $submitted = array_values(array_filter($participants, fn($p) => $p['id'] == $s->scenario->students[0]->id));
        $this->assertTrue($submitted[0]['islate']);
    }

    /**
     * Test get_submission_data with no submission.
     */
    public function test_get_submission_data_no_submission(): void {
        $this->resetAfterTest();

        $s = $this->create_scenario();
        $data = $s->adapter->get_submission_data($s->scenario->students[0]->id);

        $this->assertEquals('nosubmission', $data['status']);
        $this->assertEquals($s->scenario->students[0]->id, $data['userid']);
        $this->assertEmpty($data['files']);
        $this->assertEquals(0, $data['timecreated']);
    }

    /**
     * Test get_submission_data with a submission.
     */
    public function test_get_submission_data_with_submission(): void {
        $this->resetAfterTest();

        $plugingen = $this->getDataGenerator()->get_plugin_generator('local_unifiedgrader');
        $s = $this->create_scenario();

        $this->setUser($s->scenario->students[0]);
        $plugingen->create_assign_submission($s->scenario->activity, $s->scenario->students[0]->id, '<p>My answer</p>');

        $this->setUser($s->scenario->teacher);
        $data = $s->adapter->get_submission_data($s->scenario->students[0]->id);

        $this->assertEquals('submitted', $data['status']);
        $this->assertGreaterThan(0, $data['timemodified']);
        $this->assertEquals(0, $data['attemptnumber']);
    }

    /**
     * With online-text-to-PDF enabled but no document converter available (as in
     * CI and most dev sites), opening the same submission twice must not throw a
     * duplicate-file exception and must not leave an orphaned temp HTML file: the
     * conversion fails gracefully and falls back to the iframe preview.
     */
    public function test_get_submission_data_onlinetext_pdf_no_converter_is_graceful(): void {
        global $DB;
        $this->resetAfterTest();

        set_config('onlinetext_as_pdf', 1, 'local_unifiedgrader');

        $plugingen = $this->getDataGenerator()->get_plugin_generator('local_unifiedgrader');
        $s = $this->create_scenario();
        $this->setUser($s->scenario->students[0]);
        $plugingen->create_assign_submission(
            $s->scenario->activity,
            $s->scenario->students[0]->id,
            '<p>Une réponse en français.</p>'
        );

        $this->setUser($s->scenario->teacher);
        // Two opens in a row: the first would create the temp HTML, the second
        // used to collide on it. Both must now succeed.
        $first = $s->adapter->get_submission_data($s->scenario->students[0]->id);
        $second = $s->adapter->get_submission_data($s->scenario->students[0]->id);

        $this->assertEquals('submitted', $first['status']);
        $this->assertEquals('submitted', $second['status']);
        $this->assertSame(0, $DB->count_records('files', [
            'component' => 'local_unifiedgrader',
            'filearea' => 'onlinetextpdf',
            'filename' => 'onlinetext.html',
        ]), 'The temp conversion HTML must never be left orphaned.');
    }

    /**
     * Test get_grade_data with no grade.
     */
    public function test_get_grade_data_no_grade(): void {
        $this->resetAfterTest();

        $s = $this->create_scenario();
        $data = $s->adapter->get_grade_data($s->scenario->students[0]->id);

        $this->assertNull($data['grade']);
        $this->assertEquals(0, $data['timegraded']);
        $this->assertEquals(0, $data['grader']);
    }

    /**
     * Test save_grade and then read it back.
     */
    public function test_save_grade_simple(): void {
        $this->resetAfterTest();

        $plugingen = $this->getDataGenerator()->get_plugin_generator('local_unifiedgrader');
        $s = $this->create_scenario();

        // Create a submission first.
        $this->setUser($s->scenario->students[0]);
        $plugingen->create_assign_submission($s->scenario->activity, $s->scenario->students[0]->id);

        $this->setUser($s->scenario->teacher);
        $result = $s->adapter->save_grade(
            $s->scenario->students[0]->id,
            85.0,
            '<p>Good work!</p>',
        );
        $this->assertTrue($result);

        // Read it back.
        $data = $s->adapter->get_grade_data($s->scenario->students[0]->id);
        $this->assertEquals(85.0, $data['grade']);
        $this->assertStringContainsString('Good work!', $data['feedback']);
        $this->assertGreaterThan(0, $data['timegraded']);
    }

    /**
     * On a marking-workflow assignment, posting grades must RELEASE the per-user
     * workflow state (which is what actually makes the grade and feedback visible
     * to students) — not just toggle the gradebook hidden flag.
     */
    public function test_post_grades_releases_marking_workflow(): void {
        $this->resetAfterTest();

        $plugingen = $this->getDataGenerator()->get_plugin_generator('local_unifiedgrader');
        $s = $this->create_scenario(['modparams' => ['markingworkflow' => 1]]);
        $student = $s->scenario->students[0];

        $this->setUser($student);
        $plugingen->create_assign_submission($s->scenario->activity, $student->id);

        $this->setUser($s->scenario->teacher);
        $s->adapter->save_grade($student->id, 85.0, '<p>Good work!</p>');

        // Graded but not released: feedback is not yet visible.
        $this->assertFalse($s->adapter->is_grade_released($student->id));
        $this->assertFalse($s->adapter->are_grades_posted());

        // Post grades → release.
        $s->adapter->set_grades_posted(0);
        $this->assertTrue($s->adapter->is_grade_released($student->id));
        $this->assertTrue($s->adapter->are_grades_posted());

        // Hiding again un-releases (back to ready-for-release).
        $s->adapter->set_grades_posted(1);
        $this->assertFalse($s->adapter->is_grade_released($student->id));
        $this->assertFalse($s->adapter->are_grades_posted());
    }

    /**
     * A plain numeric grade must still persist when the gradebook entry is
     * overridden — the state gradepenalty_duedate leaves behind after applying
     * a late penalty (grade_grades.overridden set, assign::grading_disabled()
     * returns true). Before the fix the override-clearing recovery was gated
     * behind advanced-grading data, so a plain grade was silently dropped:
     * apply_grade_to_user() skipped the write and the teacher's mark "vanished"
     * on refresh. This is the regression guard for that data-loss path.
     */
    public function test_save_grade_persists_when_gradebook_overridden(): void {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        $this->resetAfterTest();

        $plugingen = $this->getDataGenerator()->get_plugin_generator('local_unifiedgrader');
        $s = $this->create_scenario();
        $student = $s->scenario->students[0];

        // Submission + an initial grade so a gradebook row exists.
        $this->setUser($student);
        $plugingen->create_assign_submission($s->scenario->activity, $student->id);
        $this->setUser($s->scenario->teacher);
        $s->adapter->save_grade($student->id, 50.0, '<p>First pass.</p>');

        // Simulate a late-penalty deduction by overriding the gradebook entry,
        // which is exactly what makes assign::grading_disabled() return true.
        $gradeitem = \grade_item::fetch([
            'itemtype' => 'mod',
            'itemmodule' => 'assign',
            'iteminstance' => $s->scenario->activity->id,
            'itemnumber' => 0,
            'courseid' => $s->scenario->course->id,
        ]);
        $this->assertNotEmpty($gradeitem);
        $gradegrade = \grade_grade::fetch(['itemid' => $gradeitem->id, 'userid' => $student->id]);
        $this->assertNotEmpty($gradegrade);
        $gradegrade->set_overridden(true);
        $this->assertNotEmpty(
            \grade_grade::fetch(['itemid' => $gradeitem->id, 'userid' => $student->id])->overridden,
            'Precondition: the gradebook entry should be overridden.',
        );

        // Re-grade through a fresh adapter, as a new request would. Before the
        // fix this plain grade was silently skipped and the mark stayed at 50;
        // it must now persist as 85.
        $adapter = adapter_factory::create($s->scenario->cm->id);
        $result = $adapter->save_grade($student->id, 85.0, '<p>Second pass.</p>');
        $this->assertTrue($result);

        $data = $adapter->get_grade_data($student->id);
        $this->assertEquals(85.0, $data['grade'], 'The re-grade must persist despite the gradebook override.');

        // The recovery should have lifted the recoverable override so the grade
        // could land (the penalty subsystem would re-apply it in production).
        $after = \grade_grade::fetch(['itemid' => $gradeitem->id, 'userid' => $student->id]);
        $this->assertEmpty($after->overridden, 'The recoverable override should have been cleared.');
    }

    /**
     * Clearing a grade is refused, not silently dropped, when the gradebook cell is overridden.
     *
     * Saving a value under an override works (test_save_grade_persists_when_gradebook_overridden
     * above): the adapter lifts the override first. A pure clear does not lift it, since that
     * would drop a penalty the teacher never asked to drop, and core's apply_grade_to_user()
     * skips the write while grading_disabled() is true. So the clear throws; the "--" reset
     * (reset_grade_and_submission()) is the path that lifts the override. See
     * assign_adapter::save_grade().
     */
    public function test_clearing_a_grade_is_refused_when_the_gradebook_is_overridden(): void {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        $this->resetAfterTest();

        $plugingen = $this->getDataGenerator()->get_plugin_generator('local_unifiedgrader');
        $s = $this->create_scenario();
        $student = $s->scenario->students[0];

        $this->setUser($student);
        $plugingen->create_assign_submission($s->scenario->activity, $student->id);
        $this->setUser($s->scenario->teacher);
        $s->adapter->save_grade($student->id, 50.0, '<p>First pass.</p>');
        $this->assertEquals(
            50.0,
            $s->adapter->get_grade_data($student->id)['grade'],
            'Precondition: there must be a grade to clear.'
        );

        // Override the gradebook entry - what a late penalty leaves behind.
        $gradeitem = \grade_item::fetch([
            'itemtype' => 'mod',
            'itemmodule' => 'assign',
            'iteminstance' => $s->scenario->activity->id,
            'itemnumber' => 0,
            'courseid' => $s->scenario->course->id,
        ]);
        $this->assertNotEmpty($gradeitem);
        $gradegrade = \grade_grade::fetch(['itemid' => $gradeitem->id, 'userid' => $student->id]);
        $this->assertNotEmpty($gradegrade);
        $gradegrade->set_overridden(true);
        $this->assertNotEmpty(
            \grade_grade::fetch(['itemid' => $gradeitem->id, 'userid' => $student->id])->overridden,
            'Precondition: the gradebook entry must be overridden.'
        );

        // Clear through a fresh adapter, as a new request would.
        $adapter = adapter_factory::create($s->scenario->cm->id);
        try {
            $adapter->save_grade($student->id, null, '<p>First pass.</p>');
            $this->fail('Clearing the grade should have been refused while the gradebook entry is overridden.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_grade_clear_blocked_by_gradebook', $e->errorcode);
        }

        // The refusal has to be truthful: the grade really is still there.
        $this->assertEquals(
            50.0,
            $adapter->get_grade_data($student->id)['grade'],
            'The refusal must reflect reality - the grade was not cleared.'
        );
    }

    /**
     * A clear refused because the gradebook grade is locked says so.
     *
     * The override message suggests "--", which cannot lift a lock and is itself refused
     * under one. The test above pins the override message and this one the lock message,
     * so a refusal that always picked one of the two fails one of them.
     */
    public function test_clearing_a_grade_is_refused_with_the_lock_message_when_the_grade_is_locked(): void {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        $this->resetAfterTest();

        $plugingen = $this->getDataGenerator()->get_plugin_generator('local_unifiedgrader');
        $s = $this->create_scenario();
        $student = $s->scenario->students[0];

        $this->setUser($student);
        $plugingen->create_assign_submission($s->scenario->activity, $student->id);
        $this->setUser($s->scenario->teacher);
        $s->adapter->save_grade($student->id, 50.0, '<p>First pass.</p>');

        $gradeitem = \grade_item::fetch([
            'itemtype' => 'mod',
            'itemmodule' => 'assign',
            'iteminstance' => $s->scenario->activity->id,
            'itemnumber' => 0,
            'courseid' => $s->scenario->course->id,
        ]);
        $gradegrade = \grade_grade::fetch(['itemid' => $gradeitem->id, 'userid' => $student->id]);
        $gradegrade->locked = time();
        $gradegrade->update();
        $this->assertTrue($gradeitem->is_locked($student->id), 'Precondition: the gradebook grade must be locked.');

        $adapter = adapter_factory::create($s->scenario->cm->id);
        try {
            $adapter->save_grade($student->id, null, '<p>First pass.</p>');
            $this->fail('Clearing the grade should have been refused while the gradebook grade is locked.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_grade_clear_blocked_by_lock', $e->errorcode);
        }

        $this->assertEquals(
            50.0,
            $adapter->get_grade_data($student->id)['grade'],
            'The refusal must reflect reality - the grade was not cleared.'
        );
    }

    /**
     * The "--" deliberate reset clears the grade, but only strips the orphan
     * submission when the grader may edit other people's submissions.
     *
     * assign::remove_submission() requires mod/assign:editothersubmission for another
     * user's submission, a capability core grants to no archetype, not even manager. So on
     * a default site "--" acts like "-": the grade goes, the row and its plugin data stay.
     * The first leg pins that, and that the reset returns false to say so; the
     * second pins the reset with the capability.
     *
     * Even with the capability the row is not deleted: remove_submission() resets its
     * status to new (or reopened) and asks each submission plugin to drop its data.
     */
    public function test_full_reset_strips_the_orphan_only_with_the_capability(): void {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        $this->resetAfterTest();

        $s = $this->create_scenario();
        $student = $s->scenario->students[0];
        $context = \context_module::instance($s->scenario->cm->id);
        [$course, $cm] = get_course_and_cm_from_cmid($s->scenario->cm->id, 'assign');
        $assignobj = new \assign($context, $cm, $course);

        // The row an accidental teacher interaction leaves behind: created,
        // never submitted, carrying whatever the submission plugins wrote.
        $submission = $assignobj->get_user_submission($student->id, true);
        $DB->insert_record('assignsubmission_onlinetext', (object) [
            'assignment' => $s->scenario->activity->id,
            'submission' => $submission->id,
            'onlinetext' => 'Orphan content',
            'onlineformat' => FORMAT_HTML,
        ]);
        $this->setUser($s->scenario->teacher);
        $s->adapter->save_grade($student->id, 12.0, '');
        $this->assertEquals(
            12.0,
            $s->adapter->get_grade_data($student->id)['grade'],
            'Precondition: there is a grade to reset.'
        );
        $this->assertNotEmpty(
            $DB->get_record('assignsubmission_onlinetext', ['submission' => $submission->id]),
            'Precondition: the orphan carries submission plugin data.'
        );

        // Leg 1: default editing teacher, no editothersubmission. The reset says
        // the submission half was refused instead of reporting plain success.
        $this->assertFalse(
            $s->adapter->reset_grade_and_submission($student->id),
            'A reset that kept the submission must not report full success.'
        );
        $this->assertNull(
            $s->adapter->get_grade_data($student->id)['grade'],
            'The grade half of the reset always happens.'
        );
        $this->assertNotEmpty(
            $DB->get_record('assignsubmission_onlinetext', ['submission' => $submission->id]),
            'Without mod/assign:editothersubmission the submission half is inert.'
        );

        // Leg 2: the same reset, once the grader may edit the submission.
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        assign_capability('mod/assign:editothersubmission', CAP_ALLOW, $roleid, $context->id, true);
        accesslib_clear_all_caches_for_unit_testing();

        $adapter = adapter_factory::create($s->scenario->cm->id);
        $adapter->save_grade($student->id, 12.0, '');
        $this->assertTrue($adapter->reset_grade_and_submission($student->id));

        $this->assertEmpty(
            $DB->get_record('assignsubmission_onlinetext', ['submission' => $submission->id]),
            'With the capability the submission plugin data is dropped.'
        );
        $row = $DB->get_record('assign_submission', ['id' => $submission->id]);
        $this->assertNotEmpty($row, 'The row itself is never deleted - only reset.');
        $this->assertSame(ASSIGN_SUBMISSION_STATUS_NEW, $row->status);
    }

    /**
     * Build an assignment under marking workflow, graded 50 by the editing
     * teacher and parked in the given workflow state, plus a non-editing
     * teacher who holds mod/assign:grade but none of the review or release
     * capabilities, so only the in-marking and ready-for-review states are hers.
     *
     * @param string $workflowstate One of the ASSIGN_MARKING_WORKFLOW_STATE_* values.
     * @return object{scenario: \stdClass, student: \stdClass, marker: \stdClass}
     */
    private function create_workflow_scenario(string $workflowstate): object {
        global $CFG;
        require_once($CFG->dirroot . '/mod/assign/locallib.php');

        $plugingen = $this->getDataGenerator()->get_plugin_generator('local_unifiedgrader');
        $s = $this->create_scenario(['modparams' => ['markingworkflow' => 1]]);
        $student = $s->scenario->students[0];

        $this->setUser($student);
        $plugingen->create_assign_submission($s->scenario->activity, $student->id);
        $this->setUser($s->scenario->teacher);
        $s->adapter->save_grade($student->id, 50.0, '');

        [$course, $cm] = get_course_and_cm_from_cmid($s->scenario->cm->id, 'assign');
        $assignobj = new \assign(\context_module::instance($cm->id), $cm, $course);
        $flags = $assignobj->get_user_flags($student->id, true);
        $flags->workflowstate = $workflowstate;
        $assignobj->update_user_flags($flags);

        $marker = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($marker->id, $s->scenario->course->id, 'teacher');

        return (object) ['scenario' => $s->scenario, 'student' => $student, 'marker' => $marker];
    }

    /**
     * Marking workflow refuses a grade, a clear and a "--" reset with its own message.
     *
     * core's grading_disabled() is also true when the submission sits in a workflow
     * state the grader may not set. The clear used to blame a gradebook override that
     * did not exist and point at "--", and "--" then wrote -1 past the workflow; a
     * typed grade blamed a gradebook lock. Each is now refused with the workflow
     * message, and the mark stays.
     *
     * Moodle branches whose grading_disabled() does not apply the workflow state skip
     * this test: their own save does not refuse, so neither does the adapter.
     */
    public function test_grading_refused_by_marking_workflow_says_so(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        $this->resetAfterTest();

        $w = $this->create_workflow_scenario(ASSIGN_MARKING_WORKFLOW_STATE_INREVIEW);
        $this->setUser($w->marker);
        $adapter = adapter_factory::create($w->scenario->cm->id);

        [$course, $cm] = get_course_and_cm_from_cmid($w->scenario->cm->id, 'assign');
        $assignobj = new \assign(\context_module::instance($cm->id), $cm, $course);
        if (!$assignobj->grading_disabled($w->student->id)) {
            $this->markTestSkipped('This Moodle branch does not restrict grading by marking workflow state.');
        }
        $this->assertFalse(
            $assignobj->grading_disabled($w->student->id, false),
            'Precondition: nothing in the gradebook blocks the grade; only the workflow does.'
        );

        $attempts = [
            'clear' => fn() => $adapter->save_grade($w->student->id, null, ''),
            'grade' => fn() => $adapter->save_grade($w->student->id, 70.0, ''),
            'reset' => fn() => $adapter->reset_grade_and_submission($w->student->id),
        ];
        foreach ($attempts as $label => $attempt) {
            try {
                $attempt();
                $this->fail("The {$label} should have been refused under marking workflow.");
            } catch (\moodle_exception $e) {
                $this->assertSame('error_grade_blocked_by_workflow', $e->errorcode, $label);
            }
            $this->assertEquals(
                50.0,
                adapter_factory::create($w->scenario->cm->id)->get_grade_data($w->student->id)['grade'],
                "The refused {$label} must leave the mark in place."
            );
        }
    }

    /**
     * The same marker may still grade a submission in a workflow state that is hers.
     *
     * The control for test_grading_refused_by_marking_workflow_says_so(): the refusal
     * follows the workflow state, not the marker's role.
     */
    public function test_marking_workflow_allows_grading_in_a_state_the_grader_may_set(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        $this->resetAfterTest();

        $w = $this->create_workflow_scenario(ASSIGN_MARKING_WORKFLOW_STATE_INMARKING);
        $this->setUser($w->marker);
        $adapter = adapter_factory::create($w->scenario->cm->id);

        $this->assertTrue($adapter->save_grade($w->student->id, 70.0, ''));
        $this->assertEquals(70.0, $adapter->get_grade_data($w->student->id)['grade']);

        $adapter->save_grade($w->student->id, null, '');
        $this->assertNull($adapter->get_grade_data($w->student->id)['grade']);
    }

    /**
     * Attach a marking guide with one criterion out of 10 to the scenario's assignment.
     *
     * @param \stdClass $scenario Scenario from the plugin generator.
     * @return int The criterion id.
     */
    private function attach_marking_guide(\stdClass $scenario): int {
        $context = \context_module::instance($scenario->cm->id);
        $generator = $this->getDataGenerator()->get_plugin_generator('gradingform_guide');
        $generator->create_instance($context, 'mod_assign', 'submissions', 'Guide', '', [
            'Argument' => ['description' => 'Argument', 'descriptionmarkers' => 'Argument', 'maxscore' => 10],
        ]);
        $controller = get_grading_manager($context, 'mod_assign', 'submissions')->get_active_controller();
        $this->assertNotEmpty($controller, 'Precondition: the guide is the active grading method.');
        return (int) array_key_first($controller->get_definition()->guide_criteria);
    }

    /**
     * A null grade clears the mark even when it comes with filled guide criteria.
     *
     * The grader sends the criteria on every save once the assignment has a guide,
     * so an emptied grade box or a typed "-" reaches the server as a null grade with
     * the guide still scored. Sent through the grading instance it recomputed the
     * mark from the guide, and the clear never happened. forum_adapter_test pins the
     * same rule for forums.
     */
    public function test_null_grade_with_guide_criteria_clears_the_mark(): void {
        $this->resetAfterTest();

        $s = $this->create_scenario();
        $student = $s->scenario->students[0];
        $criterionid = $this->attach_marking_guide($s->scenario);
        $criteria = ['criteria' => [$criterionid => ['score' => '7', 'remark' => '']]];

        $adapter = adapter_factory::create($s->scenario->cm->id);
        $adapter->save_grade($student->id, 70.0, '', FORMAT_HTML, $criteria);
        $this->assertEqualsWithDelta(
            70.0,
            $adapter->get_grade_data($student->id)['grade'],
            0.01,
            'Precondition: the guide graded the student.'
        );

        $this->assertTrue($adapter->save_grade($student->id, null, '', FORMAT_HTML, $criteria));

        $this->assertNull(
            adapter_factory::create($s->scenario->cm->id)->get_grade_data($student->id)['grade'],
            'A null grade must clear the mark, not have the guide recompute it.'
        );
    }

    /**
     * Criteria data without an active grading method does not swallow a clear.
     *
     * A page opened before the guide was removed still sends its criteria. With no
     * grading instance to feed, assign::save_grade() stored nothing and the save
     * reported success with the old mark in place.
     */
    public function test_null_grade_with_criteria_but_no_grading_method_clears_the_mark(): void {
        $this->resetAfterTest();

        $s = $this->create_scenario();
        $student = $s->scenario->students[0];
        $this->assertNull(
            get_grading_manager(\context_module::instance($s->scenario->cm->id), 'mod_assign', 'submissions')
                ->get_active_controller(),
            'Precondition: no advanced grading method is active.'
        );

        $s->adapter->save_grade($student->id, 15.0, '');
        $this->assertEquals(15.0, $s->adapter->get_grade_data($student->id)['grade'], 'Precondition: a mark to clear.');

        $criteria = ['criteria' => [1 => ['score' => '7', 'remark' => '']]];
        $this->assertTrue($s->adapter->save_grade($student->id, null, '', FORMAT_HTML, $criteria));

        $this->assertNull(
            adapter_factory::create($s->scenario->cm->id)->get_grade_data($student->id)['grade'],
            'The clear must land even though stale criteria came with it.'
        );
    }

    /**
     * Test is_grade_released with no grade returns false.
     */
    public function test_is_grade_released_no_grade(): void {
        $this->resetAfterTest();

        $s = $this->create_scenario();
        $this->assertFalse($s->adapter->is_grade_released($s->scenario->students[0]->id));
    }

    /**
     * Test is_grade_released with hidden grade item returns false.
     */
    public function test_is_grade_released_hidden_item(): void {
        $this->resetAfterTest();

        $plugingen = $this->getDataGenerator()->get_plugin_generator('local_unifiedgrader');
        $s = $this->create_scenario();

        // Create submission and grade.
        $this->setUser($s->scenario->students[0]);
        $plugingen->create_assign_submission($s->scenario->activity, $s->scenario->students[0]->id);
        $this->setUser($s->scenario->teacher);
        $s->adapter->save_grade($s->scenario->students[0]->id, 80.0, '');

        // Hide the grade item.
        $s->adapter->set_grades_posted(1);

        $this->assertFalse($s->adapter->is_grade_released($s->scenario->students[0]->id));
    }

    /**
     * Test is_grade_released with visible grade returns true.
     */
    public function test_is_grade_released_visible(): void {
        $this->resetAfterTest();

        $plugingen = $this->getDataGenerator()->get_plugin_generator('local_unifiedgrader');
        $s = $this->create_scenario();

        // Create submission and grade.
        $this->setUser($s->scenario->students[0]);
        $plugingen->create_assign_submission($s->scenario->activity, $s->scenario->students[0]->id);
        $this->setUser($s->scenario->teacher);
        $s->adapter->save_grade($s->scenario->students[0]->id, 80.0, '');

        $this->assertTrue($s->adapter->is_grade_released($s->scenario->students[0]->id));
    }

    /**
     * Test get_effective_duedate returns global duedate when no override.
     */
    public function test_get_effective_duedate_global(): void {
        $this->resetAfterTest();

        $duedate = time() + DAYSECS * 7;
        $s = $this->create_scenario(['modparams' => ['duedate' => $duedate]]);

        $effective = $s->adapter->get_effective_duedate($s->scenario->students[0]->id);
        $this->assertEquals($duedate, $effective);
    }

    /**
     * Test get_effective_duedate returns override when set.
     */
    public function test_get_effective_duedate_override(): void {
        global $DB;
        $this->resetAfterTest();

        $s = $this->create_scenario();
        $instance = $DB->get_record('assign', ['id' => $s->scenario->activity->id]);

        // Create user override.
        $overridedate = time() + DAYSECS * 14;
        $DB->insert_record('assign_overrides', (object) [
            'assignid' => $instance->id,
            'userid' => $s->scenario->students[0]->id,
            'duedate' => $overridedate,
        ]);

        $effective = $s->adapter->get_effective_duedate($s->scenario->students[0]->id);
        $this->assertEquals($overridedate, $effective);
    }

    /**
     * Test supports_feature for onlinetext.
     */
    public function test_supports_feature_onlinetext(): void {
        $this->resetAfterTest();

        $s = $this->create_scenario(['modparams' => ['assignsubmission_onlinetext_enabled' => 1]]);
        $this->assertTrue($s->adapter->supports_feature('onlinetext'));
    }

    /**
     * Test perform_submission_action with invalid action throws.
     */
    public function test_perform_submission_action_invalid_throws(): void {
        $this->resetAfterTest();

        $s = $this->create_scenario();

        $this->expectException(\moodle_exception::class);
        $s->adapter->perform_submission_action($s->scenario->students[0]->id, 'nonexistent');
    }

    /**
     * Reverting an ungraded submission to draft must surface as 'draft' in
     * the participant list. revert_to_draft creates a placeholder assign_grades
     * row with grade=null even when the teacher never scored the work, so this
     * pins down that the no-grade path still resolves to 'draft' and doesn't
     * stay on 'submitted' or fall through to 'graded'.
     */
    public function test_get_participants_reverted_ungraded_submission_shows_draft(): void {
        $this->resetAfterTest();

        $plugingen = $this->getDataGenerator()->get_plugin_generator('local_unifiedgrader');
        $s = $this->create_scenario();
        $student = $s->scenario->students[0];

        $this->setUser($student);
        $plugingen->create_assign_submission($s->scenario->activity, $student->id);

        $this->setUser($s->scenario->teacher);
        $s->adapter->perform_submission_action($student->id, 'revert_to_draft');

        $participants = $s->adapter->get_participants();
        $entry = null;
        foreach ($participants as $p) {
            if ((int) $p['id'] === (int) $student->id) {
                $entry = $p;
                break;
            }
        }
        $this->assertNotNull($entry, 'student missing from participants');
        $this->assertSame('draft', $entry['status']);
    }

    /**
     * Reverting a graded submission to draft must surface as 'draft' in the
     * participant list. The assign_grades row outlives the revert action, so
     * resolve_status used to short-circuit on the grade and keep returning
     * 'graded' — the marking panel pill said 'Draft' while the participant
     * list still said 'Graded' for the same student.
     */
    public function test_get_participants_reverted_graded_submission_shows_draft(): void {
        $this->resetAfterTest();

        $plugingen = $this->getDataGenerator()->get_plugin_generator('local_unifiedgrader');
        $s = $this->create_scenario();
        $student = $s->scenario->students[0];

        // Student submits.
        $this->setUser($student);
        $plugingen->create_assign_submission($s->scenario->activity, $student->id);

        // Teacher grades, then reverts to draft.
        $this->setUser($s->scenario->teacher);
        $s->adapter->save_grade($student->id, 85.0, '<p>Initial grade.</p>');
        $s->adapter->perform_submission_action($student->id, 'revert_to_draft');

        $participants = $s->adapter->get_participants();
        $entry = null;
        foreach ($participants as $p) {
            if ((int) $p['id'] === (int) $student->id) {
                $entry = $p;
                break;
            }
        }
        $this->assertNotNull($entry, 'student missing from participants');
        $this->assertSame('draft', $entry['status']);
    }

    /**
     * Test get_user_override returns null when no override exists.
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
        $instance = $DB->get_record('assign', ['id' => $s->scenario->activity->id]);

        $overridedate = time() + DAYSECS * 14;
        $DB->insert_record('assign_overrides', (object) [
            'assignid' => $instance->id,
            'userid' => $s->scenario->students[0]->id,
            'duedate' => $overridedate,
        ]);

        $override = $s->adapter->get_user_override($s->scenario->students[0]->id);
        $this->assertNotNull($override);
        $this->assertArrayHasKey('id', $override);
        $this->assertEquals($overridedate, $override['duedate']);
    }

    /**
     * Test are_grades_posted defaults to true (visible).
     */
    public function test_are_grades_posted_default(): void {
        $this->resetAfterTest();

        $s = $this->create_scenario();
        $this->assertTrue($s->adapter->are_grades_posted());
    }

    /**
     * Test set_grades_posted and are_grades_posted.
     */
    public function test_set_grades_posted(): void {
        $this->resetAfterTest();

        $s = $this->create_scenario();

        // Hide grades.
        $s->adapter->set_grades_posted(1);
        $this->assertFalse($s->adapter->are_grades_posted());

        // Show grades.
        $s->adapter->set_grades_posted(0);
        $this->assertTrue($s->adapter->are_grades_posted());
    }

    /**
     * Test get_type returns 'assign'.
     */
    public function test_get_type(): void {
        $this->resetAfterTest();

        $s = $this->create_scenario();
        $this->assertEquals('assign', $s->adapter->get_type());
    }

    /**
     * Test get_participants returns correct participant structure.
     */
    public function test_get_participants_structure(): void {
        $this->resetAfterTest();

        $s = $this->create_scenario(['studentcount' => 1]);
        $participants = $s->adapter->get_participants();

        $this->assertCount(1, $participants);
        $p = $participants[0];

        $expectedkeys = ['id', 'fullname', 'email', 'profileimageurl', 'status',
            'submittedat', 'gradevalue', 'locked', 'hasoverride', 'hasextension', 'islate'];
        foreach ($expectedkeys as $key) {
            $this->assertArrayHasKey($key, $p, "Missing key: {$key}");
        }
    }

    /**
     * A null grade must clear the stored mark, not be silently ignored.
     *
     * Typing "-" in the grade field is the teacher's "ungrade me" signal and
     * arrives here as null. assign::apply_grade_to_user() guards its write with
     * isset($formdata->grade), which is false for null, so routing a clear
     * through assign::save_grade() dropped it and left the previous mark in
     * place - the teacher saw the field blank until they reloaded.
     */
    public function test_null_grade_clears_the_stored_mark(): void {
        $this->resetAfterTest();

        $s = $this->create_scenario();
        $student = $s->scenario->students[0];

        $s->adapter->save_grade($student->id, 15.0, '', FORMAT_HTML);
        $this->assertEqualsWithDelta(
            15.0,
            $s->adapter->get_grade_data($student->id)['grade'],
            0.01,
            'Precondition: the grade of 15 must be stored.',
        );

        $s->adapter->save_grade($student->id, null, '', FORMAT_HTML);

        $this->assertNull(
            $s->adapter->get_grade_data($student->id)['grade'],
            'A null grade must clear the mark rather than leave the previous one.',
        );
    }

    /**
     * Clearing a grade must survive a fresh read, not just the in-memory object.
     *
     * The Behat scenario that caught this only failed after a page reload, so
     * the assertion has to come from a newly constructed adapter rather than
     * the one that performed the write.
     */
    public function test_cleared_grade_stays_cleared_on_reload(): void {
        $this->resetAfterTest();

        $s = $this->create_scenario();
        $student = $s->scenario->students[0];

        $s->adapter->save_grade($student->id, 15.0, '', FORMAT_HTML);
        $s->adapter->save_grade($student->id, null, '', FORMAT_HTML);

        // A fresh adapter, as a page reload would build.
        $reloaded = adapter_factory::create($s->scenario->cm->id);

        $this->assertNull(
            $reloaded->get_grade_data($student->id)['grade'],
            'The cleared grade must still be clear when the page is reloaded.',
        );
    }

    /**
     * With "Feedback comments" disabled, mod_assign silently discards feedback
     * text. A save carrying real feedback must be refused before anything is
     * written, rather than reporting success and losing the text.
     */
    public function test_save_grade_refuses_feedback_when_comments_disabled(): void {
        global $DB;
        $this->resetAfterTest();

        $plugingen = $this->getDataGenerator()->get_plugin_generator('local_unifiedgrader');
        $s = $this->create_scenario(['modparams' => ['assignfeedback_comments_enabled' => 0]]);
        $studentid = (int) $s->scenario->students[0]->id;
        $this->setUser($s->scenario->students[0]);
        $plugingen->create_assign_submission($s->scenario->activity, $studentid);
        $this->setUser($s->scenario->teacher);

        $this->assertFalse($s->adapter->has_feedback_plugin('comments'), 'Precondition: comments disabled.');

        // Text, and media with no text, both count as feedback worth protecting.
        foreach (['<p>Well argued.</p>', '<p><img src="https://example.com/a.png" alt=""></p>'] as $feedback) {
            try {
                $s->adapter->save_grade($studentid, 70.0, $feedback);
                $this->fail('A save carrying feedback must be refused when comments are disabled.');
            } catch (\moodle_exception $e) {
                $this->assertEquals('error_feedback_comments_disabled', $e->errorcode);
            }
        }

        // Refused before any write: the grade was not stored either.
        $grade = $DB->get_record('assign_grades', [
            'assignment' => $s->scenario->activity->id,
            'userid' => $studentid,
        ]);
        $this->assertTrue(!$grade || $grade->grade === null || (float) $grade->grade < 0);
    }

    /**
     * Blank feedback is what the hidden editor sends when comments are disabled,
     * so it must still save the grade, and must not create a comment row.
     */
    public function test_save_grade_with_blank_feedback_when_comments_disabled(): void {
        global $DB;
        $this->resetAfterTest();

        $plugingen = $this->getDataGenerator()->get_plugin_generator('local_unifiedgrader');
        $s = $this->create_scenario(['modparams' => ['assignfeedback_comments_enabled' => 0]]);
        $studentid = (int) $s->scenario->students[0]->id;
        $this->setUser($s->scenario->students[0]);
        $plugingen->create_assign_submission($s->scenario->activity, $studentid);
        $this->setUser($s->scenario->teacher);

        foreach (['', '<p></p>', '<p>&nbsp;</p>'] as $blank) {
            $this->assertTrue($s->adapter->save_grade($studentid, 70.0, $blank, FORMAT_HTML, [], 12345));
        }

        $this->assertEqualsWithDelta(70.0, $s->adapter->get_grade_data($studentid)['grade'], 0.01);
        $this->assertSame(0, $DB->count_records('assignfeedback_comments', [
            'assignment' => $s->scenario->activity->id,
        ]));
    }

    /**
     * Blank feedback on a comments-disabled assignment leaves a draft file where it is.
     *
     * The test above cannot tell: a disabled comments plugin never writes a comment row, with or
     * without save_grade() zeroing the draft item id. This one puts a real file in the draft area,
     * which the draft rewrite would otherwise move into the disabled plugin's file area, where no
     * comment row owns it.
     */
    public function test_blank_feedback_when_comments_disabled_moves_no_draft_file(): void {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        $this->resetAfterTest();

        $plugingen = $this->getDataGenerator()->get_plugin_generator('local_unifiedgrader');
        $s = $this->create_scenario(['modparams' => ['assignfeedback_comments_enabled' => 0]]);
        $studentid = (int) $s->scenario->students[0]->id;
        $this->setUser($s->scenario->students[0]);
        $plugingen->create_assign_submission($s->scenario->activity, $studentid);
        $this->setUser($s->scenario->teacher);

        $draftitemid = file_get_unused_draft_itemid();
        $fs = get_file_storage();
        $usercontext = \context_user::instance($s->scenario->teacher->id);
        $fs->create_file_from_string([
            'contextid' => $usercontext->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftitemid,
            'filepath' => '/',
            'filename' => 'stays.txt',
        ], 'should stay in the draft area');
        $this->assertCount(
            1,
            $fs->get_area_files($usercontext->id, 'user', 'draft', $draftitemid, 'id', false),
            'Precondition: the draft area holds a file that a save could move.'
        );

        $this->assertTrue($s->adapter->save_grade($studentid, 70.0, '', FORMAT_HTML, [], $draftitemid));

        $this->assertSame(
            [],
            $fs->get_area_files($s->scenario->context->id, 'assignfeedback_comments', 'feedback', false, 'id', false),
            'No file may be moved into the disabled comments plugin\'s file area.'
        );
    }

    /**
     * Seed a student with two submitted attempts (0 and 1, 1 being latest).
     *
     * Mirrors the Behat "has N graded submission attempts" step: the core
     * generator has no attempt-number control, so the second attempt row is
     * inserted directly, as a manual reopen would produce it.
     *
     * @param \stdClass $scenario The grading scenario.
     * @param int $userid The student user ID.
     */
    private function seed_two_attempts(\stdClass $scenario, int $userid): void {
        global $DB;

        $plugingen = $this->getDataGenerator()->get_plugin_generator('local_unifiedgrader');
        $first = $plugingen->create_assign_submission($scenario->activity, $userid);
        $first = $DB->get_record('assign_submission', ['id' => $first->id], '*', MUST_EXIST);

        $second = clone $first;
        unset($second->id);
        $second->attemptnumber = 1;
        $second->latest = 1;
        $second->timecreated = time();
        $DB->insert_record('assign_submission', $second);

        $first->latest = 0;
        $DB->update_record('assign_submission', $first);
    }

    /**
     * Re-saving a previous attempt must not overwrite the latest attempt's
     * feedback.
     *
     * After mod_assign saved the previous attempt, save_grade() moved the
     * editor's draft files and rewrote the comment of the LATEST attempt's
     * grade, not the attempt just graded. The latest attempt's feedback was
     * replaced by the older attempt's text. The draft item id is what reaches
     * that branch; the grader always sends one when TinyMCE is in use.
     */
    public function test_save_grade_on_previous_attempt_keeps_latest_feedback(): void {
        $this->resetAfterTest();

        $s = $this->create_scenario();
        $studentid = (int) $s->scenario->students[0]->id;
        $this->seed_two_attempts($s->scenario, $studentid);
        $this->setUser($s->scenario->teacher);

        $s->adapter->save_grade($studentid, 50.0, '<p>Attempt one feedback</p>', FORMAT_HTML, [], 0, 0, 0);
        $s->adapter->save_grade($studentid, 80.0, '<p>Attempt two feedback</p>', FORMAT_HTML, [], 0, 0, 1);

        // Revise the first attempt's feedback through the editor's draft area.
        $draftitemid = file_get_unused_draft_itemid();
        $s->adapter->save_grade($studentid, 55.0, '<p>Attempt one revised</p>', FORMAT_HTML, [], $draftitemid, 0, 0);

        $reloaded = adapter_factory::create($s->scenario->cm->id);
        $latest = $reloaded->get_grade_data_for_attempt($studentid, 1);
        $previous = $reloaded->get_grade_data_for_attempt($studentid, 0);

        $this->assertStringContainsString('Attempt two feedback', $latest['feedback']);
        $this->assertStringNotContainsString('Attempt one', $latest['feedback']);
        $this->assertStringContainsString('Attempt one revised', $previous['feedback']);
        $this->assertEqualsWithDelta(80.0, $latest['grade'], 0.01);
    }

    /**
     * The feedback editor must be loaded with the feedback of the attempt on
     * screen. prepare_feedback_draft() ignored the attempt number and always
     * loaded the latest attempt's feedback, which a save then wrote over the
     * previous attempt's own feedback.
     */
    public function test_prepare_feedback_draft_loads_requested_attempt(): void {
        $this->resetAfterTest();

        $s = $this->create_scenario();
        $studentid = (int) $s->scenario->students[0]->id;
        $this->seed_two_attempts($s->scenario, $studentid);
        $this->setUser($s->scenario->teacher);

        $s->adapter->save_grade($studentid, 50.0, '<p>Attempt one feedback</p>', FORMAT_HTML, [], 0, 0, 0);
        $s->adapter->save_grade($studentid, 80.0, '<p>Attempt two feedback</p>', FORMAT_HTML, [], 0, 0, 1);

        $draftitemid = file_get_unused_draft_itemid();

        $previous = $s->adapter->prepare_feedback_draft($studentid, $draftitemid, 0);
        $this->assertStringContainsString('Attempt one feedback', $previous['feedbackhtml']);
        $this->assertStringNotContainsString('Attempt two', $previous['feedbackhtml']);

        $latest = $s->adapter->prepare_feedback_draft($studentid, $draftitemid, -1);
        $this->assertStringContainsString('Attempt two feedback', $latest['feedbackhtml']);
    }

    /**
     * An activity name containing an ampersand comes back as plain text.
     *
     * Every sink this value reaches escapes it — Mustache's {{ }} in the header,
     * textContent in the marking panel, encodeURIComponent in the report-form
     * URL — so escaping it here as well is what put a literal "&amp;" on screen.
     */
    public function test_activity_name_is_not_html_escaped(): void {
        $this->resetAfterTest();

        $s = $this->create_scenario(['modparams' => ['name' => 'Grief & Loss Counselling']]);
        $info = $s->adapter->get_activity_info();

        $this->assertSame('Grief & Loss Counselling', $info['name']);
        $this->assertStringNotContainsString('&amp;', $info['name']);
    }

    /**
     * Turning escaping off does not let markup through: format_string() strips
     * tags whatever the escape option says, so the unescaped name is still safe
     * to hand to a sink that will escape it once.
     */
    public function test_activity_name_still_strips_markup_when_unescaped(): void {
        $this->resetAfterTest();

        $s = $this->create_scenario(['modparams' => ['name' => 'Grief <script>alert(1)</script> Loss']]);
        $info = $s->adapter->get_activity_info();

        $this->assertStringNotContainsString('<script>', $info['name']);
        $this->assertStringNotContainsString('</script>', $info['name']);
    }
}
