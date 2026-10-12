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
 * Every badge must state its own text colour.
 *
 * Bootstrap 5's .badge defaults its colour to white, so a badge on a light background
 * fails the 4.5:1 WCAG AA floor: with Boost's colours, white on bg-secondary is 1.49:1
 * and on bg-warning 1.95:1. No other check catches this: phpcs, the Mustache lint and
 * stylelint do not read class names for meaning, and Behat does not assert contrast.
 *
 * On Bootstrap 4 (Moodle 4.5) .badge sets no colour, so a saturated background gets
 * near-black text instead. This plugin requires Moodle 5.0, so only the light-background
 * case can occur, but stating the colour explicitly is correct on both.
 *
 * @package    local_unifiedgrader
 * @category   test
 * @copyright  2026 South African Theological Seminary (mathieu@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class badge_contrast_test extends \basic_testcase {
    /**
     * Background utilities and the text utility each one requires.
     *
     * @return array Background class => required text class.
     */
    private function required_text_colour(): array {
        return [
            'bg-success' => 'text-white',
            'bg-primary' => 'text-white',
            'bg-danger' => 'text-white',
            'bg-info' => 'text-white',
            'bg-dark' => 'text-white',
            'bg-secondary' => 'text-dark',
            'bg-warning' => 'text-dark',
        ];
    }

    /**
     * Whether a line is prose rather than markup.
     *
     * A comment naming a class in order to explain the rule is not a breach of it.
     *
     * @param string $line One raw source line.
     * @return bool True when the line opens with a PHP, JS or Mustache comment marker.
     */
    private function is_comment_line(string $line): bool {
        $trimmed = ltrim($line);

        return $trimmed === ''
            || str_starts_with($trimmed, '//')
            || str_starts_with($trimmed, '/*')
            || str_starts_with($trimmed, '*')
            || str_starts_with($trimmed, '{{!');
    }

    /**
     * Every file whose contents can put a class name in front of a user.
     *
     * The top-level pages count too: the comment library pages (moderate_libraries.php,
     * my_library.php, ...) print their badges through html_writer from the plugin root.
     *
     * @return array List of absolute file paths.
     */
    private function markup_files(): array {
        $root = dirname(__DIR__);
        $files = glob($root . '/*.php');
        foreach (['templates', 'amd/src', 'classes'] as $dir) {
            $path = $root . '/' . $dir;
            if (!is_dir($path)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path));
            foreach ($iterator as $file) {
                if ($file->isFile() && in_array($file->getExtension(), ['mustache', 'js', 'php'], true)) {
                    $files[] = $file->getPathname();
                }
            }
        }
        sort($files);
        return $files;
    }

    /**
     * A badge carrying a background utility must carry a text colour beside it.
     *
     * Checked on every line that mentions a badge and a background, rather than by
     * parsing class attributes: the classes are assembled in Mustache conditionals and
     * in JS string concatenation as well as in plain attributes, and a parser strict
     * enough to read all three would be easier to fool than a line scan.
     *
     * @return void
     */
    public function test_every_badge_states_its_text_colour(): void {
        $offenders = [];
        foreach ($this->markup_files() as $path) {
            foreach (file($path) as $number => $line) {
                if ($this->is_comment_line($line) || !preg_match('/badge/i', $line)) {
                    continue;
                }
                /*
                 * Code that detects these classes in existing HTML does not emit them:
                 * forum_adapter matches bg-danger and similar classes to classify a post's markup.
                 */
                if (preg_match('/preg_(match|replace|split|quote)|new RegExp/', $line)) {
                    continue;
                }
                foreach ($this->required_text_colour() as $background => $required) {
                    /*
                     * Not preceded by "text-": Bootstrap 5.2's text-bg-* utility sets the
                     * background and a contrasting colour, so it satisfies the rule on its own.
                     */
                    if (!preg_match('/(?<!text-)\b' . preg_quote($background, '/') . '\b(?!-subtle)/', $line)) {
                        continue;
                    }
                    if (!preg_match('/\btext-(white|dark|body|muted|bg-[a-z]+)\b/', $line)) {
                        $offenders[] = basename($path) . ':' . ($number + 1) . ' needs ' . $required;
                    }
                }
                /*
                 * Bootstrap 5.3's bg-*-subtle backgrounds are light, so white badge text fails
                 * on them as on bg-secondary; each pairs with its text-*-emphasis colour. The
                 * (?!-subtle) lookahead above keeps them out of the saturated check.
                 */
                if (preg_match_all('/\bbg-([a-z]+)-subtle\b/', $line, $subtle)) {
                    foreach ($subtle[1] as $hue) {
                        $ok = '/\btext-(' . preg_quote($hue, '/') . '-emphasis|dark|body)\b/';
                        if (!preg_match($ok, $line)) {
                            $offenders[] = basename($path) . ':' . ($number + 1)
                                . ' needs text-' . $hue . '-emphasis';
                        }
                    }
                }
            }
        }
        sort($offenders);
        $this->assertSame(
            [],
            $offenders,
            'Bootstrap 5 defaults .badge text to white, so a badge on a light background fails '
                . 'WCAG AA (bg-secondary measures 1.49:1). State the colour explicitly - text-dark '
                . 'on secondary and warning, text-white on the saturated backgrounds: '
                . implode('; ', $offenders)
        );
    }
}
