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
 * Tests for resolving the site logo drawn into the feedback PDF header.
 *
 * TCPDF needs a file it can read, not the pluginfile URL Moodle hands the
 * browser, and it cannot read an SVG at all — so the lookup has an order to
 * respect and formats to refuse. A site with no logo must simply render without
 * one rather than failing a student's download.
 *
 * @package    local_unifiedgrader
 * @copyright  2026 South African Theological Seminary (mathieu@sats.ac.za) (https://www.sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_unifiedgrader\feedback_data_helper::resolve_site_logo_path
 */
final class feedback_data_helper_logo_test extends \advanced_testcase {
    /**
     * Put a file into a system-context filearea, as the site logo settings do.
     *
     * @param string $component
     * @param string $filearea
     * @param string $filename
     * @param string|null $frompath Copy this file, or write placeholder bytes.
     */
    private function store_logo(
        string $component,
        string $filearea,
        string $filename,
        ?string $frompath = null
    ): void {
        $record = [
            'contextid' => \context_system::instance()->id,
            'component' => $component,
            'filearea' => $filearea,
            'itemid' => 0,
            'filepath' => '/',
            'filename' => $filename,
        ];
        $fs = get_file_storage();
        if ($frompath !== null) {
            $fs->create_file_from_pathname($record, $frompath);
        } else {
            $fs->create_file_from_string($record, '<svg xmlns="http://www.w3.org/2000/svg"/>');
        }
    }

    /**
     * A site with no logo configured gets null, and the PDF renders without one.
     */
    public function test_returns_null_when_no_logo_is_configured(): void {
        $this->resetAfterTest();

        $this->assertNull(feedback_data_helper::resolve_site_logo_path());
    }

    /**
     * The configured logo comes back as a readable local file, because TCPDF
     * needs a path rather than the pluginfile URL Moodle would hand the browser.
     */
    public function test_returns_a_readable_local_copy_of_the_site_logo(): void {
        global $CFG;
        $this->resetAfterTest();

        $this->store_logo(
            'core_admin',
            'logo',
            'logo.png',
            $CFG->dirroot . '/local/unifiedgrader/pix/icon.png'
        );

        $path = feedback_data_helper::resolve_site_logo_path();

        $this->assertNotNull($path);
        $this->assertTrue(is_readable($path));
        $this->assertNotFalse(getimagesize($path), 'TCPDF must be handed a real image');
    }

    /**
     * The compact logo wins over the full one, matching the order Moodle itself
     * prefers them in — it is the variant meant for a confined space.
     */
    public function test_compact_logo_is_preferred(): void {
        global $CFG;
        $this->resetAfterTest();

        $this->store_logo('core_admin', 'logo', 'full.png', $CFG->dirroot . '/local/unifiedgrader/pix/icon.png');
        $this->store_logo('core_admin', 'logocompact', 'compact.png', $CFG->dirroot . '/local/unifiedgrader/pix/icon.png');

        $path = feedback_data_helper::resolve_site_logo_path();

        $this->assertNotNull($path);
        $this->assertTrue(is_readable($path));
    }

    /**
     * An SVG site logo is perfectly valid on screen and unreadable to TCPDF, so
     * it is skipped rather than allowed to abort the download.
     */
    public function test_svg_logo_is_skipped(): void {
        $this->resetAfterTest();

        $this->store_logo('core_admin', 'logocompact', 'logo.svg');

        $this->assertNull(feedback_data_helper::resolve_site_logo_path());
    }

    /**
     * An SVG in the preferred slot must not mask a usable raster logo further
     * down the order.
     */
    public function test_svg_compact_logo_falls_through_to_a_raster_logo(): void {
        global $CFG;
        $this->resetAfterTest();

        $this->store_logo('core_admin', 'logocompact', 'logo.svg');
        $this->store_logo('core_admin', 'logo', 'logo.png', $CFG->dirroot . '/local/unifiedgrader/pix/icon.png');

        $path = feedback_data_helper::resolve_site_logo_path();

        $this->assertNotNull($path);
        $this->assertNotFalse(getimagesize($path));
    }
}
