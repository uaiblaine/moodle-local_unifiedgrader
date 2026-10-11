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

/**
 * A Behat context file may not carry a MOODLE_INTERNAL guard.
 *
 * Behat requires tests/behat/behat_*.php step-definition files before it includes
 * config.php, so MOODLE_INTERNAL is not defined yet and the guard's die() ends the whole
 * Behat process with exit code 0 and no output, stopping the features of every plugin on
 * the site, not only this one. Core's own context files carry a "NOTE: no MOODLE_INTERNAL
 * test here" comment instead of the guard for this reason.
 *
 * @package    local_unifiedgrader
 * @category   test
 * @copyright  2026 South African Theological Seminary (mathieu@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class behat_context_guard_test extends \basic_testcase {
    /**
     * No Behat context file defines a MOODLE_INTERNAL guard.
     *
     * @return void
     */
    public function test_behat_context_files_have_no_moodle_internal_guard(): void {
        $dir = dirname(__DIR__) . '/tests/behat';
        $contexts = glob($dir . '/behat_*.php') ?: [];

        /*
         * Guard against a green pass caused by the scan finding nothing: a renamed
         * directory would otherwise look exactly like a clean plugin.
         */
        $this->assertNotEmpty($contexts, 'Found no Behat context files to check — the scan is broken, not the code.');

        $offenders = [];
        foreach ($contexts as $path) {
            foreach (file($path) as $number => $line) {
                if (preg_match('/^\s*defined\s*\(\s*[\'"]MOODLE_INTERNAL[\'"]\s*\)/', $line)) {
                    $offenders[] = basename($path) . ':' . ($number + 1);
                }
            }
        }
        $this->assertSame(
            [],
            $offenders,
            'Behat includes context files before config.php, so a MOODLE_INTERNAL guard kills the '
                . 'entire Behat run — every plugin on the site, silently, with exit code 0. Replace it '
                . 'with the canonical comment core uses: ' . implode(', ', $offenders)
        );
    }
}
