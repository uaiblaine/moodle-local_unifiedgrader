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
 * External function: save a per-user UI preference.
 *
 * @package    local_unifiedgrader
 * @copyright  2026 South African Theological Seminary (mathieu@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_unifiedgrader\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_unifiedgrader\preferences_manager;

/**
 * Save a single UI preference key/value for the current user.
 */
class save_preference extends external_api {
    /**
     * Parameter definition.
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'key' => new external_value(PARAM_ALPHANUMEXT, 'Preference key (e.g. groupfilter.123)'),
            'value' => new external_value(PARAM_RAW, 'Preference value as a string'),
        ]);
    }

    /**
     * Execute.
     *
     * @param string $key
     * @param string $value
     * @return array
     */
    public static function execute(string $key, string $value): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'key' => $key,
            'value' => $value,
        ]);

        $context = \context_system::instance();
        self::validate_context($context);

        // Release the PHP session lock so concurrent AJAX from the same
        // teacher does not serialize behind this request. This handler
        // does not write to $SESSION.
        \core\session\manager::write_close();
        if (isguestuser()) {
            throw new \moodle_exception('noguest');
        }

        preferences_manager::set($USER->id, $params['key'], $params['value']);
        return ['success' => true];
    }

    /**
     * Return definition.
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the preference was saved'),
        ]);
    }
}
