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
 * CSV export and import for the comment library.
 *
 * @package    local_unifiedgrader
 * @copyright  2026 South African Theological Seminary (mathieu@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_unifiedgrader;

/**
 * Turns comment library rows into a CSV file and back.
 *
 * Two export shapes:
 *  - Single-owner ("bucket" or "my library"): coursecode,shared,tags,content
 *  - Cross-owner (admin, spans teachers): ownerid,ownername,coursecode,shared,tags,content
 *
 * Import auto-detects the shape from the header row and is deliberately
 * forgiving: content is the only required column, everything else falls
 * back to a caller-supplied default. It is also additive and idempotent in
 * the same way as library_audit::import_legacy() — a row that already
 * exists for its target owner/scope/content is skipped rather than
 * duplicated, so re-importing the same file twice is harmless.
 */
class library_csv {
    /** @var string Separator between tag names within one CSV cell. */
    private const TAG_SEPARATOR = '|';

    /** @var int Longest course code local_unifiedgrader_clib stores (char 255 in install.xml). */
    public const MAX_CODE_LENGTH = 255;

    /** @var int Longest tag name local_unifiedgrader_cltag stores (char 50 in install.xml). */
    private const MAX_TAG_LENGTH = 50;

    /**
     * Export one owner's library (optionally restricted to one course code).
     *
     * @param int $userid The owner.
     * @param string|null $coursecode Restrict to this code; null = every code.
     * @return string CSV content.
     */
    public static function export_for_owner(int $userid, ?string $coursecode = null): string {
        global $DB;

        $records = $DB->get_records(
            'local_unifiedgrader_clib',
            ['userid' => $userid],
            'coursecode ASC, timecreated ASC',
        );
        // The code is matched in PHP rather than with coursecode = :coursecode. MariaDB's
        // default collation folds case and ignores trailing spaces, so that predicate
        // exported a different set of rows for the same bucket there than on PostgreSQL.
        if ($coursecode !== null) {
            $records = array_filter($records, fn($r) => $r->coursecode === $coursecode);
        }

        return self::build_csv($records, false);
    }

    /**
     * Whether a user exporting a bucket is its owner, and so needs no moderation capability.
     *
     * Both ids are compared as ints. $USER->id comes back from the database as a string on
     * every supported driver while the requested owner is cleaned as PARAM_INT, so comparing
     * the two raw values strictly never matched, and every teacher exporting their own bucket
     * was asked for local/unifiedgrader:moderatelibraries.
     *
     * @param int $owner Owner of the bucket being exported.
     * @param int $viewerid The user asking for the export.
     * @return bool
     */
    public static function is_bucket_owner(int $owner, int $viewerid): bool {
        return $owner === $viewerid;
    }

    /**
     * Export across owners, matching the same filters the moderation page uses.
     *
     * @param int $userid Restrict to one owner (0 = every owner).
     * @param string $codefilter Restrict to codes containing this substring.
     * @return string CSV content.
     */
    public static function export_for_filters(int $userid = 0, string $codefilter = ''): string {
        global $DB;

        $where = ['1 = 1'];
        $params = [];
        if ($userid > 0) {
            $where[] = 'c.userid = :userid';
            $params['userid'] = $userid;
        }
        if (trim($codefilter) !== '') {
            $where[] = $DB->sql_like('c.coursecode', ':codefilter', false);
            $params['codefilter'] = '%' . $DB->sql_like_escape(trim($codefilter)) . '%';
        }

        $sql = "SELECT c.*, u.firstname, u.lastname
                  FROM {local_unifiedgrader_clib} c
             LEFT JOIN {user} u ON u.id = c.userid
                 WHERE " . implode(' AND ', $where) . "
              ORDER BY c.userid ASC, c.coursecode ASC, c.timecreated ASC";

        $records = $DB->get_records_sql($sql, $params);

        return self::build_csv($records, true);
    }

    /**
     * Render a set of clib records (optionally joined to owner name fields)
     * as a CSV string.
     *
     * @param array $records Rows from local_unifiedgrader_clib, keyed by id.
     * @param bool $includeowner Whether to add ownerid/ownername columns.
     * @return string
     */
    private static function build_csv(array $records, bool $includeowner): string {
        $tagnames = self::tag_names_for(array_keys($records));

        $fh = fopen('php://temp', 'r+');
        $header = $includeowner
            ? ['ownerid', 'ownername', 'coursecode', 'shared', 'tags', 'content']
            : ['coursecode', 'shared', 'tags', 'content'];
        // The escape character is passed explicitly, here and in import(): PHP 8.4
        // deprecates relying on its default, and core's csvlib passes the same value.
        fputcsv($fh, $header, escape: '\\');

        foreach ($records as $r) {
            $tags = self::escape_cell(implode(self::TAG_SEPARATOR, $tagnames[(int) $r->id] ?? []));
            $coursecode = self::escape_cell((string) $r->coursecode);
            $content = self::escape_cell((string) $r->content);
            if ($includeowner) {
                $ownername = self::escape_cell(isset($r->firstname) ? trim($r->firstname . ' ' . $r->lastname) : '');
                fputcsv($fh, [$r->userid, $ownername, $coursecode, $r->shared, $tags, $content], escape: '\\');
            } else {
                fputcsv($fh, [$coursecode, $r->shared, $tags, $content], escape: '\\');
            }
        }

        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);

        return $csv;
    }

    /**
     * Neutralise a cell that a spreadsheet would read as a formula.
     *
     * A cell starting with =, +, - or @ is evaluated as a formula when the file is opened in
     * a spreadsheet, and a leading tab or carriage return can be read as one too. Comment
     * text, tag names and owner names are written by teachers, so an export must not hand
     * them to whoever opens it as live formulas. Such a cell gets a leading single quote,
     * which spreadsheets take as "this is text" and do not display.
     *
     * A cell that already starts with a quote gets one as well. That is what keeps the rule
     * reversible: an exported cell starts with a quote only when this method put it there,
     * so unescape_cell() can always remove exactly one.
     *
     * @param string $value Cell text.
     * @return string
     */
    private static function escape_cell(string $value): string {
        if ($value !== '' && strpos("=+-@\t\r'", $value[0]) !== false) {
            return "'" . $value;
        }
        return $value;
    }

    /**
     * Undo escape_cell() on a cell read back from a CSV file.
     *
     * Only a quote escape_cell() could have added is removed: one followed by a character it
     * quotes. A cell that merely starts with a quote, as "'Tis well argued" does in a file written
     * by hand or exported before cells were quoted, keeps it, so re-importing such a file still
     * finds the comment already present instead of adding a second, altered copy.
     *
     * @param string $value Cell text.
     * @return string
     */
    private static function unescape_cell(string $value): string {
        if (strlen($value) > 1 && $value[0] === "'" && strpos("=+-@\t\r'", $value[1]) !== false) {
            return substr($value, 1);
        }
        return $value;
    }

    /**
     * Import comments from CSV text.
     *
     * @param string $csvcontent Raw file content.
     * @param int $defaultuserid Owner to use for rows with no usable ownerid column.
     * @param string|null $defaultcoursecode Code to use for rows with no coursecode column
     *                                       (or when $forcecoursecode is true, for every row).
     * @param bool $allowownercolumn When true (admin only), an "ownerid" column in the
     *                               file may override $defaultuserid per row.
     * @param bool $forcecoursecode When true, every row is filed under $defaultcoursecode
     *                              regardless of any coursecode column present — used for
     *                              "import into this bucket".
     * @return array{imported:int, skipped:int, errors:string[]}
     */
    public static function import(
        string $csvcontent,
        int $defaultuserid,
        ?string $defaultcoursecode = null,
        bool $allowownercolumn = false,
        bool $forcecoursecode = false,
    ): array {
        global $DB;

        // Excel's "CSV UTF-8" starts the file with a byte order mark. Left in place it became
        // part of the first header name, so that column (coursecode or ownerid, in the shapes
        // this class exports) was never found. Core's csv_import_reader strips it the same way.
        $csvcontent = \core_text::trim_utf8_bom($csvcontent);

        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $csvcontent);
        rewind($fh);

        $header = fgetcsv($fh, escape: '\\');
        if ($header === false) {
            fclose($fh);
            return ['imported' => 0, 'skipped' => 0, 'errors' => [get_string('clibcsv_empty_file', 'local_unifiedgrader')]];
        }

        $header = array_map(fn($h) => strtolower(trim((string) $h)), $header);
        $colindex = array_flip($header);

        if (!isset($colindex['content'])) {
            fclose($fh);
            return [
                'imported' => 0,
                'skipped' => 0,
                'errors' => [get_string('clibcsv_missing_content_column', 'local_unifiedgrader')],
            ];
        }

        $imported = 0;
        $skipped = 0;
        $errors = [];
        $tagcache = [];
        $existing = [];
        $now = time();
        $rownum = 1;

        while (($row = fgetcsv($fh, escape: '\\')) !== false) {
            $rownum++;

            if (count($row) === 1 && trim((string) $row[0]) === '') {
                continue;
            }

            // Every cell must be valid UTF-8 before any of it reaches a query. PostgreSQL rejects
            // an invalid byte sequence even as a SELECT parameter, and the exception used to abort
            // the import part way through, keeping the rows before it and returning no summary.
            $row = self::row_to_utf8($row);
            if ($row === null) {
                $errors[] = get_string('clibcsv_row_bad_encoding', 'local_unifiedgrader', $rownum);
                continue;
            }

            $content = trim(self::unescape_cell((string) ($row[$colindex['content']] ?? '')));
            if ($content === '') {
                $skipped++;
                continue;
            }

            $userid = $defaultuserid;
            if ($allowownercolumn && isset($colindex['ownerid'])) {
                $ownerraw = trim((string) ($row[$colindex['ownerid']] ?? ''));
                if ($ownerraw !== '') {
                    $candidate = (int) $ownerraw;
                    if ($candidate > 0 && $DB->record_exists('user', ['id' => $candidate, 'deleted' => 0])) {
                        $userid = $candidate;
                    } else {
                        $errors[] = get_string('clibcsv_row_bad_owner', 'local_unifiedgrader', $rownum);
                        continue;
                    }
                }
            }
            if ($userid <= 0) {
                $errors[] = get_string('clibcsv_row_no_owner', 'local_unifiedgrader', $rownum);
                continue;
            }

            $coursecode = (string) ($defaultcoursecode ?? '');
            if (!$forcecoursecode && isset($colindex['coursecode'])) {
                $coursecode = trim(self::unescape_cell((string) ($row[$colindex['coursecode']] ?? '')));
            }

            $tagnames = [];
            if (isset($colindex['tags'])) {
                $tagnames = array_filter(array_map(
                    'trim',
                    explode(self::TAG_SEPARATOR, self::unescape_cell((string) ($row[$colindex['tags']] ?? ''))),
                ), fn($t) => $t !== '');
            }

            // Lengths are checked before anything is written. The comment row is inserted before
            // its tags, so a tag too long for its column used to leave the comment behind, untagged,
            // when the tag insert failed and took the rest of the import down with it.
            if (\core_text::strlen($coursecode) > self::MAX_CODE_LENGTH) {
                $errors[] = get_string('clibcsv_row_coursecode_too_long', 'local_unifiedgrader', $rownum);
                continue;
            }
            foreach ($tagnames as $tagname) {
                if (\core_text::strlen($tagname) > self::MAX_TAG_LENGTH) {
                    $errors[] = get_string('clibcsv_row_tag_too_long', 'local_unifiedgrader', $rownum);
                    continue 2;
                }
            }

            $shared = 0;
            if (isset($colindex['shared'])) {
                $rawshared = strtolower(trim((string) ($row[$colindex['shared']] ?? '')));
                $shared = in_array($rawshared, ['1', 'true', 'yes'], true) ? 1 : 0;
            }

            // Skip an exact duplicate for this owner/scope — importing must
            // not multiply a library that may already contain the row, and
            // makes re-running an import (e.g. after fixing earlier errors)
            // harmless rather than adding a second copy of everything that
            // already succeeded.
            //
            // Compared in PHP, not with a coursecode = :coursecode AND content
            // = :content predicate: MariaDB's default collation folds case and
            // ignores trailing spaces, so that predicate skipped a row there
            // that PostgreSQL imported, for the same file.
            if (!isset($existing[$userid])) {
                $existing[$userid] = [];
                $ownerrows = $DB->get_records('local_unifiedgrader_clib', ['userid' => $userid], '', 'id, coursecode, content');
                foreach ($ownerrows as $ownerrow) {
                    $existing[$userid][$ownerrow->coursecode . "\0" . $ownerrow->content] = true;
                }
            }
            $duplicatekey = $coursecode . "\0" . $content;
            if (isset($existing[$userid][$duplicatekey])) {
                $skipped++;
                continue;
            }

            $commentid = $DB->insert_record('local_unifiedgrader_clib', (object) [
                'userid' => $userid,
                'coursecode' => $coursecode,
                'content' => $content,
                'shared' => $shared,
                'sortorder' => 0,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
            $existing[$userid][$duplicatekey] = true;

            foreach ($tagnames as $tagname) {
                $cachekey = $userid . ':' . strtolower($tagname);
                if (!isset($tagcache[$cachekey])) {
                    $tagcache[$cachekey] = self::find_or_create_tag($userid, $tagname);
                }
                $DB->insert_record('local_unifiedgrader_clmap', (object) [
                    'commentid' => $commentid,
                    'tagid' => $tagcache[$cachekey],
                ]);
            }

            $imported++;
        }

        fclose($fh);

        return ['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * The notice an import page shows for a result of import().
     *
     * It names the rows that were rejected as well as the counts. A row rejected for its encoding,
     * a course code or tag that is too long, or an owner that cannot be resolved is neither imported
     * nor skipped, so a notice built from the two counts alone lost it without a word.
     *
     * @param array $result Output of import().
     * @return string
     */
    public static function import_result_message(array $result): string {
        $message = get_string('clibcsv_import_result', 'local_unifiedgrader', (object) [
            'imported' => $result['imported'],
            'skipped' => $result['skipped'],
        ]);
        if (!empty($result['errors'])) {
            $message .= ' ' . get_string(
                'clibcsv_import_errors',
                'local_unifiedgrader',
                implode('; ', array_slice($result['errors'], 0, 5)),
            );
        }
        return $message;
    }

    /**
     * Whether a moderation-page import may take each row's owner from an "ownerid" column.
     *
     * Only when no owner filter is set. Once an admin has filtered the page to one teacher,
     * the import goes into that teacher's library whatever the file says, as the page's help
     * text promises; an ownerid column used to override the filter row by row.
     *
     * @param int $filteruser Owner filter on the moderation page (0 = none).
     * @return bool
     */
    public static function allow_owner_column_for_filter(int $filteruser): bool {
        return $filteruser === 0;
    }

    /**
     * Make every cell of a CSV row valid UTF-8, or report that one cannot be.
     *
     * A cell that is not valid UTF-8 is read as Windows-1252, which is what Excel on Windows
     * writes when a file is saved as plain "CSV", by far the usual source of such a file. A
     * cell that is still not valid UTF-8 after that is left for the caller to report.
     *
     * @param array $row One row as fgetcsv() returns it.
     * @return array|null The row with every cell valid UTF-8, or null when one cannot be converted.
     */
    private static function row_to_utf8(array $row): ?array {
        foreach ($row as $index => $cell) {
            if (!is_string($cell) || mb_check_encoding($cell, 'UTF-8')) {
                continue;
            }
            $converted = \core_text::convert($cell, 'windows-1252', 'utf-8');
            if (!is_string($converted) || !mb_check_encoding($converted, 'UTF-8')) {
                return null;
            }
            $row[$index] = $converted;
        }
        return $row;
    }

    /**
     * Find an existing tag visible to this owner by name (their own tag
     * first, falling back to a system tag), or create a new personal tag.
     *
     * @param int $userid
     * @param string $name
     * @return int Tag id.
     */
    private static function find_or_create_tag(int $userid, string $name): int {
        global $DB;

        $matches = $DB->get_records_sql(
            "SELECT id, userid
               FROM {local_unifiedgrader_cltag}
              WHERE (userid = :userid OR userid = 0) AND " . $DB->sql_equal('name', ':name', false) . "
           ORDER BY userid DESC",
            ['userid' => $userid, 'name' => $name],
        );
        if (!empty($matches)) {
            return (int) reset($matches)->id;
        }

        return $DB->insert_record('local_unifiedgrader_cltag', (object) [
            'userid' => $userid,
            'name' => $name,
            'sortorder' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * Batch-fetch tag names for a set of comment ids.
     *
     * @param int[] $commentids
     * @return array<int,string[]> commentid => list of tag names, in display order.
     */
    private static function tag_names_for(array $commentids): array {
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
}
