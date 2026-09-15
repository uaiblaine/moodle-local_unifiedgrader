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
 * Helpers for advanced grading methods.
 *
 * The gradingform_rubric_ranges plugin is a fork of core's rubric that adds a
 * per-criterion "ranged" flag. Its grading method name is 'rubric_ranges', its
 * definition exposes the same rubric_criteria structure, and its instance
 * exposes the same get_rubric_filling() -- but its instance class extends
 * gradingform_instance directly rather than gradingform_rubric_instance, so
 * naive "=== 'rubric'" and "instanceof gradingform_rubric_instance" checks miss
 * it entirely.
 *
 * Everything that distinguishes the two lives here so the adapters do not each
 * carry their own copy.
 *
 * @package    local_unifiedgrader
 * @copyright  2026 South African Theological Seminary
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class grading_method_helper {
    /** @var string Core rubric grading method. */
    public const METHOD_RUBRIC = 'rubric';

    /** @var string Ranged rubric grading method (gradingform_rubric_ranges). */
    public const METHOD_RUBRIC_RANGES = 'rubric_ranges';

    /**
     * Whether the method is a rubric, ranged or not.
     *
     * @param string|null $method Grading method name.
     * @return bool
     */
    public static function is_rubric(?string $method): bool {
        return $method === self::METHOD_RUBRIC || $method === self::METHOD_RUBRIC_RANGES;
    }

    /**
     * Whether the method supports per-criterion score ranges.
     *
     * @param string|null $method Grading method name.
     * @return bool
     */
    public static function is_ranged_rubric(?string $method): bool {
        return $method === self::METHOD_RUBRIC_RANGES;
    }

    /**
     * Read the rubric filling from a grading instance, whichever rubric flavour it is.
     *
     * @param \gradingform_instance $instance
     * @return array|null
     */
    public static function get_rubric_filling(\gradingform_instance $instance): ?array {
        // Covers both gradingform_rubric_instance and
        // gradingform_rubric_ranges_instance, which share the method name but
        // not a common parent below gradingform_instance.
        if (method_exists($instance, 'get_rubric_filling')) {
            return $instance->get_rubric_filling();
        }
        return null;
    }

    /**
     * Annotate serialised rubric criteria with ranged-rubric data.
     *
     * For a ranged criterion the teacher picks a level *and* enters a score
     * inside that level's band; gradingform_rubric_ranges_instance::get_grade()
     * sums the entered score for ranged criteria and the level score otherwise.
     * The band boundaries mirror
     * gradingform_rubric_ranges_renderer::display_range_score(): with levels
     * sorted ascending the first band starts at 0 and each subsequent band
     * starts one point above the previous level's score.
     *
     * @param array $criteria Serialised criteria, each with id/levels.
     * @param array $rawcriteria The controller's rubric_criteria, keyed by criterion id.
     * @param bool $sortlevelsasc Whether levels are sorted ascending (the sortlevelsasc option).
     * @return array The criteria with isranged, points and per-level rangestart/rangeend/rangelabel added.
     */
    public static function annotate_ranged_criteria(
        array $criteria,
        array $rawcriteria,
        bool $sortlevelsasc = true,
    ): array {
        foreach ($criteria as &$criterion) {
            $raw = $rawcriteria[$criterion['id']] ?? null;
            $isranged = !empty($raw['isranged']);

            $criterion['isranged'] = $isranged;
            // Maximum score a ranged criterion can be awarded. The plugin's own
            // grading UI offers range(0, points) as the score selector.
            $criterion['points'] = isset($raw['points']) ? (float) $raw['points'] : 0.0;

            if (!$isranged || empty($criterion['levels'])) {
                continue;
            }

            $levels = array_values($criterion['levels']);
            $count = count($levels);
            foreach ($levels as $index => $level) {
                if ($sortlevelsasc) {
                    $start = $index === 0 ? 0 : ((float) $levels[$index - 1]['score'] + 1);
                    $end = (float) $level['score'];
                } else {
                    // Descending: the band runs from this level's score up to
                    // one above the next level down, and the last row ends at 0.
                    $start = (float) $level['score'];
                    $end = $index === $count - 1 ? 0 : ((float) $levels[$index + 1]['score'] + 1);
                }
                $a = (object) ['rangestart' => self::format_score($start), 'rangeend' => self::format_score($end)];
                // The bounds are carried separately from the label so callers --
                // and tests -- do not depend on the ranged rubric's lang pack
                // being installed just to know where a band begins and ends.
                $levels[$index]['rangestart'] = $a->rangestart;
                $levels[$index]['rangeend'] = $a->rangeend;
                $levels[$index]['rangelabel'] = self::range_label($a);
            }
            $criterion['levels'] = $levels;
        }
        unset($criterion);

        return $criteria;
    }

    /**
     * Build the URL of the ranged rubric's printable PDF, if that plugin provides one.
     *
     * print.php only requires course access, so this is safe to expose to students.
     *
     * @param string|null $method Grading method name.
     * @param int $areaid Grading area id.
     * @return string|null Absolute URL, or null when the method has no PDF export.
     */
    public static function get_pdf_url(?string $method, int $areaid): ?string {
        if (!self::is_ranged_rubric($method) || $areaid <= 0) {
            return null;
        }
        $url = new \moodle_url(
            '/grade/grading/form/rubric_ranges/print.php',
            ['areaid' => $areaid],
        );
        return $url->out(false);
    }

    /**
     * Render a band label, e.g. "6 to 10".
     *
     * Uses the ranged rubric's own string so the label matches what that
     * plugin's preview shows and follows the site language. That plugin is
     * necessarily installed whenever a ranged rubric is being displayed, but
     * the helper is also exercised without it -- calling get_string() on an
     * absent string would return "[[levelrange]]" and raise a debugging call,
     * so fall back to the same format its English string uses.
     *
     * @param \stdClass $a Object with rangestart and rangeend.
     * @return string
     */
    private static function range_label(\stdClass $a): string {
        if (get_string_manager()->string_exists('levelrange', 'gradingform_rubric_ranges')) {
            return get_string('levelrange', 'gradingform_rubric_ranges', $a);
        }
        return $a->rangestart . ' to ' . $a->rangeend;
    }

    /**
     * Format a score for display, dropping a redundant trailing ".0".
     *
     * @param float $score
     * @return string
     */
    private static function format_score(float $score): string {
        if ((float) (int) $score === $score) {
            return (string) (int) $score;
        }
        return rtrim(rtrim(number_format($score, 5, '.', ''), '0'), '.');
    }
}
