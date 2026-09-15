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
 * Tests for the comment library audit and repair engine.
 *
 * @package    local_unifiedgrader
 * @category   test
 * @copyright  2026 South African Theological Seminary (mathieu@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_unifiedgrader\library_audit
 */
final class library_audit_test extends \advanced_testcase {
    /**
     * Find the single inventory row for one owner and code.
     *
     * @param array $inventory Output of get_inventory().
     * @param int $userid
     * @param string $code
     * @return array|null
     */
    private function row_for(array $inventory, int $userid, string $code): ?array {
        foreach ($inventory as $row) {
            if ($row['userid'] === $userid && $row['coursecode'] === $code) {
                return $row;
            }
        }
        return null;
    }

    /**
     * A comment scoped to a real course, owned by a real teacher, is clean.
     */
    public function test_healthy_library_has_no_flags(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();

        $course = $generator->create_course(['shortname' => 'BIB3129']);
        $teacher = $generator->create_user();
        $generator->get_plugin_generator('local_unifiedgrader')->create_library_comment([
            'userid' => $teacher->id,
            'coursecode' => 'BIB3129',
        ]);

        $row = $this->row_for(library_audit::get_inventory(), $teacher->id, 'BIB3129');

        $this->assertNotNull($row);
        $this->assertSame([], $row['flags']);
        $this->assertSame(1, $row['numcomments']);
        $this->assertEquals($course->id, $row['courses'][0]['id']);
    }

    /**
     * Universal comments (empty code) are by design, not an anomaly.
     */
    public function test_universal_comments_are_not_flagged(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();

        $teacher = $generator->create_user();
        $generator->get_plugin_generator('local_unifiedgrader')->create_library_comment([
            'userid' => $teacher->id,
            'coursecode' => '',
        ]);

        $row = $this->row_for(library_audit::get_inventory(), $teacher->id, '');

        $this->assertNotNull($row);
        $this->assertTrue($row['isuniversal']);
        $this->assertSame([], $row['flags']);
    }

    /**
     * A code no course on the site produces is flagged as unknown.
     */
    public function test_unknown_code_is_flagged(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();

        $generator->create_course(['shortname' => 'BIB3127']);
        $teacher = $generator->create_user();
        $generator->get_plugin_generator('local_unifiedgrader')->create_library_comment([
            'userid' => $teacher->id,
            'coursecode' => 'BIB9999',
        ]);

        $row = $this->row_for(library_audit::get_inventory(), $teacher->id, 'BIB9999');

        $this->assertContains(library_audit::FLAG_UNKNOWN_CODE, $row['flags']);
        $this->assertSame([], $row['courses']);
    }

    /**
     * A sentinel leaked from the modal sidebar is flagged and short-circuits
     * the other code checks.
     */
    public function test_sentinel_code_is_flagged(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();

        $teacher = $generator->create_user();
        $generator->get_plugin_generator('local_unifiedgrader')->create_library_comment([
            'userid' => $teacher->id,
            'coursecode' => '__system__',
        ]);

        $row = $this->row_for(library_audit::get_inventory(), $teacher->id, '__system__');

        $this->assertSame([library_audit::FLAG_SENTINEL_CODE], $row['flags']);
    }

    /**
     * Leading whitespace splits a bucket in two and is invisible in the UI,
     * so it gets its own flag — and the padded and clean spellings are also
     * reported as competing variants of one code.
     *
     * Leading whitespace is used deliberately: MySQL's collation ignores
     * *trailing* spaces and case when comparing, so those spellings collapse
     * into one bucket at the database level and there is nothing to report.
     * A leading space is significant on every supported database.
     */
    public function test_padded_code_is_flagged_as_padded_and_variant(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('local_unifiedgrader');

        $generator->create_course(['shortname' => 'BIB3129']);
        $teacher = $generator->create_user();
        $plugingen->create_library_comment(['userid' => $teacher->id, 'coursecode' => 'BIB3129']);
        $plugingen->create_library_comment(['userid' => $teacher->id, 'coursecode' => ' BIB3129']);

        $inventory = library_audit::get_inventory();
        $padded = $this->row_for($inventory, $teacher->id, ' BIB3129');
        $clean = $this->row_for($inventory, $teacher->id, 'BIB3129');

        $this->assertNotNull($padded);
        $this->assertContains(library_audit::FLAG_PADDED_CODE, $padded['flags']);
        $this->assertContains(library_audit::FLAG_VARIANT_CODE, $padded['flags']);
        $this->assertContains(library_audit::FLAG_VARIANT_CODE, $clean['flags']);
    }

    /**
     * Variants are detected site-wide, so filtering to one teacher still
     * reports a clash with another teacher's spelling.
     */
    public function test_variant_detection_survives_a_user_filter(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('local_unifiedgrader');

        $generator->create_course(['shortname' => 'BIB3129']);
        $one = $generator->create_user();
        $two = $generator->create_user();
        $plugingen->create_library_comment(['userid' => $one->id, 'coursecode' => 'BIB3129']);
        $plugingen->create_library_comment(['userid' => $two->id, 'coursecode' => ' BIB3129']);

        $filtered = library_audit::get_inventory($one->id);
        $row = $this->row_for($filtered, $one->id, 'BIB3129');

        $this->assertCount(1, $filtered);
        $this->assertContains(library_audit::FLAG_VARIANT_CODE, $row['flags']);
    }

    /**
     * A system-default row (userid = 0) should never carry a course code.
     */
    public function test_system_row_with_a_code_is_flagged(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();

        $generator->create_course(['shortname' => 'BIB3129']);
        $generator->get_plugin_generator('local_unifiedgrader')->create_library_comment([
            'userid' => 0,
            'coursecode' => 'BIB3129',
        ]);

        $row = $this->row_for(library_audit::get_inventory(), 0, 'BIB3129');

        $this->assertContains(library_audit::FLAG_SYSTEM_WITH_CODE, $row['flags']);
    }

    /**
     * A deleted owner is flagged, and a userid with no user row at all is
     * flagged differently.
     */
    public function test_owner_problems_are_flagged(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('local_unifiedgrader');

        $generator->create_course(['shortname' => 'BIB3129']);
        $deleted = $generator->create_user(['deleted' => 1]);
        $plugingen->create_library_comment(['userid' => $deleted->id, 'coursecode' => 'BIB3129']);
        $plugingen->create_library_comment(['userid' => 999999, 'coursecode' => 'BIB3129']);

        $inventory = library_audit::get_inventory();

        $this->assertContains(
            library_audit::FLAG_DELETED_OWNER,
            $this->row_for($inventory, $deleted->id, 'BIB3129')['flags'],
        );
        $this->assertContains(
            library_audit::FLAG_NO_OWNER,
            $this->row_for($inventory, 999999, 'BIB3129')['flags'],
        );
    }

    /**
     * anomaliesonly drops healthy rows.
     */
    public function test_anomalies_only_filter(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('local_unifiedgrader');

        $generator->create_course(['shortname' => 'BIB3129']);
        $teacher = $generator->create_user();
        $plugingen->create_library_comment(['userid' => $teacher->id, 'coursecode' => 'BIB3129']);
        $plugingen->create_library_comment(['userid' => $teacher->id, 'coursecode' => 'GONE101']);

        $all = library_audit::get_inventory($teacher->id);
        $flagged = library_audit::get_inventory($teacher->id, '', true);

        $this->assertCount(2, $all);
        $this->assertCount(1, $flagged);
        $this->assertSame('GONE101', $flagged[0]['coursecode']);
    }

    /**
     * The code filter is a case-insensitive substring match.
     */
    public function test_code_filter_is_case_insensitive(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('local_unifiedgrader');

        $teacher = $generator->create_user();
        $plugingen->create_library_comment(['userid' => $teacher->id, 'coursecode' => 'BIB3129']);
        $plugingen->create_library_comment(['userid' => $teacher->id, 'coursecode' => 'THE2100']);

        $found = library_audit::get_inventory(0, 'bib31');

        $this->assertCount(1, $found);
        $this->assertSame('BIB3129', $found[0]['coursecode']);
    }

    /**
     * Re-scoping moves the comments and leaves everything else alone.
     */
    public function test_recode_comments(): void {
        global $DB;
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('local_unifiedgrader');

        $teacher = $generator->create_user();
        $one = $plugingen->create_library_comment(['userid' => $teacher->id, 'coursecode' => '']);
        $two = $plugingen->create_library_comment(['userid' => $teacher->id, 'coursecode' => '']);
        $untouched = $plugingen->create_library_comment(['userid' => $teacher->id, 'coursecode' => '']);

        $changed = library_audit::recode_comments([$one->id, $two->id], 'BIB3129');

        $this->assertSame(2, $changed);
        $this->assertSame('BIB3129', $DB->get_field('local_unifiedgrader_clib', 'coursecode', ['id' => $one->id]));
        $this->assertSame('BIB3129', $DB->get_field('local_unifiedgrader_clib', 'coursecode', ['id' => $two->id]));
        $this->assertSame('', $DB->get_field('local_unifiedgrader_clib', 'coursecode', ['id' => $untouched->id]));
    }

    /**
     * The owner argument confines a re-scope to that teacher's own rows —
     * this is what makes the self-service page safe.
     */
    public function test_recode_respects_the_owner_scope(): void {
        global $DB;
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('local_unifiedgrader');

        $mine = $generator->create_user();
        $theirs = $generator->create_user();
        $ownrow = $plugingen->create_library_comment(['userid' => $mine->id, 'coursecode' => '']);
        $otherrow = $plugingen->create_library_comment(['userid' => $theirs->id, 'coursecode' => '']);

        $changed = library_audit::recode_comments([$ownrow->id, $otherrow->id], 'BIB3129', $mine->id);

        $this->assertSame(1, $changed);
        $this->assertSame('BIB3129', $DB->get_field('local_unifiedgrader_clib', 'coursecode', ['id' => $ownrow->id]));
        $this->assertSame('', $DB->get_field('local_unifiedgrader_clib', 'coursecode', ['id' => $otherrow->id]));
    }

    /**
     * Re-scoping to an empty code makes a comment universal again.
     */
    public function test_recode_to_universal(): void {
        global $DB;
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();

        $teacher = $generator->create_user();
        $comment = $generator->get_plugin_generator('local_unifiedgrader')->create_library_comment([
            'userid' => $teacher->id,
            'coursecode' => 'BIB3129',
        ]);

        library_audit::recode_comments([$comment->id], '');

        $this->assertSame('', $DB->get_field('local_unifiedgrader_clib', 'coursecode', ['id' => $comment->id]));
    }

    /**
     * Reassigning to a non-existent user is refused rather than orphaning
     * the comments further.
     */
    public function test_reassign_rejects_an_invalid_owner(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();

        $teacher = $generator->create_user();
        $comment = $generator->get_plugin_generator('local_unifiedgrader')->create_library_comment([
            'userid' => $teacher->id,
        ]);

        $this->expectException(\moodle_exception::class);
        library_audit::reassign_comments([$comment->id], 999999);
    }

    /**
     * Reassignment moves ownership without disturbing scope or tags.
     */
    public function test_reassign_comments(): void {
        global $DB;
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('local_unifiedgrader');

        $from = $generator->create_user();
        $to = $generator->create_user();
        $comment = $plugingen->create_library_comment([
            'userid' => $from->id,
            'coursecode' => 'BIB3129',
        ]);
        $tag = $plugingen->create_library_tag(['userid' => $from->id]);
        $plugingen->create_tag_mapping(['commentid' => $comment->id, 'tagid' => $tag->id]);

        $changed = library_audit::reassign_comments([$comment->id], $to->id);

        $this->assertSame(1, $changed);
        $record = $DB->get_record('local_unifiedgrader_clib', ['id' => $comment->id]);
        $this->assertSame((int) $to->id, (int) $record->userid);
        $this->assertSame('BIB3129', $record->coursecode);
        $this->assertTrue($DB->record_exists('local_unifiedgrader_clmap', [
            'commentid' => $comment->id,
            'tagid' => $tag->id,
        ]));
    }

    /**
     * Orphaned mappings are found and purged; live mappings are left alone.
     */
    public function test_orphan_map_detection_and_purge(): void {
        global $DB;
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('local_unifiedgrader');

        $teacher = $generator->create_user();
        $comment = $plugingen->create_library_comment(['userid' => $teacher->id]);
        $tag = $plugingen->create_library_tag(['userid' => $teacher->id]);
        $live = $plugingen->create_tag_mapping(['commentid' => $comment->id, 'tagid' => $tag->id]);
        $deadcomment = $plugingen->create_tag_mapping(['commentid' => 999999, 'tagid' => $tag->id]);
        $deadtag = $plugingen->create_tag_mapping(['commentid' => $comment->id, 'tagid' => 999999]);

        $orphans = library_audit::get_orphan_maps();
        $this->assertCount(2, $orphans);

        $removed = library_audit::purge_orphan_maps();

        $this->assertSame(2, $removed);
        $this->assertTrue($DB->record_exists('local_unifiedgrader_clmap', ['id' => $live->id]));
        $this->assertFalse($DB->record_exists('local_unifiedgrader_clmap', ['id' => $deadcomment->id]));
        $this->assertFalse($DB->record_exists('local_unifiedgrader_clmap', ['id' => $deadtag->id]));
    }

    /**
     * Legacy v1 rows are surfaced with the code they would be filed under.
     */
    public function test_unmigrated_legacy_rows_are_listed(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();

        $course = $generator->create_course(['shortname' => 'BIB3129']);
        $teacher = $generator->create_user();
        $generator->get_plugin_generator('local_unifiedgrader')->create_legacy_comment([
            'userid' => $teacher->id,
            'courseid' => $course->id,
            'content' => 'Legacy feedback',
        ]);

        $legacy = library_audit::get_unmigrated_legacy($teacher->id);

        $this->assertCount(1, $legacy);
        $this->assertSame('BIB3129', $legacy[0]['wouldbecode']);
        $this->assertFalse($legacy[0]['coursemissing']);
    }

    /**
     * A legacy row whose course was deleted is reported as such, and would
     * import as universal rather than silently vanishing.
     */
    public function test_legacy_row_with_a_deleted_course(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();

        $teacher = $generator->create_user();
        $generator->get_plugin_generator('local_unifiedgrader')->create_legacy_comment([
            'userid' => $teacher->id,
            'courseid' => 999999,
        ]);

        $legacy = library_audit::get_unmigrated_legacy($teacher->id);

        $this->assertTrue($legacy[0]['coursemissing']);
        $this->assertSame('', $legacy[0]['wouldbecode']);
    }

    /**
     * Importing legacy rows is additive and idempotent — running it twice
     * must not duplicate a teacher's library.
     */
    public function test_legacy_import_is_idempotent(): void {
        global $DB;
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();

        $course = $generator->create_course(['shortname' => 'BIB3129']);
        $teacher = $generator->create_user();
        $generator->get_plugin_generator('local_unifiedgrader')->create_legacy_comment([
            'userid' => $teacher->id,
            'courseid' => $course->id,
            'content' => 'Legacy feedback',
        ]);

        $first = library_audit::import_legacy($teacher->id);
        $second = library_audit::import_legacy($teacher->id);

        $this->assertSame(1, $first);
        $this->assertSame(0, $second);
        $this->assertSame(1, $DB->count_records('local_unifiedgrader_clib', [
            'userid' => $teacher->id,
            'coursecode' => 'BIB3129',
        ]));
    }

    /**
     * A successfully imported row is removed from the legacy table. Without
     * this, get_unmigrated_legacy() reports the same row forever and the
     * moderation page's backlog never clears even after every comment has
     * genuinely been recovered.
     */
    public function test_legacy_import_deletes_the_source_row(): void {
        global $DB;
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();

        $course = $generator->create_course(['shortname' => 'BIB3129']);
        $teacher = $generator->create_user();
        $legacy = $generator->get_plugin_generator('local_unifiedgrader')->create_legacy_comment([
            'userid' => $teacher->id,
            'courseid' => $course->id,
            'content' => 'Legacy feedback',
        ]);

        $imported = library_audit::import_legacy($teacher->id);

        $this->assertSame(1, $imported);
        $this->assertFalse($DB->record_exists('local_unifiedgrader_comments', ['id' => $legacy->id]));
        $this->assertSame([], library_audit::get_unmigrated_legacy($teacher->id));
    }

    /**
     * A legacy row whose content already exists in v2 — because a prior
     * import copied it, or the teacher separately re-saved the same
     * comment — is not re-inserted, but its now-redundant legacy source is
     * still removed rather than left to clutter the backlog indefinitely.
     */
    public function test_legacy_import_clears_source_for_an_existing_duplicate(): void {
        global $DB;
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('local_unifiedgrader');

        $course = $generator->create_course(['shortname' => 'BIB3129']);
        $teacher = $generator->create_user();
        $plugingen->create_library_comment([
            'userid' => $teacher->id,
            'coursecode' => 'BIB3129',
            'content' => 'Already in v2',
        ]);
        $legacy = $plugingen->create_legacy_comment([
            'userid' => $teacher->id,
            'courseid' => $course->id,
            'content' => 'Already in v2',
        ]);

        $imported = library_audit::import_legacy($teacher->id);

        // Nothing new was inserted (the row already existed)...
        $this->assertSame(0, $imported);
        $this->assertSame(1, $DB->count_records('local_unifiedgrader_clib', [
            'userid' => $teacher->id,
            'coursecode' => 'BIB3129',
        ]));
        // ...but the now-redundant legacy source is gone rather than stuck
        // reporting forever.
        $this->assertFalse($DB->record_exists('local_unifiedgrader_comments', ['id' => $legacy->id]));
    }

    /**
     * Empty input is a no-op rather than a mass update.
     */
    public function test_repairs_ignore_empty_input(): void {
        $this->resetAfterTest();

        $this->assertSame(0, library_audit::recode_comments([], 'BIB3129'));
        $this->assertSame(0, library_audit::reassign_comments([], 1));
        $this->assertSame(0, library_audit::purge_orphan_maps());
        $this->assertSame(0, library_audit::delete_comments([]));
    }

    /**
     * Deleting a comment removes its tag mappings too, and leaves comments
     * outside the requested set untouched.
     */
    public function test_delete_comments(): void {
        global $DB;
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('local_unifiedgrader');

        $teacher = $generator->create_user();
        $target = $plugingen->create_library_comment(['userid' => $teacher->id]);
        $untouched = $plugingen->create_library_comment(['userid' => $teacher->id]);
        $tag = $plugingen->create_library_tag(['userid' => $teacher->id]);
        $plugingen->create_tag_mapping(['commentid' => $target->id, 'tagid' => $tag->id]);

        $deleted = library_audit::delete_comments([$target->id]);

        $this->assertSame(1, $deleted);
        $this->assertFalse($DB->record_exists('local_unifiedgrader_clib', ['id' => $target->id]));
        $this->assertFalse($DB->record_exists('local_unifiedgrader_clmap', ['commentid' => $target->id]));
        $this->assertTrue($DB->record_exists('local_unifiedgrader_clib', ['id' => $untouched->id]));
    }

    /**
     * The owner argument confines a delete to that teacher's own rows —
     * this is what makes the self-service delete safe.
     */
    public function test_delete_comments_respects_the_owner_scope(): void {
        global $DB;
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('local_unifiedgrader');

        $mine = $generator->create_user();
        $theirs = $generator->create_user();
        $ownrow = $plugingen->create_library_comment(['userid' => $mine->id]);
        $otherrow = $plugingen->create_library_comment(['userid' => $theirs->id]);

        $deleted = library_audit::delete_comments([$ownrow->id, $otherrow->id], $mine->id);

        $this->assertSame(1, $deleted);
        $this->assertFalse($DB->record_exists('local_unifiedgrader_clib', ['id' => $ownrow->id]));
        $this->assertTrue($DB->record_exists('local_unifiedgrader_clib', ['id' => $otherrow->id]));
    }

    /**
     * A comment with no duplicate is not reported.
     */
    public function test_find_duplicate_comments_ignores_unique_rows(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $teacher = $generator->create_user();
        $generator->get_plugin_generator('local_unifiedgrader')->create_library_comment([
            'userid' => $teacher->id,
            'content' => 'One of a kind',
        ]);

        $this->assertSame([], library_audit::find_duplicate_comments($teacher->id));
    }

    /**
     * Exact repeats — same owner, course code and content — are grouped
     * together, oldest first, with the group's own tags attached per copy.
     */
    public function test_find_duplicate_comments_groups_exact_repeats(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('local_unifiedgrader');
        $teacher = $generator->create_user();

        $older = $plugingen->create_library_comment([
            'userid' => $teacher->id,
            'coursecode' => 'BIB3129',
            'content' => 'Repeated comment',
            'timecreated' => 1000,
        ]);
        $newer = $plugingen->create_library_comment([
            'userid' => $teacher->id,
            'coursecode' => 'BIB3129',
            'content' => 'Repeated comment',
            'timecreated' => 2000,
        ]);

        $groups = library_audit::find_duplicate_comments($teacher->id);

        $this->assertCount(1, $groups);
        $this->assertSame('BIB3129', $groups[0]['coursecode']);
        $this->assertSame('Repeated comment', $groups[0]['content']);
        $this->assertCount(2, $groups[0]['comments']);
        $this->assertSame($older->id, $groups[0]['comments'][0]['id']);
        $this->assertSame($newer->id, $groups[0]['comments'][1]['id']);
    }

    /**
     * The same content under two different course codes is not a
     * duplicate — a teacher reusing one generic comment across courses is
     * legitimate, not a data problem.
     */
    public function test_find_duplicate_comments_scopes_by_coursecode(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('local_unifiedgrader');
        $teacher = $generator->create_user();

        $plugingen->create_library_comment([
            'userid' => $teacher->id,
            'coursecode' => 'BIB3127',
            'content' => 'Well cited',
        ]);
        $plugingen->create_library_comment([
            'userid' => $teacher->id,
            'coursecode' => 'BIB3129',
            'content' => 'Well cited',
        ]);

        $this->assertSame([], library_audit::find_duplicate_comments($teacher->id));
    }

    /**
     * Duplicates are scoped per owner even when filtering site-wide — two
     * teachers who happen to save the same comment are not "duplicates" of
     * each other.
     */
    public function test_find_duplicate_comments_scopes_by_owner(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('local_unifiedgrader');
        $one = $generator->create_user();
        $two = $generator->create_user();

        $plugingen->create_library_comment(['userid' => $one->id, 'content' => 'Same words']);
        $plugingen->create_library_comment(['userid' => $two->id, 'content' => 'Same words']);

        $this->assertSame([], library_audit::find_duplicate_comments());
    }
}
