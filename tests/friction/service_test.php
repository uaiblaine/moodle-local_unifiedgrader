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

namespace local_unifiedgrader\friction;

use local_unifiedgrader\adapter\adapter_factory;

/**
 * Friction Feedback holds a posted mark until the student reads it, or a clock opens it.
 *
 * @package    local_unifiedgrader
 * @category   test
 * @copyright  2026 South African Theological Seminary (mathieu@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_unifiedgrader\friction\service
 */
final class service_test extends \advanced_testcase {
    /**
     * With the site setting off, posting still reveals the cell.
     */
    public function test_friction_off_posts_the_cell(): void {
        $this->resetAfterTest();
        $built = $this->graded_assignment();
        $student = $built->scenario->students[0];

        $built->adapter->set_grades_posted(0, [$student->id]);

        $itemid = $built->adapter->get_grade_item()->id;
        $this->assertSame(0, $this->cell_hidden($itemid, $student->id));
        $this->assertFalse(service::is_pending_user($built->adapter, $student->id));
    }

    /**
     * Posting with friction on hides the cell, opens the feedback page, and holds the mail.
     */
    public function test_posting_holds_the_cell_and_the_mail(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_friction();
        $built = $this->graded_assignment();
        $student = $built->scenario->students[0];
        $params = ['assignment' => $built->scenario->activity->id, 'userid' => $student->id];
        if ($DB->record_exists('assign_user_flags', $params)) {
            $DB->set_field('assign_user_flags', 'mailed', 0, $params);
        }

        $built->adapter->set_grades_posted(0, [$student->id]);

        $itemid = $built->adapter->get_grade_item()->id;
        $this->assertSame(1, $this->cell_hidden($itemid, $student->id));
        $this->assertTrue(service::is_pending_user($built->adapter, $student->id));
        $this->setUser($student);
        $this->assertTrue($built->adapter->is_grade_released($student->id));
        $this->assertSame(1, (int) $DB->get_field('assign_user_flags', 'mailed', $params));

        $this->setUser($built->scenario->teacher);
        $status = $built->adapter->posting_status();
        $this->assertSame(1, $status['postedcount']);
        $rows = [];
        foreach ($built->adapter->get_participants([]) as $row) {
            $rows[$row['id']] = $row['gradehidden'];
        }
        $this->assertSame(0, $rows[$student->id]);
    }

    /**
     * Under marking workflow the walk is keyed to the mark the post released.
     *
     * Releasing a ready-for-release student pushes the mark to the gradebook,
     * which rewrites the cell's timemodified. The walk stores that value as the
     * mark it belongs to, so the push has to come before the walk is opened, or
     * the next post of the same mark no longer finds the walk and starts another.
     */
    public function test_marking_workflow_post_keys_the_walk_to_the_released_mark(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        $this->resetAfterTest();
        $this->enable_friction();

        $plugingen = $this->getDataGenerator()->get_plugin_generator('local_unifiedgrader');
        $scenario = $plugingen->create_grading_scenario('assign', [
            'studentcount' => 1,
            'modparams' => ['markingworkflow' => 1],
        ]);
        $adapter = adapter_factory::create($scenario->cm->id);
        $student = $scenario->students[0];
        $this->setUser($student);
        $plugingen->create_assign_submission($scenario->activity, $student->id);
        $this->setUser($scenario->teacher);
        $adapter->save_grade($student->id, 80.0, '<p>Marked.</p>');
        $DB->set_field('assign_user_flags', 'workflowstate', ASSIGN_MARKING_WORKFLOW_STATE_READYFORRELEASE, [
            'assignment' => $scenario->activity->id,
            'userid' => $student->id,
        ]);
        $itemid = $adapter->get_grade_item()->id;
        // An older stamp than the post can write, so a stale key cannot match by luck.
        $DB->set_field('grade_grades', 'timemodified', 1000, ['itemid' => $itemid, 'userid' => $student->id]);

        $adapter->set_grades_posted(0, [$student->id]);

        $cellstamp = (int) $DB->get_field('grade_grades', 'timemodified', ['itemid' => $itemid, 'userid' => $student->id]);
        $this->assertNotSame(1000, $cellstamp, 'Precondition: the post pushed the mark.');
        $walk = $DB->get_record('local_unifiedgrader_friction', [
            'itemid' => $itemid,
            'userid' => $student->id,
            'state' => service::STATE_PENDING,
        ], '*', MUST_EXIST);
        $this->assertSame($cellstamp, (int) $walk->gradetimemodified);

        $adapter->set_grades_posted(0, [$student->id]);
        $this->assertSame(
            [(int) $walk->id],
            array_map('intval', $DB->get_fieldset_select(
                'local_unifiedgrader_friction',
                'id',
                'itemid = ? AND userid = ?',
                [$itemid, $student->id],
            )),
            'Posting the same mark again keeps the same walk.'
        );
    }

    /**
     * An activity can force the walk off, or on, against the site default.
     */
    public function test_activity_mode_overrides_the_site(): void {
        $this->resetAfterTest();
        $this->enable_friction();
        $built = $this->graded_assignment();
        $student = $built->scenario->students[0];
        $itemid = $built->adapter->get_grade_item()->id;

        service::set_mode($built->adapter->get_cmid(), service::MODE_OFF);
        $built->adapter->set_grades_posted(0, [$student->id]);
        $this->assertSame(0, $this->cell_hidden($itemid, $student->id));
        $this->assertFalse(service::is_pending_user($built->adapter, $student->id));

        set_config('friction_enable', 0, 'local_unifiedgrader');
        service::set_mode($built->adapter->get_cmid(), service::MODE_ON);
        $built->adapter->set_grades_posted(0, [$student->id]);
        $this->assertSame(1, $this->cell_hidden($itemid, $student->id));
        $this->assertSame(service::MODE_ON, service::mode($built->adapter->get_cmid()));

        service::set_mode($built->adapter->get_cmid(), service::MODE_INHERIT);
        $this->assertSame(service::MODE_INHERIT, service::mode($built->adapter->get_cmid()));
        $this->assertFalse(service::applies($built->adapter->get_cmid()));
    }

    /**
     * A mark with nothing to read is revealed at post time.
     */
    public function test_no_steps_reveals_at_post_time(): void {
        $this->resetAfterTest();
        $this->enable_friction();
        $plugingen = $this->getDataGenerator()->get_plugin_generator('local_unifiedgrader');
        $scenario = $plugingen->create_grading_scenario('assign', ['studentcount' => 1]);
        $adapter = adapter_factory::create($scenario->cm->id);
        $student = $scenario->students[0];
        $this->setUser($student);
        $plugingen->create_assign_submission($scenario->activity, $student->id);
        $this->setUser($scenario->teacher);
        $adapter->save_grade($student->id, 80.0, '');

        $adapter->set_grades_posted(0, [$student->id]);

        $this->assertSame([], service::steps($adapter, $student->id));
        $this->assertSame(0, $this->cell_hidden($adapter->get_grade_item()->id, $student->id));
        $this->assertFalse(service::is_pending_user($adapter, $student->id));
    }

    /**
     * The last tick opens the cell. A newer mark starts the walk again.
     */
    public function test_finishing_reveals_and_a_new_mark_holds_again(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_friction();
        $built = $this->graded_assignment();
        $student = $built->scenario->students[0];
        $itemid = $built->adapter->get_grade_item()->id;
        $built->adapter->set_grades_posted(0, [$student->id]);

        $this->setUser($student);
        $result = service::advance($built->adapter->get_cmid(), $student->id);
        $this->assertTrue($result['finished']);
        $this->assertSame(0, $this->cell_hidden($itemid, $student->id));
        $this->assertFalse(service::is_pending_user($built->adapter, $student->id));
        $state = $DB->get_field('local_unifiedgrader_friction', 'state', [
            'itemid' => $itemid,
            'userid' => $student->id,
        ]);
        $this->assertSame(service::STATE_COMPLETE, $state);

        $page = service::present($built->adapter, $student->id, ['gradedisplay' => '80 / 100']);
        $this->assertFalse($page['frictionactive']);

        $this->setUser($built->scenario->teacher);
        $built->adapter->save_grade($student->id, 70.0, '<p>Read this again.</p>');
        // The new mark has to carry a different clock from the finished walk.
        // A save in the same second would otherwise look like the same mark.
        $DB->set_field('local_unifiedgrader_friction', 'gradetimemodified', time() - 100, [
            'itemid' => $itemid,
            'userid' => $student->id,
        ]);
        $built->adapter->set_grades_posted(0, [$student->id]);
        $this->assertSame(1, $this->cell_hidden($itemid, $student->id));
        $step = $DB->get_field('local_unifiedgrader_friction', 'step', [
            'itemid' => $itemid,
            'userid' => $student->id,
            'state' => service::STATE_PENDING,
        ]);
        $this->assertSame(0, (int) $step);
    }

    /**
     * While the walk is open the feedback page has the comment and not the mark.
     */
    public function test_pending_page_omits_the_mark(): void {
        $this->resetAfterTest();
        $this->enable_friction();
        $built = $this->graded_assignment();
        $student = $built->scenario->students[0];
        $built->adapter->set_grades_posted(0, [$student->id]);

        $this->setUser($student);
        $page = service::present($built->adapter, $student->id, [
            'gradedisplay' => '80 / 100 (80%)',
            'feedback' => '<p>Marked.</p>',
            'hasfeedback' => true,
            'feedbackdownloadurl' => 'https://example.test/download',
            'penaltycard' => ['haspenalties' => true, 'finaldisplay' => '70 / 100 (70%)'],
        ]);
        $this->assertTrue($page['frictionactive']);
        $this->assertSame('', $page['gradedisplay']);
        $this->assertSame('', $page['feedbackdownloadurl']);
        $this->assertNull($page['penaltycard']);
        $this->assertStringNotContainsString('80 / 100', $page['frictionstepsjson']);
        $this->assertStringContainsString('Marked.', $page['frictionstepsjson']);
    }

    /**
     * A teacher hide drops the walk, and the fail-safe does not open that cell.
     */
    public function test_teacher_hide_is_left_hidden(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_friction();
        $built = $this->graded_assignment();
        $student = $built->scenario->students[0];
        $itemid = $built->adapter->get_grade_item()->id;
        $built->adapter->set_grades_posted(0, [$student->id]);
        $this->assertTrue(service::is_pending_user($built->adapter, $student->id));

        $built->adapter->set_grades_posted(1, [$student->id]);
        $this->assertFalse(service::is_pending_user($built->adapter, $student->id));
        $course = $built->scenario->course;
        $course->enddate = time() - DAYSECS;
        $DB->update_record('course', $course);

        $this->assertSame(0, service::open_due());
        $this->assertSame(1, $this->cell_hidden($itemid, $student->id));
    }

    /**
     * The stored course total keeps a hidden mark. The student-facing pass drops it.
     */
    public function test_stored_course_total_includes_the_hidden_grade(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_friction();
        $built = $this->graded_assignment();
        $student = $built->scenario->students[0];
        $course = $built->scenario->course;
        $built->adapter->set_grades_posted(0, [$student->id]);
        $item = $built->adapter->get_grade_item();

        grade_regrade_final_grades($course->id);
        $courseitem = \grade_item::fetch_course_item($course->id);
        $stored = \grade_grade::fetch(['itemid' => $courseitem->id, 'userid' => $student->id]);
        $this->assertNotNull($stored);
        $this->assertNotNull($stored->finalgrade);
        $assigngrade = \grade_grade::fetch(['itemid' => $item->id, 'userid' => $student->id]);
        $this->assertEqualsWithDelta((float) $assigngrade->finalgrade, (float) $stored->finalgrade, 0.01);

        $items = \grade_item::fetch_all(['courseid' => $course->id]);
        $grades = [];
        $records = $DB->get_records_sql(
            "SELECT g.*
               FROM {grade_grades} g
               JOIN {grade_items} gi ON gi.id = g.itemid
              WHERE g.userid = :userid AND gi.courseid = :courseid",
            ['userid' => $student->id, 'courseid' => $course->id],
        );
        foreach ($records as $record) {
            $grades[$record->itemid] = new \grade_grade($record, false);
        }
        foreach ($items as $itemid => $unused) {
            if (!isset($grades[$itemid])) {
                $empty = new \grade_grade();
                $empty->userid = $student->id;
                $empty->itemid = $items[$itemid]->id;
                $grades[$itemid] = $empty;
            }
            $grades[$itemid]->grade_item = $items[$itemid];
        }
        $affected = \grade_grade::get_hiding_affected($grades, $items);
        $this->assertArrayHasKey($item->id, $affected['altered']);
        $this->assertNull($affected['altered'][$item->id]);
        $this->assertSame('dropped', $affected['alteredaggregationstatus'][$item->id]);
        $this->assertArrayHasKey($courseitem->id, $affected['altered']);
        $shown = $affected['altered'][$courseitem->id];
        $this->assertTrue($shown === null || (float) $shown !== (float) $stored->finalgrade);
    }

    /**
     * The daily task opens a pending cell on the earlier of the two clocks.
     */
    public function test_failsafe_opens_on_the_earlier_clock(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_friction();
        set_config('friction_daysbeforeend', 14, 'local_unifiedgrader');
        set_config('friction_daysafterpost', 21, 'local_unifiedgrader');

        $waiting = $this->post_one();
        $this->set_enddate($waiting->course, time() + 40 * DAYSECS);
        $this->assertSame(0, service::open_due());
        $this->assertSame(1, $this->cell_hidden($waiting->itemid, $waiting->student->id));

        $byend = $this->post_one();
        $this->set_enddate($byend->course, time() + 10 * DAYSECS);
        $this->assertGreaterThan(0, service::open_due());
        $this->assertSame(0, $this->cell_hidden($byend->itemid, $byend->student->id));
        $this->assertSame(service::STATE_WAIVED, $this->friction_state($byend->itemid, $byend->student->id));
        $this->assertSame(1, (int) $DB->get_field('assign_user_flags', 'mailed', [
            'assignment' => $byend->assignid,
            'userid' => $byend->student->id,
        ]));

        $byage = $this->post_one();
        $this->set_enddate($byage->course, 0);
        $DB->set_field('local_unifiedgrader_friction', 'gradetimemodified', time() - 22 * DAYSECS, [
            'itemid' => $byage->itemid,
            'userid' => $byage->student->id,
        ]);
        service::open_due();
        $this->assertSame(0, $this->cell_hidden($byage->itemid, $byage->student->id));

        $both = $this->post_one();
        $this->set_enddate($both->course, time() + 40 * DAYSECS);
        $DB->set_field('local_unifiedgrader_friction', 'gradetimemodified', time() - 22 * DAYSECS, [
            'itemid' => $both->itemid,
            'userid' => $both->student->id,
        ]);
        service::open_due();
        $this->assertSame(0, $this->cell_hidden($both->itemid, $both->student->id));

        $past = $this->post_one();
        $this->set_enddate($past->course, time() - DAYSECS);
        service::open_due();
        $this->assertSame(0, $this->cell_hidden($past->itemid, $past->student->id));
        $this->assertSame(service::STATE_WAIVED, $this->friction_state($past->itemid, $past->student->id));
    }

    /**
     * A quiz student post leaves the review options hiding marks. Finishing the
     * walk shows that student's gradebook mark and keeps Unified Grader feedback
     * open. Another student's mark stays hidden. A later grade sync does not
     * hide it again. Hiding the class closes it. Posting the same mark again
     * opens the page without a second walk.
     */
    public function test_quiz_walk_reveals_that_students_gradebook_mark(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        $this->enable_friction();
        $plugingen = $this->getDataGenerator()->get_plugin_generator('local_unifiedgrader');
        $scenario = $plugingen->create_grading_scenario('quiz', ['studentcount' => 2]);
        $adapter = adapter_factory::create($scenario->cm->id);
        $student = $scenario->students[0];
        $other = $scenario->students[1];
        $this->setUser($scenario->teacher);

        $item = $adapter->get_grade_item();
        $grade = new \grade_grade(['itemid' => $item->id, 'userid' => $student->id]);
        $grade->finalgrade = 63;
        $grade->rawgrade = 63;
        $grade->rawgrademin = $item->grademin;
        $grade->rawgrademax = $item->grademax;
        $grade->feedback = '<p>Read the essay.</p>';
        $grade->feedbackformat = FORMAT_HTML;
        $grade->hidden = 0;
        $grade->insert('phpunit');
        $DB->insert_record('quiz_grades', (object) [
            'quiz' => $scenario->activity->id,
            'userid' => $student->id,
            'grade' => 63,
            'timemodified' => time(),
        ]);
        $adapter->set_grades_posted(1);
        $fields = 'reviewmarks, reviewmaxmarks, reviewoverallfeedback';
        $bits = $DB->get_record('quiz', ['id' => $scenario->activity->id], $fields, MUST_EXIST);

        $adapter->set_grades_posted(0, [$student->id]);

        $item = $adapter->get_grade_item();
        $this->assertTrue($item->is_hidden());
        $this->assertEquals($bits, $DB->get_record('quiz', ['id' => $scenario->activity->id], $fields, MUST_EXIST));
        $this->assertTrue(service::is_pending_user($adapter, $student->id));
        $this->assertSame(1, $this->cell_hidden($item->id, $student->id));
        $this->setUser($student);
        $this->assertTrue($adapter->is_grade_released($student->id));

        // The other student already has a visible cell. The item hide is all
        // that covers it, which is what a quiz grade push leaves behind.
        $othergrade = new \grade_grade(['itemid' => $item->id, 'userid' => $other->id]);
        $othergrade->finalgrade = 40;
        $othergrade->rawgrade = 40;
        $othergrade->rawgrademin = $item->grademin;
        $othergrade->rawgrademax = $item->grademax;
        $othergrade->hidden = 0;
        $othergrade->insert('phpunit');
        $DB->insert_record('quiz_grades', (object) [
            'quiz' => $scenario->activity->id,
            'userid' => $other->id,
            'grade' => 40,
            'timemodified' => time(),
        ]);

        $result = service::advance($adapter->get_cmid(), $student->id);
        $this->assertTrue($result['finished']);
        $this->assertFalse(service::is_pending_user($adapter, $student->id));
        $this->assertSame(0, $this->cell_hidden($item->id, $student->id));
        $item = \grade_item::fetch(['id' => $item->id]);
        $this->assertFalse($item->is_hidden());
        $this->assertFalse($this->gradebook_hides($item->id, $student->id));
        $this->assertSame(1, $this->cell_hidden($item->id, $other->id));
        $this->assertTrue($this->gradebook_hides($item->id, $other->id));
        $this->assertEquals($bits, $DB->get_record('quiz', ['id' => $scenario->activity->id], $fields, MUST_EXIST));
        $this->assertTrue($adapter->is_grade_released($student->id));
        $this->assertFalse($adapter->is_grade_released($other->id));
        $page = service::present($adapter, $student->id, ['gradedisplay' => '63 / 70']);
        $this->assertFalse($page['frictionactive']);
        $this->assertSame('63 / 70', $page['gradedisplay']);

        require_once($CFG->dirroot . '/mod/quiz/lib.php');
        $quiz = $DB->get_record('quiz', ['id' => $scenario->activity->id], '*', MUST_EXIST);
        quiz_grade_item_update($quiz);
        $item = \grade_item::fetch(['id' => $item->id]);
        $this->assertFalse($item->is_hidden());
        $this->assertFalse($this->gradebook_hides($item->id, $student->id));
        $this->assertTrue($this->gradebook_hides($item->id, $other->id));
        $this->assertEquals($bits, $DB->get_record('quiz', ['id' => $scenario->activity->id], $fields, MUST_EXIST));

        $this->setUser($scenario->teacher);
        $status = $adapter->posting_status();
        $this->assertTrue($status['partial']);
        $this->assertSame(1, $status['postedcount']);
        $this->assertSame(2, $status['total']);
        $rows = [];
        foreach ($adapter->get_participants([]) as $row) {
            $rows[$row['id']] = $row['gradehidden'];
        }
        $this->assertSame(0, $rows[$student->id]);
        $this->assertSame(1, $rows[$other->id]);

        $adapter->set_grades_posted(1);
        $this->setUser($student);
        $this->assertFalse($adapter->is_grade_released($student->id));
        $this->setUser($scenario->teacher);
        $this->assertFalse($adapter->posting_status()['posted']);

        $adapter->set_grades_posted(0, [$student->id]);
        $this->assertFalse(service::is_pending_user($adapter, $student->id));
        $this->assertSame(service::STATE_COMPLETE, $this->friction_state($item->id, $student->id));
        $item = \grade_item::fetch(['id' => $item->id]);
        $this->assertFalse($item->is_hidden());
        $this->assertFalse($this->gradebook_hides($item->id, $student->id));
        $this->assertTrue($this->gradebook_hides($item->id, $other->id));
        $this->setUser($student);
        $this->assertTrue($adapter->is_grade_released($student->id));
        $this->setUser($scenario->teacher);
        $status = $adapter->posting_status();
        $this->assertTrue($status['partial']);
        $this->assertSame(1, $status['postedcount']);
    }

    /**
     * The fail-safe opens a quiz mark the review options still hide.
     */
    public function test_quiz_failsafe_reveals_the_gradebook_mark(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_friction();
        $plugingen = $this->getDataGenerator()->get_plugin_generator('local_unifiedgrader');
        $scenario = $plugingen->create_grading_scenario('quiz', ['studentcount' => 1]);
        $adapter = adapter_factory::create($scenario->cm->id);
        $student = $scenario->students[0];
        $this->setUser($scenario->teacher);

        $item = $adapter->get_grade_item();
        $grade = new \grade_grade(['itemid' => $item->id, 'userid' => $student->id]);
        $grade->finalgrade = 63;
        $grade->rawgrade = 63;
        $grade->rawgrademin = $item->grademin;
        $grade->rawgrademax = $item->grademax;
        $grade->feedback = '<p>Read the essay.</p>';
        $grade->feedbackformat = FORMAT_HTML;
        $grade->hidden = 0;
        $grade->insert('phpunit');
        $DB->insert_record('quiz_grades', (object) [
            'quiz' => $scenario->activity->id,
            'userid' => $student->id,
            'grade' => 63,
            'timemodified' => time(),
        ]);
        $adapter->set_grades_posted(1);
        $fields = 'reviewmarks, reviewmaxmarks, reviewoverallfeedback';
        $bits = $DB->get_record('quiz', ['id' => $scenario->activity->id], $fields, MUST_EXIST);
        $adapter->set_grades_posted(0, [$student->id]);
        $this->set_enddate($scenario->course, time() - DAYSECS);

        $this->assertGreaterThan(0, service::open_due());
        $item = \grade_item::fetch(['id' => $item->id]);
        $this->assertFalse($item->is_hidden());
        $this->assertSame(0, $this->cell_hidden($item->id, $student->id));
        $this->assertFalse($this->gradebook_hides($item->id, $student->id));
        $this->assertSame(service::STATE_WAIVED, $this->friction_state($item->id, $student->id));
        $this->assertEquals($bits, $DB->get_record('quiz', ['id' => $scenario->activity->id], $fields, MUST_EXIST));
        $this->setUser($student);
        $this->assertTrue($adapter->is_grade_released($student->id));
    }

    /**
     * The upgrade repair opens a finished walk whose cell is already open and
     * whose quiz item is still hidden. A finished walk whose cell is still
     * hidden stays hidden.
     */
    public function test_upgrade_repair_reveals_a_finished_open_quiz_cell(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_friction();
        $plugingen = $this->getDataGenerator()->get_plugin_generator('local_unifiedgrader');
        $scenario = $plugingen->create_grading_scenario('quiz', ['studentcount' => 2]);
        $adapter = adapter_factory::create($scenario->cm->id);
        $student = $scenario->students[0];
        $other = $scenario->students[1];
        $this->setUser($scenario->teacher);

        $item = $adapter->get_grade_item();
        $grade = new \grade_grade(['itemid' => $item->id, 'userid' => $student->id]);
        $grade->finalgrade = 63;
        $grade->rawgrade = 63;
        $grade->rawgrademin = $item->grademin;
        $grade->rawgrademax = $item->grademax;
        $grade->feedback = '<p>Read the essay.</p>';
        $grade->feedbackformat = FORMAT_HTML;
        $grade->hidden = 0;
        $grade->insert('phpunit');
        $DB->insert_record('quiz_grades', (object) [
            'quiz' => $scenario->activity->id,
            'userid' => $student->id,
            'grade' => 63,
            'timemodified' => time(),
        ]);
        $adapter->set_grades_posted(1);
        $fields = 'reviewmarks, reviewmaxmarks, reviewoverallfeedback';
        $bits = $DB->get_record('quiz', ['id' => $scenario->activity->id], $fields, MUST_EXIST);
        $adapter->set_grades_posted(0, [$student->id]);

        $othergrade = new \grade_grade(['itemid' => $item->id, 'userid' => $other->id]);
        $othergrade->finalgrade = 40;
        $othergrade->rawgrade = 40;
        $othergrade->rawgrademin = $item->grademin;
        $othergrade->rawgrademax = $item->grademax;
        $othergrade->hidden = 0;
        $othergrade->insert('phpunit');

        $row = $DB->get_record('local_unifiedgrader_friction', [
            'itemid' => $item->id,
            'userid' => $student->id,
        ], '*', MUST_EXIST);
        $row->state = service::STATE_COMPLETE;
        $row->timecompleted = time();
        $DB->update_record('local_unifiedgrader_friction', $row);

        $this->assertSame(0, service::reveal_completed_hidden());
        $item = \grade_item::fetch(['id' => $item->id]);
        $this->assertTrue($item->is_hidden());
        $this->assertSame(1, $this->cell_hidden($item->id, $student->id));

        // The old walk opened the cell and left the quiz item hidden.
        \local_unifiedgrader\grades\release::set_cell($item, $student->id, 0);
        $this->assertSame(1, service::reveal_completed_hidden());
        $item = \grade_item::fetch(['id' => $item->id]);
        $this->assertFalse($item->is_hidden());
        $this->assertFalse($this->gradebook_hides($item->id, $student->id));
        $this->assertSame(1, $this->cell_hidden($item->id, $other->id));
        $this->assertTrue($this->gradebook_hides($item->id, $other->id));
        $this->assertEquals($bits, $DB->get_record('quiz', ['id' => $scenario->activity->id], $fields, MUST_EXIST));
        $this->setUser($student);
        $this->assertTrue($adapter->is_grade_released($student->id));
    }

    /**
     * The scheduled task path opens a due cell without the gradebook library loaded.
     */
    public function test_open_due_loads_the_gradebook_library_itself(): void {
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->enable_friction();
        $due = $this->post_one();
        $this->set_enddate($due->course, time() - DAYSECS);
        $this->assertSame(service::STATE_PENDING, $this->friction_state($due->itemid, $due->student->id));

        $result = $this->run_child('open_due');

        // The state of the failing task: without it the test proves nothing.
        $this->assertFalse($result['before'], 'The child process booted with grade_item loaded.');
        $this->assertSame(1, $result['result'], json_encode($result));
        $this->assertSame(0, $this->cell_hidden($due->itemid, $due->student->id));
    }

    /**
     * The upgrade repair reads grade items without the gradebook library loaded.
     */
    public function test_reveal_completed_hidden_loads_the_gradebook_library_itself(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->enable_friction();
        $built = $this->graded_assignment();
        $student = $built->scenario->students[0];
        $built->adapter->set_grades_posted(0, [$student->id]);
        $DB->set_field('local_unifiedgrader_friction', 'state', service::STATE_COMPLETE, [
            'itemid' => $built->adapter->get_grade_item()->id,
        ]);

        $result = $this->run_child('reveal_completed_hidden');

        $this->assertFalse($result['before'], 'The child process booted with grade_item loaded.');
        $this->assertSame(0, $result['result'], json_encode($result));
    }

    /**
     * Call a service method in a child PHP process that has booted only lib/setup.php.
     *
     * The calling test commits its data first (preventResetByRollback()), or the child sees nothing.
     * Before a test starts, this process has loaded the gradebook through other plugins' lib.php files,
     * so the missing require cannot show here. The child boots as admin/tool/phpunit/cli/util.php does.
     * A boot that rebuilds the plugin caches loads every lib.php, so a first child only boots, and the
     * second one, on the caches the first left, makes the call. The call is made once: it writes.
     *
     * @param string $method Name of a static method of the service.
     * @return array Keys: before (bool), result (int) or error (string).
     */
    private function run_child(string $method): array {
        $this->run_child_script('');
        return $this->run_child_script($method);
    }

    /**
     * Run the child script once.
     *
     * @param string $method Static method of the service to call, or '' to only boot.
     * @return array Keys: before (bool), result (int) or error (string).
     */
    private function run_child_script(string $method): array {
        global $CFG, $DB;
        $child = <<<'PHP'
            <?php
            define('PHPUNIT_UTIL', true);
            require($argv[1]);
            require($argv[2]);
            $out = ['before' => class_exists('grade_item', false)];
            try {
                if ($argv[3] !== '') {
                    $out['result'] = \local_unifiedgrader\friction\service::{$argv[3]}();
                }
            } catch (\Throwable $e) {
                $out['error'] = get_class($e) . ': ' . $e->getMessage();
            }
            echo json_encode($out);
            PHP;
        $script = make_request_directory() . '/friction_child.php';
        file_put_contents($script, $child);
        $loader = new \ReflectionClass(\Composer\Autoload\ClassLoader::class);
        $command = [
            PHP_BINARY,
            $script,
            dirname($loader->getFileName(), 2) . '/autoload.php',
            $CFG->libdir . '/phpunit/bootstrap.php',
            $method,
        ];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        // The child writes through its own connection, so the reset after this test cannot see which
        // tables it touched: mark them all, or its rows outlive the test and collide with later ones.
        foreach (array_keys($DB->get_tables(false)) as $table) {
            \testing_util::set_table_modified_by_sql(' ' . $DB->get_prefix() . $table);
        }

        $result = json_decode((string) $stdout, true);
        $this->assertIsArray($result, "The child process exited with $exit:\n$stdout\n$stderr");
        $this->assertArrayNotHasKey('error', $result, (string) ($result['error'] ?? ''));
        return $result;
    }

    /**
     * Turn the site setting on.
     */
    private function enable_friction(): void {
        set_config('friction_enable', 1, 'local_unifiedgrader');
    }

    /**
     * One graded student, posted, with the walk still open.
     *
     * @return object{course:\stdClass,student:\stdClass,itemid:int,assignid:int}
     */
    private function post_one(): object {
        $built = $this->graded_assignment();
        $student = $built->scenario->students[0];
        $built->adapter->set_grades_posted(0, [$student->id]);
        return (object) [
            'course' => $built->scenario->course,
            'student' => $student,
            'itemid' => $built->adapter->get_grade_item()->id,
            'assignid' => $built->scenario->activity->id,
        ];
    }

    /**
     * An assignment whose first student has overall feedback, which is one step.
     *
     * @return object{adapter:\local_unifiedgrader\adapter\assign_adapter,scenario:\stdClass}
     */
    private function graded_assignment(): object {
        $plugingen = $this->getDataGenerator()->get_plugin_generator('local_unifiedgrader');
        $scenario = $plugingen->create_grading_scenario('assign', ['studentcount' => 1]);
        $adapter = adapter_factory::create($scenario->cm->id);
        $student = $scenario->students[0];
        $this->setUser($student);
        $plugingen->create_assign_submission($scenario->activity, $student->id);
        $this->setUser($scenario->teacher);
        $adapter->save_grade($student->id, 80.0, '<p>Marked.</p>');
        return (object) ['adapter' => $adapter, 'scenario' => $scenario];
    }

    /**
     * Write a course end date. Zero means the course has none.
     *
     * @param \stdClass $course
     * @param int $enddate
     */
    private function set_enddate(\stdClass $course, int $enddate): void {
        global $DB;
        $course->enddate = $enddate;
        $DB->update_record('course', $course);
    }

    /**
     * The walk state stored for a student.
     *
     * @param int $itemid
     * @param int $userid
     * @return string
     */
    private function friction_state(int $itemid, int $userid): string {
        global $DB;
        return (string) $DB->get_field('local_unifiedgrader_friction', 'state', [
            'itemid' => $itemid,
            'userid' => $userid,
        ]);
    }

    /**
     * Whether the gradebook hides this mark. The item flag counts, as it does for a student.
     *
     * @param int $itemid
     * @param int $userid
     * @return bool
     */
    private function gradebook_hides(int $itemid, int $userid): bool {
        $grade = \grade_grade::fetch(['itemid' => $itemid, 'userid' => $userid]);
        if (!$grade) {
            $item = \grade_item::fetch(['id' => $itemid]);
            return !$item || $item->is_hidden();
        }
        return $grade->is_hidden();
    }

    /**
     * A cell's hidden value.
     *
     * @param int $itemid
     * @param int $userid
     * @return int
     */
    private function cell_hidden(int $itemid, int $userid): int {
        global $DB;
        return (int) $DB->get_field('grade_grades', 'hidden', ['itemid' => $itemid, 'userid' => $userid]);
    }
}
