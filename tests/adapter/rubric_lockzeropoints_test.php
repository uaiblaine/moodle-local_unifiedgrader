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

use local_unifiedgrader\grading_method_helper;

/**
 * The rubric's "lockzeropoints" option reaches the marking panel.
 *
 * The panel fills the grade box with the grade core's rubric computes from the
 * selected levels (marking_panel.js _computeCoreRubricGrade), and the box is sent
 * as the grade, which replaces the server's when manual overrides are allowed.
 * Core's formula depends on the option, so the grading definition must carry it.
 * The browser formula itself has no JavaScript test harness in this plugin; the
 * last test pins the server side of the contract it mirrors.
 *
 * @package    local_unifiedgrader
 * @category   test
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(grading_method_helper::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(assign_adapter::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(forum_adapter::class)]
final class rubric_lockzeropoints_test extends \advanced_testcase {
    /**
     * Attach a three-criterion rubric, levels scored 1 to 4, to an activity.
     *
     * The lowest levels add up to 3, so the two formulas differ for every score
     * between the extremes.
     *
     * @param \context $context Activity context.
     * @param string $component Grading component.
     * @param string $area Grading area.
     * @return \gradingform_rubric_controller
     */
    private function create_rubric(\context $context, string $component, string $area): \gradingform_rubric_controller {
        $levels = ['Poor' => 1, 'Fair' => 2, 'Good' => 3, 'Excellent' => 4];
        $generator = $this->getDataGenerator()->get_plugin_generator('gradingform_rubric');
        return $generator->create_instance($context, $component, $area, 'Rubric', '', [
            'Argument' => $levels,
            'Evidence' => $levels,
            'Style' => $levels,
        ]);
    }

    /**
     * Overwrite the stored options of a rubric definition.
     *
     * @param \gradingform_rubric_controller $controller The rubric's controller.
     * @param array $options Options to store, as the rubric editor stores them.
     */
    private function store_options(\gradingform_rubric_controller $controller, array $options): void {
        global $DB;
        $DB->set_field('grading_definitions', 'options', json_encode($options), ['id' => $controller->get_definition()->id]);
    }

    /**
     * A rubric created now locks zero points, and the definition says so.
     */
    public function test_assign_definition_ships_lockzeropoints_on(): void {
        $this->resetAfterTest();
        $scenario = $this->getDataGenerator()->get_plugin_generator('local_unifiedgrader')
            ->create_grading_scenario('assign');
        $this->setUser($scenario->teacher);
        $this->create_rubric($scenario->context, 'mod_assign', 'submissions');

        $definition = adapter_factory::create((int) $scenario->cm->id)->get_grading_definition();

        $this->assertSame('rubric', $definition['method']);
        $this->assertTrue($definition['lockzeropoints']);
    }

    /**
     * A rubric with the option unticked, or created before Moodle 3.2 (no option
     * stored at all), scales from the minimum score, and the definition says so.
     */
    public function test_assign_definition_ships_lockzeropoints_off(): void {
        $this->resetAfterTest();
        $scenario = $this->getDataGenerator()->get_plugin_generator('local_unifiedgrader')
            ->create_grading_scenario('assign');
        $this->setUser($scenario->teacher);
        $controller = $this->create_rubric($scenario->context, 'mod_assign', 'submissions');

        $this->store_options($controller, ['sortlevelsasc' => 1, 'lockzeropoints' => 0]);
        $definition = adapter_factory::create((int) $scenario->cm->id)->get_grading_definition();
        $this->assertFalse($definition['lockzeropoints']);

        $this->store_options($controller, ['sortlevelsasc' => 1]);
        $definition = adapter_factory::create((int) $scenario->cm->id)->get_grading_definition();
        $this->assertFalse($definition['lockzeropoints']);
    }

    /**
     * The forum adapter ships the option too.
     */
    public function test_forum_definition_ships_lockzeropoints(): void {
        $this->resetAfterTest();
        $scenario = $this->getDataGenerator()->get_plugin_generator('local_unifiedgrader')
            ->create_grading_scenario('forum');
        $this->setUser($scenario->teacher);
        $controller = $this->create_rubric($scenario->context, 'mod_forum', 'forum');

        $definition = adapter_factory::create((int) $scenario->cm->id)->get_grading_definition();
        $this->assertTrue($definition['lockzeropoints']);

        $this->store_options($controller, ['sortlevelsasc' => 1, 'lockzeropoints' => 0]);
        $definition = adapter_factory::create((int) $scenario->cm->id)->get_grading_definition();
        $this->assertFalse($definition['lockzeropoints']);
    }

    /**
     * With lockzeropoints off, the server grades (score - minscore) / (maxscore - minscore).
     *
     * The formula the panel mirrors from the shipped definition: levels 2, 2, 2
     * score 6 on a 3..12 range, so a /100 assignment stores 33.33333, where the
     * zero-based formula would give 50. Overrides are off, so the 50 the panel
     * used to send is ignored and the stored grade is the rubric's own.
     */
    public function test_assign_grade_without_lockzeropoints_scales_from_minscore(): void {
        $this->resetAfterTest();
        set_config('allow_manual_grade_override', 0, 'local_unifiedgrader');
        $scenario = $this->getDataGenerator()->get_plugin_generator('local_unifiedgrader')
            ->create_grading_scenario('assign', ['modparams' => ['grade' => 100]]);
        $this->setUser($scenario->teacher);
        $controller = $this->create_rubric($scenario->context, 'mod_assign', 'submissions');
        $this->store_options($controller, ['sortlevelsasc' => 1, 'lockzeropoints' => 0]);

        $definition = adapter_factory::create((int) $scenario->cm->id)->get_grading_definition();
        $criteria = [];
        foreach ($definition['criteria'] as $criterion) {
            $level = array_values(array_filter($criterion['levels'], fn($l) => (float) $l['score'] === 2.0))[0];
            $criteria[$criterion['id']] = ['levelid' => $level['id'], 'remark' => ''];
        }

        $student = reset($scenario->students);
        $adapter = adapter_factory::create((int) $scenario->cm->id);
        $adapter->save_grade((int) $student->id, 50.0, '', FORMAT_HTML, ['criteria' => $criteria]);

        $stored = adapter_factory::create((int) $scenario->cm->id)->get_grade_data((int) $student->id)['grade'];
        $this->assertEqualsWithDelta(100 / 3, (float) $stored, 0.0001);
    }

    /**
     * An unedited auto-filled total arrives rounded to the display precision and must keep core's unrounded
     * rubric grade, while a value that differs by more than that precision is still stored as an override.
     */
    public function test_assign_rounded_total_is_not_stored_as_an_override(): void {
        $this->resetAfterTest();
        set_config('allow_manual_grade_override', 1, 'local_unifiedgrader');
        $scenario = $this->getDataGenerator()->get_plugin_generator('local_unifiedgrader')
            ->create_grading_scenario('assign', ['modparams' => ['grade' => 100]]);
        $this->setUser($scenario->teacher);
        $controller = $this->create_rubric($scenario->context, 'mod_assign', 'submissions');
        $this->store_options($controller, ['sortlevelsasc' => 1, 'lockzeropoints' => 0]);

        $definition = adapter_factory::create((int) $scenario->cm->id)->get_grading_definition();
        $criteria = [];
        foreach ($definition['criteria'] as $criterion) {
            $level = array_values(array_filter($criterion['levels'], fn($l) => (float) $l['score'] === 2.0))[0];
            $criteria[$criterion['id']] = ['levelid' => $level['id'], 'remark' => ''];
        }

        $student = reset($scenario->students);
        $adapter = adapter_factory::create((int) $scenario->cm->id);
        $adapter->save_grade((int) $student->id, 33.33, '', FORMAT_HTML, ['criteria' => $criteria]);
        $stored = adapter_factory::create((int) $scenario->cm->id)->get_grade_data((int) $student->id)['grade'];
        $this->assertEqualsWithDelta(100 / 3, (float) $stored, 0.00001);
        $this->assertNotEquals(33.33, (float) $stored);

        $adapter->save_grade((int) $student->id, 40.0, '', FORMAT_HTML, ['criteria' => $criteria]);
        $stored = adapter_factory::create((int) $scenario->cm->id)->get_grade_data((int) $student->id)['grade'];
        $this->assertEqualsWithDelta(40.0, (float) $stored, 0.0001);
    }
}
