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
 * Audit and repair engine for comment libraries.
 *
 * Comment library entries are scoped by a free-text `coursecode` string
 * rather than a foreign key to a course, so nothing at the database level
 * stops an entry drifting out of reach of the teacher who wrote it: a code
 * that no longer matches any course, a sentinel string leaked from the UI,
 * an owner who no longer exists. This class finds those cases and repairs
 * them.
 *
 * @package    local_unifiedgrader
 * @copyright  2026 South African Theological Seminary (mathieu@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_unifiedgrader;

/**
 * Finds and repairs anomalies in the comment library tables.
 */
class library_audit {
    /** @var string Owner row is missing from the user table entirely. */
    public const FLAG_NO_OWNER = 'noowner';

    /** @var string Owner exists but is flagged deleted. */
    public const FLAG_DELETED_OWNER = 'deletedowner';

    /** @var string A system-default row (userid = 0) carrying a course code. */
    public const FLAG_SYSTEM_WITH_CODE = 'systemwithcode';

    /** @var string Code is a UI sentinel (__system__, __universal__) stored literally. */
    public const FLAG_SENTINEL_CODE = 'sentinelcode';

    /** @var string Code has leading or trailing whitespace. */
    public const FLAG_PADDED_CODE = 'paddedcode';

    /** @var string Code matches no course on the site. */
    public const FLAG_UNKNOWN_CODE = 'unknowncode';

    /** @var string Code differs only by case or padding from another code in use. */
    public const FLAG_VARIANT_CODE = 'variantcode';

    /**
     * Every anomaly flag, in the order the report should present them —
     * most destructive first.
     *
     * @return string[]
     */
    public static function all_flags(): array {
        return [
            self::FLAG_NO_OWNER,
            self::FLAG_DELETED_OWNER,
            self::FLAG_SYSTEM_WITH_CODE,
            self::FLAG_SENTINEL_CODE,
            self::FLAG_PADDED_CODE,
            self::FLAG_UNKNOWN_CODE,
            self::FLAG_VARIANT_CODE,
        ];
    }

    /**
     * Normalise a course code for comparison: trim, collapse case.
     *
     * Used to decide whether two stored codes are "the same code, typed
     * differently" — the most common cause of a library appearing to vanish.
     *
     * @param string $code
     * @return string
     */
    public static function normalise_code(string $code): string {
        return \core_text::strtolower(trim($code));
    }

    /**
     * Every course code the site can currently produce, keyed by its
     * normalised form.
     *
     * Runs the configured extraction regex over every course shortname, so
     * the result reflects what `course_code_helper` would store *today* —
     * which is the yardstick for deciding a stored code is unknown.
     *
     * @return array Normalised code => ['code' => string, 'courses' => array of [id, shortname]].
     */
    public static function get_known_codes(): array {
        global $DB;

        // Deliberately uncached: courses are created, renamed and deleted
        // while an admin is working through this page, and a stale map would
        // mislabel a code as unknown.
        $map = [];
        $courses = $DB->get_records_select(
            'course',
            'id <> :siteid',
            ['siteid' => SITEID],
            'shortname ASC',
            'id, shortname, fullname, visible',
        );

        foreach ($courses as $course) {
            $code = course_code_helper::extract_code($course->shortname);
            if (trim($code) === '') {
                continue;
            }
            $key = self::normalise_code($code);
            if (!isset($map[$key])) {
                $map[$key] = ['code' => $code, 'courses' => []];
            }
            $map[$key]['courses'][] = [
                'id' => (int) $course->id,
                'shortname' => $course->shortname,
                'fullname' => $course->fullname,
                'visible' => (int) $course->visible,
            ];
        }

        return $map;
    }

    /**
     * Inventory of every library on the site: one row per owner per course code.
     *
     * @param int $userid Restrict to a single owner (0 = every owner).
     * @param string $codefilter Restrict to codes containing this substring (case-insensitive).
     * @param bool $anomaliesonly Return only rows carrying at least one flag.
     * @return array List of inventory rows.
     */
    public static function get_inventory(
        int $userid = 0,
        string $codefilter = '',
        bool $anomaliesonly = false,
    ): array {
        global $DB;

        $where = ['1 = 1'];
        $params = [];

        if ($userid > 0) {
            $where[] = 'c.userid = :userid';
            $params['userid'] = $userid;
        }
        if (trim($codefilter) !== '') {
            $where[] = $DB->sql_like(
                $DB->sql_compare_text('c.coursecode', 255),
                ':codefilter',
                false,
            );
            $params['codefilter'] = '%' . $DB->sql_like_escape(trim($codefilter)) . '%';
        }

        // Aggregate in PHP, keyed on the exact course code, and resolve owners
        // separately. A GROUP BY compares codes under the database collation,
        // which on MariaDB merges case and trailing-space variants into one row
        // that the exact-matching bucket view and export cannot then open.
        $sql = "SELECT c.userid, c.coursecode, c.shared, c.timecreated, c.timemodified
                  FROM {local_unifiedgrader_clib} c
                 WHERE " . implode(' AND ', $where);

        $rows = $DB->get_recordset_sql($sql, $params);

        $collected = [];
        foreach ($rows as $row) {
            $key = (int) $row->userid . "\0" . (string) $row->coursecode;
            if (!isset($collected[$key])) {
                $collected[$key] = (object) [
                    'userid' => (int) $row->userid,
                    'coursecode' => (string) $row->coursecode,
                    'numcomments' => 0,
                    'numshared' => 0,
                    'firstcreated' => (int) $row->timecreated,
                    'lastmodified' => (int) $row->timemodified,
                ];
            }
            $bucket = $collected[$key];
            $bucket->numcomments++;
            $bucket->numshared += (int) $row->shared;
            $bucket->firstcreated = min($bucket->firstcreated, (int) $row->timecreated);
            $bucket->lastmodified = max($bucket->lastmodified, (int) $row->timemodified);
        }
        $rows->close();

        uasort($collected, fn($a, $b) => ($a->userid <=> $b->userid) ?: strcmp($a->coursecode, $b->coursecode));

        $owners = self::load_owners(array_map(fn($r) => (int) $r->userid, $collected));
        $usagebynormal = self::get_code_usage();
        $known = self::get_known_codes();
        $out = [];

        foreach ($collected as $row) {
            $code = (string) $row->coursecode;
            $ownerid = (int) $row->userid;
            $owner = $owners[$ownerid] ?? null;
            $flags = self::flag_row($ownerid, $owner, $code, $known, $usagebynormal);

            if ($anomaliesonly && empty($flags)) {
                continue;
            }

            $out[] = [
                'userid' => $ownerid,
                'ownername' => self::describe_owner($ownerid, $owner),
                'ownerdeleted' => $owner !== null && !empty($owner->deleted),
                'ownersuspended' => $owner !== null && !empty($owner->suspended),
                'coursecode' => $code,
                'isuniversal' => trim($code) === '',
                'numcomments' => (int) $row->numcomments,
                'numshared' => (int) $row->numshared,
                'firstcreated' => (int) $row->firstcreated,
                'lastmodified' => (int) $row->lastmodified,
                'courses' => $known[self::normalise_code($code)]['courses'] ?? [],
                'flags' => $flags,
            ];
        }

        return $out;
    }

    /**
     * Every distinct course code in use across the whole site, grouped by its
     * normalised form.
     *
     * Deliberately unfiltered: a code only counts as a "variant" relative to
     * every other spelling in use, so narrowing this to the filtered set would
     * hide the exact case an admin filtering by one teacher is looking for.
     *
     * @return array Normalised code => list of raw spellings in use.
     */
    private static function get_code_usage(): array {
        global $DB;

        // Distinct in PHP: SELECT DISTINCT uses the database collation, which on
        // MariaDB returns one spelling for 'BIB3129' and 'bib3129', so the pair
        // was never reported as competing variants there.
        $sql = "SELECT coursecode
                  FROM {local_unifiedgrader_clib}";

        $usage = [];
        foreach ($DB->get_fieldset_sql($sql) as $rawcode) {
            $rawcode = (string) $rawcode;
            if (trim($rawcode) === '') {
                continue;
            }
            $usage[self::normalise_code($rawcode)][$rawcode] = $rawcode;
        }

        return array_map('array_values', $usage);
    }

    /**
     * Load the owner records behind a set of inventory rows.
     *
     * Deleted users are included — a library owned by a deleted account is
     * exactly what this tool exists to surface.
     *
     * @param int[] $userids
     * @return array userid => user record with name fields, deleted and suspended.
     */
    private static function load_owners(array $userids): array {
        global $DB;

        $userids = array_values(array_unique(array_filter($userids)));
        if (empty($userids)) {
            return [];
        }

        $namefields = \core_user\fields::for_name()->get_sql('', false, '', '', false)->selects;
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'uid');

        return $DB->get_records_sql(
            "SELECT id, deleted, suspended, {$namefields}
               FROM {user}
              WHERE id {$insql}",
            $params,
        );
    }

    /**
     * Work out which anomaly flags apply to one inventory row.
     *
     * @param int $ownerid The stored owner id.
     * @param object|null $owner The resolved user record, or null if there is none.
     * @param string $code The stored course code.
     * @param array $known Output of get_known_codes().
     * @param array $usagebynormal Normalised code => list of raw codes in use.
     * @return string[] Flags.
     */
    private static function flag_row(
        int $ownerid,
        ?object $owner,
        string $code,
        array $known,
        array $usagebynormal,
    ): array {
        $flags = [];
        $trimmed = trim($code);

        if ($ownerid === 0) {
            // A userid of 0 is the legitimate system-default owner, but those
            // rows are always written with an empty code. A code here means
            // something wrote a scoped comment into the system bucket.
            if ($trimmed !== '') {
                $flags[] = self::FLAG_SYSTEM_WITH_CODE;
            }
        } else if ($owner === null) {
            $flags[] = self::FLAG_NO_OWNER;
        } else if (!empty($owner->deleted)) {
            $flags[] = self::FLAG_DELETED_OWNER;
        }

        if ($trimmed === '') {
            // Universal — by design, not an anomaly.
            return $flags;
        }

        if (str_starts_with($trimmed, '__') && str_ends_with($trimmed, '__')) {
            $flags[] = self::FLAG_SENTINEL_CODE;
            // A sentinel is never a real code; the checks below would only
            // add noise on top of it.
            return $flags;
        }

        if ($code !== $trimmed) {
            $flags[] = self::FLAG_PADDED_CODE;
        }

        $key = self::normalise_code($code);
        if (!isset($known[$key])) {
            $flags[] = self::FLAG_UNKNOWN_CODE;
        }

        // More than one spelling of the same normalised code is in use
        // somewhere on the site — the teacher sees them as separate buckets.
        if (count(array_unique($usagebynormal[$key] ?? [])) > 1) {
            $flags[] = self::FLAG_VARIANT_CODE;
        }

        return $flags;
    }

    /**
     * Human-readable label for a library owner.
     *
     * Used for inventory rows and for the bucket view heading. An owner with no user row is
     * labelled from the lang string rather than through fullname(), which needs every name
     * field and raises a debugging notice for a partial user object.
     *
     * @param int $ownerid The stored owner id.
     * @param object|null $owner The resolved user record, or null if there is none.
     * @return string
     */
    public static function describe_owner(int $ownerid, ?object $owner): string {
        if ($ownerid === 0) {
            return get_string('clibmod_system_owner', 'local_unifiedgrader');
        }
        if ($owner === null) {
            return get_string('clibmod_missing_owner', 'local_unifiedgrader', $ownerid);
        }
        return fullname($owner);
    }

    /**
     * The individual comments behind one inventory row.
     *
     * @param int $userid Owner.
     * @param string $coursecode Exact stored code (empty string = universal).
     * @return array Comment records with tag names attached.
     */
    public static function get_comments_for(int $userid, string $coursecode): array {
        global $DB;

        // The code is matched in PHP rather than in the query. MariaDB's default collation
        // folds case and ignores trailing spaces, so a coursecode = :coursecode predicate
        // returned the rows of 'bib3129' and 'BIB3129 ' for bucket 'BIB3129' there, and only
        // that bucket's rows on PostgreSQL.
        $records = array_filter(
            $DB->get_records('local_unifiedgrader_clib', ['userid' => $userid], 'timecreated ASC'),
            fn($r) => $r->coursecode === $coursecode,
        );

        if (empty($records)) {
            return [];
        }

        [$insql, $inparams] = $DB->get_in_or_equal(array_keys($records), SQL_PARAMS_NAMED, 'cid');
        $tagsql = "SELECT m.id, m.commentid, t.name
                     FROM {local_unifiedgrader_clmap} m
                     JOIN {local_unifiedgrader_cltag} t ON t.id = m.tagid
                    WHERE m.commentid {$insql}
                 ORDER BY t.sortorder ASC";
        $tagnames = [];
        foreach ($DB->get_records_sql($tagsql, $inparams) as $maprow) {
            $tagnames[(int) $maprow->commentid][] = $maprow->name;
        }

        $out = [];
        foreach ($records as $record) {
            $out[] = [
                'id' => (int) $record->id,
                'content' => $record->content,
                'shared' => (int) $record->shared,
                'tags' => $tagnames[(int) $record->id] ?? [],
                'timecreated' => (int) $record->timecreated,
                'timemodified' => (int) $record->timemodified,
            ];
        }

        return $out;
    }

    /**
     * Tag mappings pointing at a comment or tag that no longer exists.
     *
     * These are produced by the historic unscoped delete in
     * comment_library_manager::delete_comment(); they are always safe to purge.
     *
     * @return array Rows with id, commentid, tagid and which side is missing.
     */
    public static function get_orphan_maps(): array {
        global $DB;

        $sql = "SELECT m.id, m.commentid, m.tagid,
                       CASE WHEN c.id IS NULL THEN 1 ELSE 0 END AS commentmissing,
                       CASE WHEN t.id IS NULL THEN 1 ELSE 0 END AS tagmissing
                  FROM {local_unifiedgrader_clmap} m
             LEFT JOIN {local_unifiedgrader_clib} c ON c.id = m.commentid
             LEFT JOIN {local_unifiedgrader_cltag} t ON t.id = m.tagid
                 WHERE c.id IS NULL OR t.id IS NULL
              ORDER BY m.id ASC";

        $out = [];
        foreach ($DB->get_records_sql($sql) as $row) {
            $out[] = [
                'id' => (int) $row->id,
                'commentid' => (int) $row->commentid,
                'tagid' => (int) $row->tagid,
                'commentmissing' => !empty($row->commentmissing),
                'tagmissing' => !empty($row->tagmissing),
            ];
        }

        return $out;
    }

    /**
     * Rows still sitting in the pre-v2 comments table that were never
     * migrated into the v2 library.
     *
     * The v1 to v2 migration reads {local_unifiedgrader_comments} but never
     * drops it, so a library stranded by a failed or partial upgrade is still
     * recoverable from here.
     *
     * @param int $userid Restrict to one owner (0 = all).
     * @return array Legacy rows with their resolved course shortname.
     */
    public static function get_unmigrated_legacy(int $userid = 0): array {
        global $DB;

        $dbman = $DB->get_manager();
        if (!$dbman->table_exists('local_unifiedgrader_comments')) {
            return [];
        }

        $params = [];
        $where = '1 = 1';
        if ($userid > 0) {
            $where = 'o.userid = :userid';
            $params['userid'] = $userid;
        }

        $sql = "SELECT o.id, o.userid, o.courseid, o.content, o.timecreated, o.timemodified,
                       co.shortname
                  FROM {local_unifiedgrader_comments} o
             LEFT JOIN {course} co ON co.id = o.courseid
                 WHERE {$where}
              ORDER BY o.userid ASC, o.courseid ASC, o.sortorder ASC";

        $out = [];
        foreach ($DB->get_records_sql($sql, $params) as $row) {
            $shortname = $row->shortname !== null ? $row->shortname : '';
            $out[] = [
                'id' => (int) $row->id,
                'userid' => (int) $row->userid,
                'courseid' => (int) $row->courseid,
                'shortname' => $shortname,
                'coursemissing' => $row->shortname === null && (int) $row->courseid !== 0,
                'wouldbecode' => $shortname !== '' ? course_code_helper::extract_code($shortname) : '',
                'content' => $row->content,
                'timecreated' => (int) $row->timecreated,
                'timemodified' => (int) $row->timemodified,
            ];
        }

        return $out;
    }

    // Repair operations.

    /**
     * Re-scope comments to a different course code.
     *
     * @param int[] $commentids Comments to move.
     * @param string $newcode The code to write (empty string = universal).
     * @param int $owner When non-zero, only comments owned by this user are touched.
     * @return int Number of comments changed.
     */
    public static function recode_comments(array $commentids, string $newcode, int $owner = 0): int {
        global $DB;

        $commentids = array_values(array_unique(array_filter(array_map('intval', $commentids))));
        if (empty($commentids)) {
            return 0;
        }
        // Refused before the UPDATE, as reassign_comments() refuses a bad owner. A code longer
        // than the column made the database throw instead, aborting the page with an error.
        if (\core_text::strlen($newcode) > library_csv::MAX_CODE_LENGTH) {
            throw new \moodle_exception('clibmod_code_too_long', 'local_unifiedgrader');
        }

        [$insql, $params] = $DB->get_in_or_equal($commentids, SQL_PARAMS_NAMED, 'cid');
        $where = "id {$insql}";
        if ($owner > 0) {
            $where .= ' AND userid = :owner';
            $params['owner'] = $owner;
        }

        // Read first so the count reflects rows actually eligible, not the
        // size of the requested set.
        $targets = $DB->get_fieldset_select('local_unifiedgrader_clib', 'id', $where, $params);
        if (empty($targets)) {
            return 0;
        }

        [$targetsql, $targetparams] = $DB->get_in_or_equal($targets, SQL_PARAMS_NAMED, 'tid');
        $targetparams['newcode'] = $newcode;
        $targetparams['now'] = time();
        $DB->execute(
            "UPDATE {local_unifiedgrader_clib}
                SET coursecode = :newcode, timemodified = :now
              WHERE id {$targetsql}",
            $targetparams,
        );

        return count($targets);
    }

    /**
     * Move comments to a different owner.
     *
     * @param int[] $commentids Comments to reassign.
     * @param int $newuserid The new owner. Must be an existing, undeleted user.
     * @return int Number of comments changed.
     */
    public static function reassign_comments(array $commentids, int $newuserid): int {
        global $DB;

        $commentids = array_values(array_unique(array_filter(array_map('intval', $commentids))));
        if (empty($commentids)) {
            return 0;
        }
        if (!$DB->record_exists('user', ['id' => $newuserid, 'deleted' => 0])) {
            throw new \moodle_exception('clibmod_invalid_owner', 'local_unifiedgrader');
        }

        // Read first so the count reflects rows that exist, not the size of the requested
        // set, as recode_comments() and delete_comments() do. The count goes into the
        // library_repaired event, the tool's only audit trail.
        [$insql, $params] = $DB->get_in_or_equal($commentids, SQL_PARAMS_NAMED, 'cid');
        $targets = $DB->get_fieldset_select('local_unifiedgrader_clib', 'id', "id {$insql}", $params);
        if (empty($targets)) {
            return 0;
        }

        [$targetsql, $targetparams] = $DB->get_in_or_equal($targets, SQL_PARAMS_NAMED, 'tid');
        $targetparams['newuserid'] = $newuserid;
        $targetparams['now'] = time();
        $DB->execute(
            "UPDATE {local_unifiedgrader_clib}
                SET userid = :newuserid, timemodified = :now
              WHERE id {$targetsql}",
            $targetparams,
        );

        return count($targets);
    }

    /**
     * Delete a set of comments and their tag mappings.
     *
     * @param int[] $commentids Comments to delete.
     * @param int $owner When non-zero, only comments owned by this user are touched —
     *                   this is what makes the teacher self-service delete safe.
     * @return int Number of comments deleted.
     */
    public static function delete_comments(array $commentids, int $owner = 0): int {
        global $DB;

        $commentids = array_values(array_unique(array_filter(array_map('intval', $commentids))));
        if (empty($commentids)) {
            return 0;
        }

        [$insql, $params] = $DB->get_in_or_equal($commentids, SQL_PARAMS_NAMED, 'cid');
        $where = "id {$insql}";
        if ($owner > 0) {
            $where .= ' AND userid = :owner';
            $params['owner'] = $owner;
        }

        // Read first so the count reflects rows actually eligible, not the
        // size of the requested set — mirrors recode_comments/reassign_comments.
        $targets = $DB->get_fieldset_select('local_unifiedgrader_clib', 'id', $where, $params);
        if (empty($targets)) {
            return 0;
        }

        [$targetsql, $targetparams] = $DB->get_in_or_equal($targets, SQL_PARAMS_NAMED, 'tid');
        $DB->delete_records_select('local_unifiedgrader_clib', "id {$targetsql}", $targetparams);
        $DB->delete_records_select('local_unifiedgrader_clmap', "commentid {$targetsql}", $targetparams);

        return count($targets);
    }

    /**
     * Groups of exact duplicates — same owner, course code and content —
     * most often produced by re-importing the same file twice, a slow
     * connection causing a double-submit, or a comment saved before and
     * after a page reload lost the "already saved" state.
     *
     * @param int $userid Restrict to one owner (0 = every owner).
     * @return array List of groups: userid, coursecode, content, and the
     *               member comment rows (oldest first).
     */
    public static function find_duplicate_comments(int $userid = 0): array {
        global $DB;

        $where = '1 = 1';
        $params = [];
        if ($userid > 0) {
            $where = 'userid = :userid';
            $params['userid'] = $userid;
        }

        // Content is TEXT, which can't be GROUP BY'd portably across every
        // supported database, so group in PHP instead — libraries are small
        // enough that this is cheap, and it avoids a database-specific
        // HAVING COUNT() clause.
        $records = $DB->get_records_select(
            'local_unifiedgrader_clib',
            $where,
            $params,
            'userid ASC, coursecode ASC, timecreated ASC',
        );

        $groups = [];
        foreach ($records as $r) {
            $key = $r->userid . "\0" . $r->coursecode . "\0" . $r->content;
            $groups[$key][] = $r;
        }

        $tagnames = self::tag_names_for_duplicates(array_keys($records));

        $out = [];
        foreach ($groups as $rows) {
            if (count($rows) < 2) {
                continue;
            }
            $first = reset($rows);
            $out[] = [
                'userid' => (int) $first->userid,
                'coursecode' => $first->coursecode,
                'content' => $first->content,
                'comments' => array_map(fn($r) => [
                    'id' => (int) $r->id,
                    'tags' => $tagnames[(int) $r->id] ?? [],
                    'shared' => (int) $r->shared,
                    'timecreated' => (int) $r->timecreated,
                ], array_values($rows)),
            ];
        }

        return $out;
    }

    /**
     * Batch-fetch tag names for a set of comment ids, for find_duplicate_comments().
     *
     * @param int[] $commentids
     * @return array<int,string[]> commentid => list of tag names.
     */
    private static function tag_names_for_duplicates(array $commentids): array {
        global $DB;

        if (empty($commentids)) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($commentids, SQL_PARAMS_NAMED, 'cid');
        $sql = "SELECT m.id, m.commentid, t.name
                  FROM {local_unifiedgrader_clmap} m
                  JOIN {local_unifiedgrader_cltag} t ON t.id = m.tagid
                 WHERE m.commentid {$insql}
              ORDER BY t.sortorder ASC, t.name ASC";

        $out = [];
        foreach ($DB->get_records_sql($sql, $params) as $row) {
            $out[(int) $row->commentid][] = $row->name;
        }

        return $out;
    }

    /**
     * Delete every tag mapping whose comment or tag no longer exists.
     *
     * @return int Number of mappings removed.
     */
    public static function purge_orphan_maps(): int {
        global $DB;

        $orphans = self::get_orphan_maps();
        if (empty($orphans)) {
            return 0;
        }

        $ids = array_column($orphans, 'id');
        [$insql, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'mid');
        $DB->delete_records_select('local_unifiedgrader_clmap', "id {$insql}", $params);

        return count($ids);
    }

    /**
     * Copy legacy v1 rows into the v2 library without touching anything
     * already there, then remove the source rows once each copy is
     * confirmed present.
     *
     * Unlike the upgrade-time migration, this is additive: it skips
     * inserting a duplicate when the owner already has an identical
     * comment, so it can be run repeatedly and used to recover a single
     * teacher. But "additive" is about the v2 table, not about leaving v1
     * alone — a row that has been confirmed migrated (whether by this call
     * or an earlier one) serves no further purpose in the legacy table, and
     * leaving it there means get_unmigrated_legacy() reports it forever,
     * so the moderation page never shows the backlog as cleared even after
     * every row has genuinely been recovered.
     *
     * @param int $userid Restrict to one owner (0 = all).
     * @return int Number of comments imported (not counting rows that were
     *             already present and only had their legacy source removed).
     */
    public static function import_legacy(int $userid = 0): int {
        global $DB;

        $legacy = self::get_unmigrated_legacy($userid);
        if (empty($legacy)) {
            return 0;
        }

        $now = time();
        $imported = 0;
        $existing = [];

        foreach ($legacy as $row) {
            $code = $row['wouldbecode'];
            $ownerid = (int) $row['userid'];

            // Already present — from this run or an earlier one, or because
            // the teacher separately re-saved the same comment. Either way
            // the v2 table already has it, so no insert is needed, but the
            // stale legacy row still gets cleared below.
            //
            // Compared in PHP, not with a coursecode = :coursecode predicate.
            // MariaDB's default collation folds case and ignores trailing
            // spaces, so that predicate matched a 'bib3129' row for a legacy
            // comment filed under 'BIB3129', skipped the insert, and then
            // deleted the only copy with the original spelling below.
            if (!isset($existing[$ownerid])) {
                $existing[$ownerid] = [];
                $ownerrows = $DB->get_records('local_unifiedgrader_clib', ['userid' => $ownerid], '', 'id, coursecode, content');
                foreach ($ownerrows as $ownerrow) {
                    $existing[$ownerid][$ownerrow->coursecode . "\0" . $ownerrow->content] = true;
                }
            }
            $duplicatekey = $code . "\0" . $row['content'];
            $confirmed = isset($existing[$ownerid][$duplicatekey]);

            if (!$confirmed) {
                $newid = $DB->insert_record('local_unifiedgrader_clib', (object) [
                    'userid' => $row['userid'],
                    'coursecode' => $code,
                    'content' => $row['content'],
                    'shared' => 0,
                    'sortorder' => 0,
                    'timecreated' => $row['timecreated'],
                    'timemodified' => $now,
                ]);

                // Confirm the write actually landed before destroying the
                // only other copy of this comment.
                $confirmed = $DB->record_exists('local_unifiedgrader_clib', ['id' => $newid]);
                if ($confirmed) {
                    $imported++;
                    $existing[$ownerid][$duplicatekey] = true;
                }
            }

            if ($confirmed) {
                $DB->delete_records('local_unifiedgrader_comments', ['id' => $row['id']]);
            }
        }

        return $imported;
    }
}
