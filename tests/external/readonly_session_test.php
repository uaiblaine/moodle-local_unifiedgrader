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

namespace local_unifiedgrader\external;

use core_external\external_api;
use local_unifiedgrader\adapter\adapter_factory;
use local_unifiedgrader\segment_comment_manager;

/**
 * A service declared 'readonlysession' runs without the session lock, so nothing it writes to
 * $SESSION survives the request.
 *
 * Core's contract for a read-only session is the one write_close() checks
 * (lib/classes/session/manager.php, the array_session_diff() of $SESSION taken at session start
 * and at close): no $SESSION key added, removed or changed. That is what is asserted here around
 * every call of the sixteen reads the grader and the feedback page make, the way the browser
 * makes them. $USER is not compared, as core does not compare it.
 *
 * The services already call \core\session\manager::write_close() themselves, but only after the
 * context is validated and the capability checked, and a call that fails before that point still
 * holds the lock for as long as it runs. The declaration moves the decision to core, which skips
 * the lock for the whole call; this test is what keeps that safe.
 *
 * local_unifiedgrader_get_submission_translation is the one read left undeclared: see
 * TRANSLATION_READ.
 *
 * The covered classes are named in this docblock, as in every other test of this plugin.
 *
 * @package    local_unifiedgrader
 * @category   test
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_unifiedgrader\external\get_activity_info
 * @covers \local_unifiedgrader\external\get_participants
 * @covers \local_unifiedgrader\external\get_submission_data
 * @covers \local_unifiedgrader\external\get_grade_data
 * @covers \local_unifiedgrader\external\get_notes
 * @covers \local_unifiedgrader\external\get_library_comments
 * @covers \local_unifiedgrader\external\get_library_tags
 * @covers \local_unifiedgrader\external\get_shared_library
 * @covers \local_unifiedgrader\external\get_annotations
 * @covers \local_unifiedgrader\external\get_student_annotations
 * @covers \local_unifiedgrader\external\get_submission_comments
 * @covers \local_unifiedgrader\external\get_penalties
 * @covers \local_unifiedgrader\external\get_referrals
 * @covers \local_unifiedgrader\external\get_segment_comments
 * @covers \local_unifiedgrader\external\get_post_ratings
 * @covers \local_unifiedgrader\external\get_post_context
 */
final class readonly_session_test extends \advanced_testcase {
    /**
     * @var string The read that stays undeclared. It hands over to local_nida's
     *     submission_api::get_for_grader, whose session behaviour this plugin cannot vouch for, and
     *     local_nida is not installed here, so no test can exercise the path.
     */
    private const TRANSLATION_READ = 'local_unifiedgrader_get_submission_translation';

    /**
     * resetAfterTest() does not restore superglobals: drop the sesskey the calls plant in $_POST.
     *
     * @return void
     */
    protected function tearDown(): void {
        unset($_POST['sesskey']);
        parent::tearDown();
    }

    /**
     * The services that must hold no session lock, each with the user who makes the call and the
     * arguments the grader or the feedback page sends against the fixture below.
     *
     * @param \stdClass $fx The fixture: cmid, forumcmid, studentid, forumstudentid, fileid, teacher, student.
     * @return array Service name => ['user' => the acting user, 'args' => the arguments].
     */
    private static function read_only_calls(\stdClass $fx): array {
        return [
            'local_unifiedgrader_get_activity_info' => ['user' => $fx->teacher, 'args' => ['cmid' => $fx->cmid]],
            'local_unifiedgrader_get_participants' => ['user' => $fx->teacher, 'args' => ['cmid' => $fx->cmid]],
            'local_unifiedgrader_get_submission_data' => [
                'user' => $fx->teacher,
                'args' => ['cmid' => $fx->cmid, 'userid' => $fx->studentid],
            ],
            'local_unifiedgrader_get_grade_data' => [
                'user' => $fx->teacher,
                'args' => ['cmid' => $fx->cmid, 'userid' => $fx->studentid],
            ],
            'local_unifiedgrader_get_notes' => [
                'user' => $fx->teacher,
                'args' => ['cmid' => $fx->cmid, 'userid' => $fx->studentid],
            ],
            'local_unifiedgrader_get_library_comments' => ['user' => $fx->teacher, 'args' => []],
            'local_unifiedgrader_get_library_tags' => ['user' => $fx->teacher, 'args' => []],
            'local_unifiedgrader_get_shared_library' => ['user' => $fx->teacher, 'args' => []],
            'local_unifiedgrader_get_annotations' => [
                'user' => $fx->teacher,
                'args' => ['cmid' => $fx->cmid, 'userid' => $fx->studentid, 'fileid' => $fx->fileid],
            ],
            'local_unifiedgrader_get_student_annotations' => [
                'user' => $fx->student,
                'args' => ['cmid' => $fx->cmid, 'fileid' => $fx->fileid],
            ],
            'local_unifiedgrader_get_submission_comments' => [
                'user' => $fx->teacher,
                'args' => ['cmid' => $fx->cmid, 'userid' => $fx->studentid],
            ],
            'local_unifiedgrader_get_penalties' => [
                'user' => $fx->teacher,
                'args' => ['cmid' => $fx->cmid, 'userid' => $fx->studentid],
            ],
            'local_unifiedgrader_get_referrals' => [
                'user' => $fx->teacher,
                'args' => ['cmid' => $fx->cmid, 'userid' => $fx->studentid],
            ],
            'local_unifiedgrader_get_segment_comments' => [
                'user' => $fx->teacher,
                'args' => ['cmid' => $fx->cmid, 'userid' => $fx->studentid, 'attempt' => 0],
            ],
            'local_unifiedgrader_get_post_ratings' => [
                'user' => $fx->forumteacher,
                'args' => ['cmid' => $fx->forumcmid, 'userid' => $fx->forumstudentid],
            ],
            'local_unifiedgrader_get_post_context' => [
                'user' => $fx->forumteacher,
                'args' => ['cmid' => $fx->forumcmid, 'userid' => $fx->forumstudentid],
            ],
        ];
    }

    /**
     * Read the plugin's db/services.php as core does.
     *
     * @return array The $functions array it declares.
     */
    private static function declared_functions(): array {
        $functions = null;
        include(\core_component::get_component_directory('local_unifiedgrader') . '/db/services.php');

        return $functions;
    }

    /**
     * $SESSION as core compares it: one entry per key, the value serialised so a change inside a
     * nested object is seen too.
     *
     * @return array Key => serialised value.
     */
    private static function session_snapshot(): array {
        global $SESSION;

        return array_map('serialize', (array) $SESSION);
    }

    /**
     * The $SESSION keys added, removed or changed between two snapshots.
     *
     * @param array $before Snapshot taken before.
     * @param array $after Snapshot taken after.
     * @return array The keys, sorted.
     */
    private static function changed_keys(array $before, array $after): array {
        $keys = array_keys(array_diff_assoc($before, $after) + array_diff_assoc($after, $before));
        sort($keys);

        return $keys;
    }

    /**
     * Every read but the translation and no write is declared read-only, the exercised set is the
     * declared one, and core's own reader of db/services.php sees the flag.
     *
     * Reading through external_function_info() is the point: it is the value
     * external_api::call_external_function() decides the session lock from.
     *
     * @return void
     */
    public function test_every_read_but_the_translation_and_no_write_is_declared_read_only(): void {
        $functions = self::declared_functions();
        $declared = [];
        foreach ($functions as $name => $function) {
            $readonly = !empty($function['readonlysession']);
            $this->assertSame($function['type'] === 'read' && $name !== self::TRANSLATION_READ, $readonly, $name);
            $this->assertSame($readonly, (bool) external_api::external_function_info($name)->readonlysession, $name);
            if ($readonly) {
                $declared[] = $name;
            }
        }
        sort($declared);
        $placeholder = (object) array_fill_keys([
            'cmid', 'forumcmid', 'studentid', 'forumstudentid', 'fileid', 'teacher', 'student', 'forumteacher',
        ], 1);
        $exercised = array_keys(self::read_only_calls($placeholder));
        sort($exercised);

        $this->assertSame('read', $functions[self::TRANSLATION_READ]['type']);
        $this->assertCount(16, $declared);
        $this->assertSame($exercised, $declared);
    }

    /**
     * The comparison sees a write, so an empty result below is not an empty comparison.
     *
     * @return void
     */
    public function test_the_snapshot_sees_a_write(): void {
        global $SESSION;

        $this->resetAfterTest();
        $before = self::session_snapshot();
        $SESSION->local_unifiedgrader_probe = (object) ['seen' => 1];
        $added = self::session_snapshot();
        $SESSION->local_unifiedgrader_probe->seen = 2;

        $this->assertSame(['local_unifiedgrader_probe'], self::changed_keys($before, $added));
        $this->assertSame(['local_unifiedgrader_probe'], self::changed_keys($added, self::session_snapshot()));
    }

    /**
     * Build the data every read below has work to do on.
     *
     * An assignment holding a submitted online text and one submitted file, graded 80, with a
     * teacher's note, an annotation on the file, a submission comment, a penalty, a referral and
     * a segment comment; the teacher's own library comment and tag and another teacher's shared
     * comment; and a rating forum with one student post the teacher has rated.
     *
     * @return \stdClass The fixture read_only_calls() takes, plus the ids the controls look for.
     */
    private function build_fixture(): \stdClass {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        require_once($CFG->dirroot . '/rating/lib.php');

        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('local_unifiedgrader');
        $scenario = $plugingen->create_grading_scenario('assign');
        $teacher = $scenario->teacher;
        $student = $scenario->students[0];
        $cmid = (int) $scenario->cm->id;

        // The submission: online text, then a file in the area the adapter lists from.
        $this->setUser($student);
        $submission = $plugingen->create_assign_submission($scenario->activity, (int) $student->id, 'Grace alone.');
        $file = get_file_storage()->create_file_from_string([
            'contextid' => $scenario->context->id,
            'component' => 'assignsubmission_file',
            'filearea' => 'submission_files',
            'itemid' => $submission->id,
            'filepath' => '/',
            'filename' => 'essay.pdf',
        ], 'Not really a PDF.');

        // The grade, so the student's feedback page is released.
        $this->setUser($teacher);
        $course = get_course($scenario->course->id);
        $assign = new \assign($scenario->context, get_coursemodule_from_id('assign', $cmid), $course);
        $grade = $assign->get_user_grade((int) $student->id, true);
        $grade->grade = 80;
        $DB->update_record('assign_grades', $grade);

        $other = $generator->create_user();
        $plugingen->create_note([
            'cmid' => $cmid,
            'userid' => $student->id,
            'authorid' => $teacher->id,
            'content' => 'Private note about the student.',
        ]);
        $plugingen->create_annotation([
            'cmid' => $cmid,
            'userid' => $student->id,
            'authorid' => $teacher->id,
            'fileid' => $file->get_id(),
        ]);
        $plugingen->create_submission_comment([
            'cmid' => $cmid,
            'userid' => $student->id,
            'authorid' => $teacher->id,
            'content' => 'Comment on the submission.',
        ]);
        $plugingen->create_penalty([
            'cmid' => $cmid,
            'userid' => $student->id,
            'authorid' => $teacher->id,
            'category' => 'other',
            'label' => 'Formatting',
        ]);
        \local_unifiedgrader\referral_manager::refer($cmid, (int) $student->id, (int) $teacher->id, 'integrity', 'Overlap');
        segment_comment_manager::create(
            $cmid,
            (int) $student->id,
            (int) $teacher->id,
            0,
            0,
            'onlinetext',
            0,
            5,
            0,
            5,
            'grace',
            '<p>Define this term.</p>',
            FORMAT_HTML,
        );
        $plugingen->create_library_comment(['userid' => $teacher->id, 'content' => 'Own library comment']);
        $plugingen->create_library_tag(['userid' => $teacher->id, 'name' => 'Own library tag']);
        $plugingen->create_library_comment(['userid' => $other->id, 'content' => 'Shared library comment', 'shared' => 1]);

        // The forum: a post by the student, rated by the teacher.
        $forum = $plugingen->create_grading_scenario('forum', [
            'modparams' => ['grade_forum' => 0, 'assessed' => RATING_AGGREGATE_AVERAGE, 'scale' => 5],
        ]);
        $forumstudent = $forum->students[0];
        $post = $plugingen->create_forum_post($forum->activity, (int) $forumstudent->id);
        $this->setUser($forum->teacher);
        adapter_factory::create((int) $forum->cm->id)->save_post_rating((int) $post->post->id, 4);

        return (object) [
            'cmid' => $cmid,
            'forumcmid' => (int) $forum->cm->id,
            'studentid' => (int) $student->id,
            'forumstudentid' => (int) $forumstudent->id,
            'fileid' => (int) $file->get_id(),
            'teacher' => $teacher,
            'student' => $student,
            'forumteacher' => $forum->teacher,
            'postid' => (int) $post->post->id,
        ];
    }

    /**
     * Every read-only service leaves $SESSION as it found it, on a fixture that gives it work.
     *
     * Each call starts from an empty session and a fresh page, as a new request would, with the
     * user who makes it in the real client: the grader for the grader's reads, the student for the
     * annotations of the feedback page. The read-only branch of external_api::call_external_function()
     * is taken, as on a site with $CFG->enable_read_only_sessions on.
     *
     * The fixture itself goes through the write paths (a rating, a submission), so a write planted
     * on a read's path would already sit in a shared session with the same value, and a second
     * write of it would change nothing the comparison can see; hence the empty session per call.
     *
     * One require_login() runs before each snapshot. Core's after_require_login callbacks run
     * inside every service of every component, and under PHPUnit tool_mfa's writes a marker
     * ($SESSION->mfa_login_hook_test, admin/tool/mfa/lib.php); paying it first keeps that core
     * write out of the comparison without exempting any key from it.
     *
     * @return void
     */
    public function test_read_only_services_leave_the_session_untouched(): void {
        global $CFG, $PAGE;

        $this->resetAfterTest();
        $fx = $this->build_fixture();
        $CFG->enable_read_only_sessions = true;

        foreach (self::read_only_calls($fx) as $name => $call) {
            \core\session\manager::init_empty_session();
            $this->setUser($call['user']);
            $PAGE = new \moodle_page();
            require_login();
            $_POST['sesskey'] = sesskey();

            $before = self::session_snapshot();
            $result = external_api::call_external_function($name, $call['args'], true);
            $after = self::session_snapshot();

            $this->assertFalse($result['error'], "$name: " . json_encode($result['exception'] ?? null));
            // The control: the call found the fixture's data, so its whole path ran.
            $data = $result['data'];
            match ($name) {
                'local_unifiedgrader_get_activity_info' => $this->assertSame($fx->cmid, (int) $data['id'], $name),
                'local_unifiedgrader_get_participants' => $this->assertContains(
                    $fx->studentid,
                    array_map('intval', array_column($data, 'id')),
                    $name
                ),
                'local_unifiedgrader_get_submission_data' => $this->assertContains(
                    $fx->fileid,
                    array_map('intval', array_column($data['files'], 'fileid')),
                    $name
                ),
                'local_unifiedgrader_get_grade_data' => $this->assertEqualsWithDelta(80.0, (float) $data['grade'], 0.001, $name),
                'local_unifiedgrader_get_notes' => $this->assertContains(
                    'Private note about the student.',
                    array_column($data, 'rawcontent'),
                    $name
                ),
                'local_unifiedgrader_get_library_comments' => $this->assertContains(
                    'Own library comment',
                    array_column($data, 'content'),
                    $name
                ),
                'local_unifiedgrader_get_library_tags' => $this->assertContains(
                    'Own library tag',
                    array_column($data, 'name'),
                    $name
                ),
                'local_unifiedgrader_get_shared_library' => $this->assertContains(
                    'Shared library comment',
                    array_column($data, 'content'),
                    $name
                ),
                'local_unifiedgrader_get_annotations',
                'local_unifiedgrader_get_student_annotations' => $this->assertSame(
                    [$fx->fileid],
                    array_map('intval', array_column($data, 'fileid')),
                    $name
                ),
                'local_unifiedgrader_get_submission_comments' => $this->assertCount(1, $data['comments'], $name),
                'local_unifiedgrader_get_penalties' => $this->assertSame(['Formatting'], array_column($data, 'label'), $name),
                'local_unifiedgrader_get_referrals' => $this->assertSame(['integrity'], array_column($data, 'reason'), $name),
                'local_unifiedgrader_get_segment_comments' => $this->assertSame(
                    ['grace'],
                    array_column($data['comments'], 'anchortext'),
                    $name
                ),
                'local_unifiedgrader_get_post_ratings' => $this->assertSame(
                    [$fx->postid],
                    array_map('intval', array_column($data['posts'], 'postid')),
                    $name
                ),
                'local_unifiedgrader_get_post_context' => $this->assertContains(
                    $fx->postid,
                    array_map('intval', array_column($data['discussions'][0]['posts'] ?? [], 'id')),
                    $name
                ),
            };
            $this->assertSame([], self::changed_keys($before, $after), "$name changed \$SESSION");
        }
    }
}
