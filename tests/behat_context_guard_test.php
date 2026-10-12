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
            foreach (self::guard_lines(file_get_contents($path)) as $line) {
                $offenders[] = basename($path) . ':' . $line;
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

    /**
     * The scanner finds every spelling of the guard and ignores comments that name it.
     *
     * Without this control the test above would stay green if the scanner stopped
     * matching anything at all.
     *
     * @return void
     */
    public function test_guard_scanner_detects_every_guard_form(): void {
        $guarded = [
            'canonical' => "<?php\ndefined('MOODLE_INTERNAL') || die();\n",
            'or die' => "<?php\ndefined(\"MOODLE_INTERNAL\") or die();\n",
            'if not defined' => "<?php\nif (!defined('MOODLE_INTERNAL')) {\n    die();\n}\n",
            'spaced, mixed case' => "<?php\n\n\nif ( ! DEFINED ( 'MOODLE_INTERNAL' ) ) { exit; }\n",
            'fully qualified' => "<?php\n\\defined('MOODLE_INTERNAL') || exit;\n",
        ];
        foreach ($guarded as $form => $code) {
            $this->assertNotEmpty(self::guard_lines($code), "The scanner missed the '{$form}' guard.");
        }
        $this->assertSame([4], self::guard_lines("<?php\n\n\nif ( ! DEFINED ( 'MOODLE_INTERNAL' ) ) { exit; }\n"));

        $clean = "<?php\n"
            . "// NOTE: no MOODLE_INTERNAL test here, defined('MOODLE_INTERNAL') would kill behat.\n"
            . "/* defined('MOODLE_INTERNAL') || die(); */\n"
            . "/** Not defined('MOODLE_INTERNAL') either. */\n"
            . "\$x = 'defined(\\'MOODLE_INTERNAL\\')';\n"
            . "defined('BEHAT_SITE_RUNNING');\n";
        $this->assertSame([], self::guard_lines($clean));
    }

    /**
     * Return the line numbers on which code (not a comment or a string) calls
     * defined('MOODLE_INTERNAL').
     *
     * Tokenising rather than matching lines catches the guard in any position, such as
     * the older if (!defined('MOODLE_INTERNAL')) { die(); } idiom, while the canonical
     * "NOTE: no MOODLE_INTERNAL test here" comment and string literals never count.
     *
     * @param string $code PHP source.
     * @return int[] One-based line numbers, in source order.
     */
    private static function guard_lines(string $code): array {
        // Drop whitespace and comments so the remaining tokens can be read as a sequence.
        $tokens = array_values(array_filter(
            token_get_all($code),
            static fn($token): bool => !is_array($token)
                || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
        ));

        $lines = [];
        foreach ($tokens as $i => $token) {
            // A leading backslash (\defined) tokenises as one fully qualified name on PHP 8.
            $isdefined = is_array($token)
                && in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true)
                && strcasecmp(ltrim($token[1], '\\'), 'defined') === 0;
            if (!$isdefined) {
                continue;
            }
            $open = $tokens[$i + 1] ?? null;
            $argument = $tokens[$i + 2] ?? null;
            if (
                $open === '('
                && is_array($argument)
                && $argument[0] === T_CONSTANT_ENCAPSED_STRING
                && trim($argument[1], '\'"') === 'MOODLE_INTERNAL'
            ) {
                $lines[] = $token[2];
            }
        }
        return $lines;
    }
}
