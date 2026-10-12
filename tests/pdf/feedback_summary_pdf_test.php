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

namespace local_unifiedgrader\pdf;

/**
 * Tests for the feedback summary PDF.
 *
 * The summary is drawn with TCPDF primitives rather than written as HTML,
 * because HTML is what mislaid the layout in the first place: TCPDF ignores the
 * classes that hide all but the selected session, so every session's tiles
 * rendered at once as an unstyled column of numbers. Drawing by hand means the
 * guard that matters is that the primitives are driven with coordinates and
 * fonts TCPDF accepts, which is what these tests exercise.
 *
 * @package    local_unifiedgrader
 * @copyright  2026 South African Theological Seminary (mathieu@sats.ac.za) (https://www.sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_unifiedgrader\pdf\feedback_summary_pdf
 */
final class feedback_summary_pdf_test extends \advanced_testcase {
    /**
     * The minimum a summary needs, with no engagement and no annotations.
     *
     * @param array $extra Keys to merge over the defaults.
     * @return array
     */
    private function base_data(array $extra = []): array {
        return $extra + [
            'activityname' => 'Role Play 2',
            'coursename' => 'BCC2121 5E Model of Christian Counselling & Care',
            'studentname' => 'Marné Buÿs',
            'gradevalue' => 22.3,
            'maxgrade' => 25.0,
            'percentage' => 89,
            'feedback' => '<p>Excellent session!</p>',
            'gradingmethod' => 'simple',
            'rubriccriteria' => [],
            'guidecriteria' => [],
            'penalties' => [],
            'dategraded' => 'Wednesday, 26 August 2026, 4:38 PM',
            'plagiarismlinks' => [],
            'additionalcontent' => '',
            'additionalcontenttitle' => '',
            'analytics' => [],
            'annotations' => [],
        ];
    }

    /**
     * A row of metrics per session, plus the totals row, renders without error
     * and produces a valid PDF.
     *
     * The drawing is done with TCPDF primitives rather than HTML precisely
     * because the HTML route mislaid the layout, so the guard that matters here
     * is that the primitives are driven with coordinates TCPDF accepts.
     */
    public function test_generates_with_engagement_widgets(): void {
        $this->resetAfterTest();

        $data = $this->base_data([
            'analytics' => [
                'hasengagement' => true,
                'sessioncount' => 2,
                'totals' => [
                    'chats' => 0, 'talks' => 1342, 'raisehand' => 0, 'pollvotes' => 0,
                    'emojis' => 0, 'durationformatted' => '3h 13m',
                    'activityscoreformatted' => '', 'hasactivityscore' => false,
                ],
                'sessions' => [
                    ['sessionlabel' => 'Wednesday, 26 August 2026, 4:38 PM', 'chats' => 0,
                     'talks' => 620, 'raisehand' => 0, 'pollvotes' => 0, 'emojis' => 0,
                     'durationformatted' => '1h 35m'],
                    ['sessionlabel' => 'Wednesday, 26 August 2026, 6:29 PM', 'chats' => 0,
                     'talks' => 722, 'raisehand' => 0, 'pollvotes' => 0, 'emojis' => 0,
                     'durationformatted' => '1h 37m'],
                ],
            ],
        ]);

        $bytes = (new feedback_summary_pdf())->generate($data);

        $this->assertNotEmpty($bytes);
        $this->assertStringStartsWith('%PDF', $bytes);
    }

    /**
     * Timestamped comments render in place of the recording that cannot be
     * embedded, without the player markup that used to be dumped here.
     */
    public function test_generates_with_annotation_comments(): void {
        $this->resetAfterTest();

        $data = $this->base_data([
            'annotations' => [
                ['sessionlabel' => 'Session A', 'timestamp' => '4:21',
                 'text' => 'Good use of Clean Language here.'],
                ['sessionlabel' => 'Session A', 'timestamp' => '1:02:15',
                 'text' => 'Watch the pacing — you led the client.'],
                ['sessionlabel' => 'Session B', 'timestamp' => '0:45',
                 'text' => 'Warm welcome & clear confidentiality explanation.'],
            ],
        ]);

        $bytes = (new feedback_summary_pdf())->generate($data);

        $this->assertNotEmpty($bytes);
        $this->assertStringStartsWith('%PDF', $bytes);
    }

    /**
     * No engagement data means no engagement section — a summary for an
     * activity that never had attendance must not gain an empty band of zeros.
     */
    public function test_generates_without_engagement_or_annotations(): void {
        $this->resetAfterTest();

        $bytes = (new feedback_summary_pdf())->generate($this->base_data());

        $this->assertNotEmpty($bytes);
        $this->assertStringStartsWith('%PDF', $bytes);
    }

    /**
     * Enough sessions to run past the foot of the page still render: each row
     * is kept whole by breaking to a new page rather than splitting.
     */
    public function test_many_sessions_break_across_pages(): void {
        $this->resetAfterTest();

        $sessions = [];
        for ($i = 1; $i <= 14; $i++) {
            $sessions[] = [
                'sessionlabel' => 'Session ' . $i, 'chats' => $i, 'talks' => $i * 10,
                'raisehand' => 0, 'pollvotes' => 0, 'emojis' => 0,
                'durationformatted' => '1h 0m',
            ];
        }
        $data = $this->base_data([
            'analytics' => [
                'hasengagement' => true,
                'sessioncount' => 14,
                'totals' => [
                    'chats' => 105, 'talks' => 1050, 'raisehand' => 0, 'pollvotes' => 0,
                    'emojis' => 0, 'durationformatted' => '14h 0m',
                    'activityscoreformatted' => '', 'hasactivityscore' => false,
                ],
                'sessions' => $sessions,
            ],
        ]);

        $bytes = (new feedback_summary_pdf())->generate($data);

        $this->assertNotEmpty($bytes);
        $this->assertStringStartsWith('%PDF', $bytes);
    }

    /**
     * A site logo is drawn into the header band.
     */
    public function test_generates_with_a_site_logo(): void {
        global $CFG;
        $this->resetAfterTest();

        $data = $this->base_data([
            'logopath' => $CFG->dirroot . '/local/unifiedgrader/pix/icon.png',
        ]);
        $bytes = (new feedback_summary_pdf())->generate($data);

        $this->assertNotEmpty($bytes);
        $this->assertStringStartsWith('%PDF', $bytes);
    }

    /**
     * A logo path pointing at nothing must not take the whole summary down with
     * it — a student downloading their feedback should still get their feedback.
     */
    public function test_unreadable_logo_path_is_ignored(): void {
        $this->resetAfterTest();

        $data = $this->base_data(['logopath' => '/no/such/file/logo.png']);
        $bytes = (new feedback_summary_pdf())->generate($data);

        $this->assertNotEmpty($bytes);
        $this->assertStringStartsWith('%PDF', $bytes);
    }

    /**
     * The donut is drawn for every grade band, including the two that take a
     * different code path: nothing filled, and the whole ring filled.
     *
     * A full ring is filled rather than stroked because a 360-degree stroke
     * closes on itself and leaves a notch; a zero one draws no arc at all. Both
     * are easy to break without noticing, so both are exercised here.
     *
     * @dataProvider grade_band_provider
     * @param float|null $gradevalue
     * @param int|null $percentage
     */
    public function test_donut_renders_across_the_grade_bands(?float $gradevalue, ?int $percentage): void {
        $this->resetAfterTest();

        $bytes = (new feedback_summary_pdf())->generate($this->base_data([
            'gradevalue' => $gradevalue,
            'percentage' => $percentage,
        ]));

        $this->assertNotEmpty($bytes);
        $this->assertStringStartsWith('%PDF', $bytes);
    }

    /**
     * Grade values spanning every band the donut colours, plus its two edges.
     *
     * @return array<string, array{0: float|null, 1: int|null}>
     */
    public static function grade_band_provider(): array {
        return [
            'not graded' => [null, null],
            'zero' => [0.0, 0],
            'fail' => [9.0, 36],
            'amber' => [15.25, 61],
            'pass' => [22.3, 89],
            'full marks' => [25.0, 100],
        ];
    }

    /**
     * A summary with no written feedback still renders: the score keeps its
     * column and the right-hand side is simply left out.
     */
    public function test_generates_without_feedback(): void {
        $this->resetAfterTest();

        $bytes = (new feedback_summary_pdf())->generate($this->base_data(['feedback' => '']));

        $this->assertNotEmpty($bytes);
        $this->assertStringStartsWith('%PDF', $bytes);
    }

    /**
     * Feedback long enough to outrun the page flows on rather than being cut,
     * and the sections that follow it still render beneath.
     */
    public function test_long_feedback_flows_past_the_page_break(): void {
        $this->resetAfterTest();

        $paragraph = '<p>' . str_repeat('Your engagement with the set reading is genuine. ', 40) . '</p>';
        $data = $this->base_data([
            'feedback' => str_repeat($paragraph, 6),
            'analytics' => [
                'hasengagement' => true,
                'sessioncount' => 1,
                'totals' => [
                    'chats' => 3, 'talks' => 120, 'raisehand' => 1, 'pollvotes' => 0,
                    'emojis' => 2, 'durationformatted' => '1h 02m',
                    'activityscoreformatted' => '', 'hasactivityscore' => false,
                ],
                'sessions' => [],
            ],
        ]);

        $bytes = (new feedback_summary_pdf())->generate($data);

        $this->assertNotEmpty($bytes);
        $this->assertStringStartsWith('%PDF', $bytes);
    }
}
