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
 * Two rules enforce that, and each requires the text class its own background maps to
 * (required_text_colour()), not merely any text-* class:
 *
 * 1. Badge lines. On a line that mentions a badge, each bg-* class must have its required
 *    text class in the same segment. A segment is the run of text between quote marks and
 *    Mustache section tags, so `{{#x}}bg-a text-white{{/x}}{{^x}}bg-b text-dark{{/x}}`
 *    and `cond ? 'bg-a text-white' : 'bg-b text-dark'` are checked branch by branch.
 * 2. Class maps. A badge class assembled elsewhere (student_navigator.js _getStatusBadgeClass()
 *    and _getStatusInfo(), quiz_adapter::get_state_badge_class(), a ternary arm) never shares a
 *    line with the word "badge". A string literal that holds nothing but bg-* and text-* tokens
 *    and sits in value position (after `=>`, `:`, `?`, `||`, `return`) is a class value, so
 *    it must carry its text class too. Literals with other words (`border`, `d-flex`) belong to
 *    rule 1 or to non-badge markup, and literals as arguments (`toggle('bg-primary')`), selectors
 *    and array keys (`'bg-warning' => 'background-color: ...'`) are not in value position.
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
            'bg-light' => 'text-dark',
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
     * Offences in one segment of markup: a background class without its required text class.
     *
     * @param string $segment Text between quote marks or Mustache section tags.
     * @return array Messages, one per background class lacking its text class.
     */
    private function segment_offences(string $segment): array {
        $found = [];
        foreach ($this->required_text_colour() as $background => $required) {
            /*
             * Not preceded by "text-": Bootstrap 5.2's text-bg-* utility sets the
             * background and a contrasting colour, so it satisfies the rule on its own.
             */
            if (!preg_match('/(?<![\w-])' . preg_quote($background, '/') . '(?![\w-])/', $segment)) {
                continue;
            }
            if (!preg_match('/(?<![\w-])' . preg_quote($required, '/') . '(?![\w-])/', $segment)) {
                $found[] = $background . ' needs ' . $required;
            }
        }
        return $found;
    }

    /**
     * Every badge must state the text colour its background requires.
     *
     * Rules 1 and 2 of the class docblock, plus the bg-*-subtle backgrounds of Bootstrap 5.3,
     * which are light and pair with their text-*-emphasis colour.
     *
     * @return void
     */
    public function test_every_badge_states_its_text_colour(): void {
        $offenders = [];
        foreach ($this->markup_files() as $path) {
            foreach (file($path) as $number => $line) {
                if ($this->is_comment_line($line)) {
                    continue;
                }
                /*
                 * Code that detects these classes in existing HTML does not emit them:
                 * forum_adapter matches bg-danger and similar classes to classify a post's markup.
                 */
                if (preg_match('/preg_(match|replace|split|quote)|new RegExp/', $line)) {
                    continue;
                }
                $where = basename($path) . ':' . ($number + 1);

                // Rule 1: badge lines, checked per segment.
                if (preg_match('/badge/i', $line)) {
                    foreach (preg_split('/[\'"]|\{\{[#^\/][^}]*\}\}/', $line) as $segment) {
                        foreach ($this->segment_offences($segment) as $offence) {
                            $offenders[] = $where . ' ' . $offence;
                        }
                    }
                    /*
                     * Bootstrap 5.3's bg-*-subtle backgrounds are light, so white badge text fails
                     * on them as on bg-secondary; each pairs with its text-*-emphasis colour.
                     */
                    if (preg_match_all('/\bbg-([a-z]+)-subtle\b/', $line, $subtle)) {
                        foreach ($subtle[1] as $hue) {
                            $ok = '/\btext-(' . preg_quote($hue, '/') . '-emphasis|dark|body)\b/';
                            if (!preg_match($ok, $line)) {
                                $offenders[] = $where . ' bg-' . $hue . '-subtle needs text-' . $hue . '-emphasis';
                            }
                        }
                    }
                }

                // Rule 2: class values that hold only bg-* and text-* tokens, wherever they are assembled.
                $value = '/(?:=>|:|\?|\|\||\breturn)\s*([\'"])((?:bg|text)-[a-z-]+(?:\s+(?:bg|text)-[a-z-]+)*)\1/';
                if (preg_match_all($value, $line, $values)) {
                    foreach ($values[2] as $classes) {
                        foreach ($this->segment_offences($classes) as $offence) {
                            $offenders[] = $where . ' ' . $offence;
                        }
                    }
                }
            }
        }
        $offenders = array_values(array_unique($offenders));
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
