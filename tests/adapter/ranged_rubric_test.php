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
 * End-to-end tests for gradingform_rubric_ranges support in the adapters.
 *
 * The ranged rubric reports its method as 'rubric_ranges', so every
 * "=== 'rubric'" comparison used to skip it: students opened an empty
 * "Assessment criteria" modal and teachers got an empty marking pane.
 *
 * @package    local_unifiedgrader
 * @category   test
 * @copyright  2026 South African Theological Seminary
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(assign_adapter::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(forum_adapter::class)]
final class ranged_rubric_test extends \advanced_testcase {
    /**
     * Skip when the ranged rubric plugin is not installed on this site.
     */
    protected function require_plugin(): void {
        if (!\core_component::get_plugin_directory('gradingform', 'rubric_ranges')) {
            $this->markTestSkipped('gradingform_rubric_ranges is not installed.');
        }
    }

    /**
     * Attach a ranged rubric to an activity's grading area.
     *
     * @param \context $context
     * @param string $component
     * @param string $area
     * @return \gradingform_controller
     */
    protected function create_ranged_rubric(
        \context $context,
        string $component,
        string $area,
    ): \gradingform_controller {
        $generator = $this->getDataGenerator()->get_plugin_generator('gradingform_rubric_ranges');
        return $generator->get_test_rubric_ranges($context, $component, $area);
    }

    /**
     * An assignment's grading definition must expose ranged criteria and a PDF link.
     */
    public function test_assign_grading_definition_includes_ranged_criteria(): void {
        $this->resetAfterTest();
        $this->require_plugin();

        $plugingen = $this->getDataGenerator()->get_plugin_generator('local_unifiedgrader');
        $scenario = $plugingen->create_grading_scenario('assign');
        $this->setUser($scenario->teacher);

        $this->create_ranged_rubric($scenario->context, 'mod_assign', 'submissions');

        $adapter = adapter_factory::create($scenario->cm->id);
        $definition = $adapter->get_grading_definition();

        // Before the fix this was a bare array with no 'criteria' key at all.
        $this->assertIsArray($definition);
        $this->assertSame('rubric_ranges', $definition['method']);
        $this->assertArrayHasKey('criteria', $definition);
        $this->assertNotEmpty($definition['criteria']);
        $this->assertTrue($definition['isranged']);

        // The PDF export is what students download from the criteria modal.
        $this->assertNotEmpty($definition['pdfurl']);
        $this->assertStringContainsString(
            '/grade/grading/form/rubric_ranges/print.php',
            $definition['pdfurl'],
        );

        // The fixture's ranged criterion carries band labels and a points ceiling.
        $ranged = null;
        foreach ($definition['criteria'] as $criterion) {
            if (str_contains($criterion['description'], 'Spelling')) {
                $ranged = $criterion;
            }
        }
        $this->assertNotNull($ranged, 'Ranged criterion missing from the serialised definition.');
        $this->assertTrue($ranged['isranged']);
        $this->assertGreaterThan(0, $ranged['points']);
        $this->assertArrayHasKey('rangelabel', $ranged['levels'][0]);
        // Levels score 0/5/10, so the bands run 0-0, 1-5, 6-10.
        $this->assertSame('0 to 0', $ranged['levels'][0]['rangelabel']);
        $this->assertSame('1 to 5', $ranged['levels'][1]['rangelabel']);
        $this->assertSame('6 to 10', $ranged['levels'][2]['rangelabel']);
    }

    /**
     * A forum's grading definition must do the same.
     */
    public function test_forum_grading_definition_includes_ranged_criteria(): void {
        $this->resetAfterTest();
        $this->require_plugin();

        $plugingen = $this->getDataGenerator()->get_plugin_generator('local_unifiedgrader');
        $scenario = $plugingen->create_grading_scenario('forum');
        $this->setUser($scenario->teacher);

        $this->create_ranged_rubric($scenario->context, 'mod_forum', 'forum');

        $adapter = adapter_factory::create($scenario->cm->id);
        $definition = $adapter->get_grading_definition();

        $this->assertIsArray($definition);
        $this->assertSame('rubric_ranges', $definition['method']);
        $this->assertNotEmpty($definition['criteria']);
        $this->assertNotEmpty($definition['pdfurl']);
    }

    /**
     * Ranged and unranged criteria coexist in one definition, and only the
     * ranged ones gain band labels.
     */
    public function test_unranged_criteria_have_no_band_labels(): void {
        $this->resetAfterTest();
        $this->require_plugin();

        $plugingen = $this->getDataGenerator()->get_plugin_generator('local_unifiedgrader');
        $scenario = $plugingen->create_grading_scenario('assign');
        $this->setUser($scenario->teacher);

        // The shipped fixture mixes one ranged criterion with one unranged one.
        $this->create_ranged_rubric($scenario->context, 'mod_assign', 'submissions');

        $adapter = adapter_factory::create($scenario->cm->id);
        $definition = $adapter->get_grading_definition();

        $sawranged = false;
        $sawunranged = false;
        foreach ($definition['criteria'] as $criterion) {
            if ($criterion['isranged']) {
                $sawranged = true;
                foreach ($criterion['levels'] as $level) {
                    $this->assertArrayHasKey('rangelabel', $level);
                }
            } else {
                $sawunranged = true;
                foreach ($criterion['levels'] as $level) {
                    $this->assertArrayNotHasKey('rangelabel', $level);
                }
            }
        }
        $this->assertTrue($sawranged, 'Fixture should contain a ranged criterion.');
        $this->assertTrue($sawunranged, 'Fixture should contain an unranged criterion.');
    }

    /**
     * The fill for a graded ranged rubric must be readable.
     *
     * gradingform_rubric_ranges_instance extends gradingform_instance rather
     * than gradingform_rubric_instance, so the old instanceof check returned
     * null and the marking pane reopened with no selections.
     */
    public function test_rubric_fill_is_read_back_for_ranged_rubric(): void {
        $this->resetAfterTest();
        $this->require_plugin();

        $plugingen = $this->getDataGenerator()->get_plugin_generator('local_unifiedgrader');
        $scenario = $plugingen->create_grading_scenario('assign');
        $this->setUser($scenario->teacher);

        $controller = $this->create_ranged_rubric($scenario->context, 'mod_assign', 'submissions');

        $student = $scenario->students[0];
        $definition = $controller->get_definition();

        // Grade the student through the plugin's own instance API.
        $itemid = 1;
        $instance = $controller->get_or_create_instance(0, $scenario->teacher->id, $itemid);
        $data = ['criteria' => []];
        foreach ($definition->rubric_criteria as $cid => $criterion) {
            $levelid = (int) array_key_first($criterion['levels']);
            $data['criteria'][$cid] = [
                'levelid' => $levelid,
                'remark' => 'Marked in test',
                'grade' => 1,
            ];
        }
        $instance->update($data);

        // Reading the filling back must work for the ranged instance class.
        $filling = \local_unifiedgrader\grading_method_helper::get_rubric_filling($instance);
        $this->assertIsArray($filling);
        $this->assertArrayHasKey('criteria', $filling);
        $this->assertCount(count($definition->rubric_criteria), $filling['criteria']);

        $first = reset($filling['criteria']);
        $this->assertSame('Marked in test', $first['remark']);
        $this->assertNotEmpty($student);
    }
}
