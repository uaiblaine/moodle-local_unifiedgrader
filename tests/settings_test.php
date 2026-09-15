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

namespace local_unifiedgrader;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/adminlib.php');

/**
 * Tests for the admin tree settings.php builds.
 *
 * settings.php is a page wiring file rather than a class, so it is tested the way core tests
 * admin tree wiring: by building the tree for a given user and looking the page up.
 *
 * @package    local_unifiedgrader
 * @category   test
 * @copyright  2026 South African Theological Seminary (mathieu@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class settings_test extends \advanced_testcase {
    /**
     * A manager holding the moderation capability, without moodle/site:config, reaches the page.
     *
     * The page used to be registered only inside the site-configuration block, so the tree built
     * for such a manager had no such page and admin_externalpage_setup() refused them, while the
     * CSV export the page links to already honoured the capability alone.
     */
    public function test_manager_with_the_capability_can_reach_the_moderation_page(): void {
        global $DB;
        $this->resetAfterTest();

        $manager = $this->getDataGenerator()->create_user();
        $managerroleid = (int) $DB->get_field('role', 'id', ['shortname' => 'manager'], MUST_EXIST);
        role_assign($managerroleid, $manager->id, \context_system::instance()->id);
        $this->setUser($manager);

        $this->assertFalse(has_capability('moodle/site:config', \context_system::instance()));
        $this->assertTrue(has_capability('local/unifiedgrader:moderatelibraries', \context_system::instance()));

        $page = admin_get_root(true, true)->locate('local_unifiedgrader_moderatelibraries');

        $this->assertInstanceOf(\admin_externalpage::class, $page);
        $this->assertTrue($page->check_access());
    }

    /**
     * A manager reaches the system-defaults page through its own capability too.
     *
     * The manager archetype holds local/unifiedgrader:managesystemdefaults, but the page was
     * registered only for users with moodle/site:config, so it refused every manager.
     */
    public function test_manager_with_the_capability_can_reach_the_system_defaults_page(): void {
        global $DB;
        $this->resetAfterTest();

        $manager = $this->getDataGenerator()->create_user();
        $managerroleid = (int) $DB->get_field('role', 'id', ['shortname' => 'manager'], MUST_EXIST);
        role_assign($managerroleid, $manager->id, \context_system::instance()->id);
        $this->setUser($manager);

        $this->assertFalse(has_capability('moodle/site:config', \context_system::instance()));
        $this->assertTrue(has_capability('local/unifiedgrader:managesystemdefaults', \context_system::instance()));

        $page = admin_get_root(true, true)->locate('local_unifiedgrader_systemdefaults');

        $this->assertInstanceOf(\admin_externalpage::class, $page);
        $this->assertTrue($page->check_access());
    }

    /**
     * A user without the capability still cannot open either page: the capability is the gate.
     */
    public function test_user_without_the_capability_cannot_reach_the_moderation_page(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $root = admin_get_root(true, true);

        foreach (['local_unifiedgrader_moderatelibraries', 'local_unifiedgrader_systemdefaults'] as $name) {
            $page = $root->locate($name);
            $this->assertTrue($page === null || !$page->check_access(), $name);
        }
    }
}
