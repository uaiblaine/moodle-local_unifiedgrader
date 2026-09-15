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
 * Event fired when a comment library is repaired from the moderation tool.
 *
 * @package    local_unifiedgrader
 * @copyright  2026 South African Theological Seminary (mathieu@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_unifiedgrader\event;

/**
 * Recorded whenever the library moderation tool rewrites comment ownership
 * or scope. These edits change what a teacher can see in their own library,
 * so they need an audit trail an admin can read back later.
 */
class library_repaired extends \core\event\base {
    /**
     * Set basic event properties.
     */
    protected function init() {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    /**
     * Localised event name (used in admin log reports).
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('event_library_repaired', 'local_unifiedgrader');
    }

    /**
     * Human-readable description.
     *
     * @return string
     */
    public function get_description(): string {
        $action = $this->other['action'] ?? 'unknown';
        $count = (int) ($this->other['count'] ?? 0);
        $detail = $this->other['detail'] ?? '';

        return "The user with id '$this->userid' performed the comment library repair "
            . "'$action' on $count comment(s). Detail: '$detail'.";
    }

    /**
     * URL where the event occurred.
     *
     * @return \moodle_url
     */
    public function get_url(): \moodle_url {
        return new \moodle_url('/local/unifiedgrader/moderate_libraries.php');
    }
}
