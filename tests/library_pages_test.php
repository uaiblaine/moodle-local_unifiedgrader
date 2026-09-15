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
 * The comment library pages take their access and owner decisions from tested methods.
 *
 * The pages cannot be driven from PHPUnit: they redirect, stream downloads and exit. So the
 * decisions they got wrong now live in library_csv and library_audit, where they are tested, and
 * this test pins that each page still calls those methods instead of deciding inline again.
 *
 * @package    local_unifiedgrader
 * @category   test
 * @copyright  2026 South African Theological Seminary (mathieu@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class library_pages_test extends \basic_testcase {
    /**
     * Read a page script from the plugin root.
     *
     * @param string $name File name.
     * @return string Source.
     */
    private function page(string $name): string {
        $source = file_get_contents(dirname(__DIR__) . '/' . $name);
        $this->assertNotFalse($source, "Could not read {$name}.");
        return $source;
    }

    /**
     * The bucket export decides who owns the bucket through library_csv::is_bucket_owner().
     */
    public function test_bucket_export_checks_the_owner_through_library_csv(): void {
        $source = $this->page('export_library_csv.php');

        $this->assertStringContainsString('library_csv::is_bucket_owner($owner, $USER->id)', $source);
        $this->assertStringNotContainsString('$owner !== $USER->id', $source);
    }

    /**
     * The filtered import decides whether an ownerid column counts through library_csv.
     */
    public function test_moderation_import_decides_the_owner_column_through_library_csv(): void {
        $source = $this->page('moderate_libraries.php');

        $this->assertStringContainsString('library_csv::allow_owner_column_for_filter($filteruser)', $source);
        $this->assertStringNotContainsString('library_csv::import($csvcontent, $filteruser, null, true, false)', $source);
    }

    /**
     * The bucket view labels its owner through library_audit::describe_owner().
     */
    public function test_bucket_view_labels_the_owner_through_describe_owner(): void {
        $source = $this->page('moderate_libraries.php');

        $this->assertStringContainsString('library_audit::describe_owner($owner, $ownerrecord)', $source);
        $this->assertStringNotContainsString("'firstname' => get_string('clibmod_missing_owner'", $source);
    }

    /**
     * Every import notice is built by library_csv::import_result_message(), which names rejected rows.
     *
     * Two of the three import actions built the notice from the counts alone.
     */
    public function test_import_notices_name_rejected_rows(): void {
        $this->assertSame(1, substr_count($this->page('my_library.php'), 'library_csv::import_result_message($result)'));
        $this->assertSame(2, substr_count($this->page('moderate_libraries.php'), 'library_csv::import_result_message($result)'));
        foreach (['my_library.php', 'moderate_libraries.php'] as $name) {
            $this->assertStringNotContainsString("get_string('clibcsv_import_result'", $this->page($name), $name);
        }
    }

    /**
     * Both recode forms refuse an over-long code with a notice rather than an exception page.
     */
    public function test_recode_forms_handle_an_over_long_code(): void {
        foreach (['my_library.php', 'moderate_libraries.php'] as $name) {
            $source = $this->page($name);
            $this->assertStringContainsString("'maxlength' => library_csv::MAX_CODE_LENGTH", $source, $name);
            $this->assertStringContainsString("\$e->errorcode !== 'clibmod_code_too_long'", $source, $name);
        }
    }
}
