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
 * A grade hidden for one student in the gradebook is not released to that student.
 *
 * The teacher can hide a single student's grade, or hide it until a date, while the
 * grade item stays visible; the student's feedback pages must then withhold it too.
 * The BigBlueButton case lives in bbb_adapter_test. The quiz adapter is not covered:
 * its release follows the quiz review options, as core's quiz pages do.
 *
 * @package    local_unifiedgrader
 * @category   test
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_unifiedgrader\adapter\assign_adapter
 * @covers \local_unifiedgrader\adapter\forum_adapter
 */
final class hidden_own_grade_test extends \advanced_testcase {
    /**
     * An assignment grade hidden for the student is not released.
     */
    public function test_assign_grade_hidden_for_the_student_is_not_released(): void {
        $this->resetAfterTest();

        $plugingen = $this->getDataGenerator()->get_plugin_generator('local_unifiedgrader');
        $scenario = $plugingen->create_grading_scenario('assign');
        $student = $scenario->students[0];
        $this->setUser($student);
        $plugingen->create_assign_submission($scenario->activity, $student->id);
        $this->setUser($scenario->teacher);
        $adapter = adapter_factory::create($scenario->cm->id);
        $adapter->save_grade($student->id, 80.0, '');

        $this->assert_hiding_withholds_release($adapter, 'assign', (int) $scenario->activity->id, 0, $scenario, (int) $student->id);
    }

    /**
     * A whole-forum grade hidden for the student is not released.
     */
    public function test_forum_grade_hidden_for_the_student_is_not_released(): void {
        $this->resetAfterTest();

        $plugingen = $this->getDataGenerator()->get_plugin_generator('local_unifiedgrader');
        $scenario = $plugingen->create_grading_scenario('forum', ['modparams' => ['grade_forum' => 100]]);
        $student = $scenario->students[0];
        $this->setUser($scenario->teacher);
        $adapter = adapter_factory::create($scenario->cm->id);
        $adapter->save_grade($student->id, 80.0, '');

        $this->assert_hiding_withholds_release($adapter, 'forum', (int) $scenario->activity->id, 1, $scenario, (int) $student->id);
    }

    /**
     * Assert the grade is released, then withheld while hidden or hidden until a future date.
     *
     * @param base_adapter $adapter The adapter under test.
     * @param string $modname Module name of the grade item.
     * @param int $instanceid Activity instance id.
     * @param int $itemnumber Grade item number.
     * @param \stdClass $scenario The grading scenario.
     * @param int $userid The graded student.
     * @return void
     */
    private function assert_hiding_withholds_release(
        base_adapter $adapter,
        string $modname,
        int $instanceid,
        int $itemnumber,
        \stdClass $scenario,
        int $userid
    ): void {
        // The control: released before anything is hidden.
        $this->assertTrue($adapter->is_grade_released($userid));

        $gradeitem = \grade_item::fetch([
            'itemtype' => 'mod',
            'itemmodule' => $modname,
            'iteminstance' => $instanceid,
            'itemnumber' => $itemnumber,
            'courseid' => (int) $scenario->course->id,
        ]);
        $this->assertNotEmpty($gradeitem);
        $gradegrade = \grade_grade::fetch(['itemid' => $gradeitem->id, 'userid' => $userid]);
        $this->assertNotEmpty($gradegrade);

        $gradegrade->set_hidden(1);
        $this->assertFalse($gradeitem->is_hidden(), 'Only the student\'s grade is hidden, not the item.');
        $this->assertFalse($adapter->is_grade_released($userid));

        $gradegrade->set_hidden(time() + DAYSECS);
        $this->assertFalse($adapter->is_grade_released($userid));

        $gradegrade->set_hidden(time() - DAYSECS);
        $this->assertTrue($adapter->is_grade_released($userid));
    }
}
