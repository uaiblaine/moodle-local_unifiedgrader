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
 * Admin tool to curate system-default tags and comments in the comment library.
 *
 * The page is a single controller that dispatches on the `action` query
 * parameter: list (default), edittag, deletetag, editcomment, deletecomment.
 *
 * @package    local_unifiedgrader
 * @copyright  2026 South African Theological Seminary (mathieu@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('local_unifiedgrader_systemdefaults');

$context = context_system::instance();
require_capability('local/unifiedgrader:managesystemdefaults', $context);

$action = optional_param('action', 'list', PARAM_ALPHA);
$id = optional_param('id', 0, PARAM_INT);

$baseurl = new moodle_url('/local/unifiedgrader/manage_system_defaults.php');

$PAGE->set_url($baseurl);
$PAGE->set_title(get_string('manage_system_defaults', 'local_unifiedgrader'));
$PAGE->set_heading(get_string('manage_system_defaults', 'local_unifiedgrader'));

// Delete actions: no form, single-step with sesskey.
if ($action === 'deletetag' && $id > 0) {
    require_sesskey();
    \local_unifiedgrader\comment_library_manager::delete_system_tag($id);
    redirect($baseurl, get_string('clib_tag_deleted', 'local_unifiedgrader'));
}
if ($action === 'deletecomment' && $id > 0) {
    require_sesskey();
    \local_unifiedgrader\comment_library_manager::delete_system_comment($id);
    redirect($baseurl, get_string('clib_comment_deleted', 'local_unifiedgrader'));
}

// Proposal queue actions.
if ($action === 'approveproposal' && $id > 0) {
    require_sesskey();
    \local_unifiedgrader\comment_library_manager::approve_proposal($id, $USER->id);
    redirect($baseurl, get_string('clib_proposal_approved_msg', 'local_unifiedgrader'));
}
if ($action === 'rejectproposal' && $id > 0) {
    require_sesskey();
    $reason = optional_param('reason', '', PARAM_TEXT);
    \local_unifiedgrader\comment_library_manager::reject_proposal($id, $USER->id, $reason);
    redirect($baseurl, get_string('clib_proposal_rejected_msg', 'local_unifiedgrader'));
}

// Edit / create tag.
if ($action === 'edittag' || $action === 'newtag') {
    $form = new \local_unifiedgrader\form\system_tag_form($baseurl->out(false) . '?action=' . $action);
    if ($action === 'edittag' && $id > 0) {
        $existing = $DB->get_record('local_unifiedgrader_cltag', ['id' => $id, 'userid' => 0], '*', MUST_EXIST);
        $form->set_data([
            'id' => (int) $existing->id,
            'name' => $existing->name,
            'sortorder' => (int) $existing->sortorder,
        ]);
    }
    if ($form->is_cancelled()) {
        redirect($baseurl);
    } else if ($data = $form->get_data()) {
        \local_unifiedgrader\comment_library_manager::save_system_tag(
            $data->name,
            (int) ($data->sortorder ?? 0),
            (int) ($data->id ?? 0),
        );
        redirect($baseurl, get_string('clib_tag_saved', 'local_unifiedgrader'));
    }
    echo $OUTPUT->header();
    echo $OUTPUT->heading(
        $action === 'edittag'
            ? get_string('clib_edit_system_tag', 'local_unifiedgrader')
            : get_string('clib_new_system_tag', 'local_unifiedgrader'),
        3,
    );
    $form->display();
    echo $OUTPUT->footer();
    exit;
}

// Edit / create comment.
if ($action === 'editcomment' || $action === 'newcomment') {
    $systemtags = array_filter(
        \local_unifiedgrader\comment_library_manager::get_tags($USER->id),
        fn($t) => !empty($t['issystem']),
    );
    $form = new \local_unifiedgrader\form\system_comment_form(
        $baseurl->out(false) . '?action=' . $action,
        ['systemtags' => array_values($systemtags)],
    );
    if ($action === 'editcomment' && $id > 0) {
        $existing = $DB->get_record('local_unifiedgrader_clib', ['id' => $id, 'userid' => 0], '*', MUST_EXIST);
        $tagids = $DB->get_fieldset_select(
            'local_unifiedgrader_clmap',
            'tagid',
            'commentid = ?',
            [$id],
        );
        $form->set_data([
            'id' => (int) $existing->id,
            'content' => $existing->content,
            'tagids' => array_map('intval', $tagids),
        ]);
    }
    if ($form->is_cancelled()) {
        redirect($baseurl);
    } else if ($data = $form->get_data()) {
        $tagidsin = isset($data->tagids) ? array_map('intval', (array) $data->tagids) : [];
        \local_unifiedgrader\comment_library_manager::save_system_comment(
            $data->content,
            $tagidsin,
            (int) ($data->id ?? 0),
        );
        redirect($baseurl, get_string('clib_comment_saved', 'local_unifiedgrader'));
    }
    echo $OUTPUT->header();
    echo $OUTPUT->heading(
        $action === 'editcomment'
            ? get_string('clib_edit_system_comment', 'local_unifiedgrader')
            : get_string('clib_new_system_comment', 'local_unifiedgrader'),
        3,
    );
    $form->display();
    echo $OUTPUT->footer();
    exit;
}

// Render the default list view.
$alltags = \local_unifiedgrader\comment_library_manager::get_tags($USER->id);
$systemtags = array_values(array_filter($alltags, fn($t) => !empty($t['issystem'])));
$comments = \local_unifiedgrader\comment_library_manager::get_system_comments();

// Build a tag-name lookup so we can show tag names next to each comment.
$tagnames = [];
foreach ($alltags as $t) {
    $tagnames[$t['id']] = $t['name'];
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('manage_system_defaults', 'local_unifiedgrader'), 2);
echo html_writer::tag(
    'p',
    get_string('manage_system_defaults_intro', 'local_unifiedgrader'),
    ['class' => 'text-muted'],
);

// Pending submissions queue.
$pending = \local_unifiedgrader\comment_library_manager::get_pending_proposals();
echo $OUTPUT->heading(get_string('clib_pending_submissions_heading', 'local_unifiedgrader'), 3);
if (empty($pending)) {
    echo html_writer::tag(
        'p',
        get_string('clib_no_pending_submissions', 'local_unifiedgrader'),
        ['class' => 'text-muted'],
    );
} else {
    // Inline JS handler for the reject prompt — keeps the markup simple and
    // avoids dragging in a full Moodle form for a one-liner reason field.
    $rejectprompt = json_encode(get_string('clib_reject_reason_prompt', 'local_unifiedgrader'));
    $approveconfirm = json_encode(get_string('clib_approve_confirm', 'local_unifiedgrader'));
    $proposaltable = new html_table();
    $proposaltable->head = [
        get_string('clib_comment_content', 'local_unifiedgrader'),
        get_string('clib_proposer', 'local_unifiedgrader'),
        get_string('clib_rationale', 'local_unifiedgrader'),
        get_string('clib_tags', 'local_unifiedgrader'),
        get_string('actions'),
    ];
    $proposaltable->attributes['class'] = 'generaltable';
    $proposaltable->data = [];
    foreach ($pending as $p) {
        $tagspills = '';
        foreach ($p['tagids'] as $tid) {
            $tagspills .= html_writer::span(
                format_string($tagnames[$tid] ?? '?'),
                'badge bg-secondary text-dark me-1',
            );
        }

        $approveurl = new moodle_url($baseurl, [
            'action' => 'approveproposal',
            'id' => $p['id'],
            'sesskey' => sesskey(),
        ]);
        // Reject URL is templated client-side — JS appends ?reason= when
        // submitting.
        $rejectbase = new moodle_url($baseurl, [
            'action' => 'rejectproposal',
            'id' => $p['id'],
            'sesskey' => sesskey(),
        ]);

        $approvebtn = html_writer::link(
            $approveurl,
            '<i class="fa fa-check me-1"></i>' . get_string('clib_approve', 'local_unifiedgrader'),
            [
                'class' => 'btn btn-sm btn-success me-1',
                'onclick' => 'return confirm(' . $approveconfirm . ');',
            ],
        );

        // Inline JS for reject: prompt for reason, build URL, navigate.
        $rejectjs = "var r = window.prompt({$rejectprompt}, '');"
            . "if (r === null) { return false; }"
            . "window.location.href = this.href + '&reason=' + encodeURIComponent(r);"
            . "return false;";
        $rejectbtn = html_writer::link(
            $rejectbase,
            '<i class="fa fa-times me-1"></i>' . get_string('clib_reject', 'local_unifiedgrader'),
            [
                'class' => 'btn btn-sm btn-outline-danger',
                'onclick' => $rejectjs,
            ],
        );

        $proposaltable->data[] = [
            format_text($p['content'], FORMAT_PLAIN),
            s($p['proposername']),
            $p['rationale'] !== ''
                ? format_text($p['rationale'], FORMAT_PLAIN)
                : html_writer::tag('span', '—', ['class' => 'text-muted']),
            $tagspills ?: html_writer::tag('span', '—', ['class' => 'text-muted']),
            $approvebtn . $rejectbtn,
        ];
    }
    echo html_writer::table($proposaltable);
}

// Tags section.
echo $OUTPUT->heading(get_string('clib_system_tags_heading', 'local_unifiedgrader'), 3, 'mt-4');

$tagtable = new html_table();
$tagtable->head = [
    get_string('clib_tag_name', 'local_unifiedgrader'),
    get_string('clib_tag_sortorder', 'local_unifiedgrader'),
    get_string('actions'),
];
$tagtable->attributes['class'] = 'generaltable';
$tagtable->data = [];
foreach ($systemtags as $tag) {
    $editurl = new moodle_url($baseurl, ['action' => 'edittag', 'id' => $tag['id']]);
    $deleteurl = new moodle_url($baseurl, [
        'action' => 'deletetag',
        'id' => $tag['id'],
        'sesskey' => sesskey(),
    ]);
    $actions = html_writer::link(
        $editurl,
        $OUTPUT->pix_icon('t/edit', get_string('edit')),
        ['title' => get_string('edit'), 'class' => 'me-2'],
    );
    $actions .= html_writer::link(
        $deleteurl,
        $OUTPUT->pix_icon('t/delete', get_string('delete')),
        [
            'title' => get_string('delete'),
            'onclick' => 'return confirm('
                . json_encode(get_string('clib_confirm_delete_tag', 'local_unifiedgrader'))
                . ');',
        ],
    );
    $tagtable->data[] = [
        format_string($tag['name']),
        (int) $tag['sortorder'],
        $actions,
    ];
}
if (empty($tagtable->data)) {
    echo html_writer::tag(
        'p',
        get_string('clib_no_system_tags_yet', 'local_unifiedgrader'),
        ['class' => 'text-muted'],
    );
} else {
    echo html_writer::table($tagtable);
}
echo $OUTPUT->single_button(
    new moodle_url($baseurl, ['action' => 'newtag']),
    get_string('clib_new_system_tag', 'local_unifiedgrader'),
    'get',
);

// Comments section.
echo $OUTPUT->heading(get_string('clib_system_comments_heading', 'local_unifiedgrader'), 3, 'mt-4');

$commenttable = new html_table();
$commenttable->head = [
    get_string('clib_comment_content', 'local_unifiedgrader'),
    get_string('clib_tags', 'local_unifiedgrader'),
    get_string('actions'),
];
$commenttable->attributes['class'] = 'generaltable';
$commenttable->colclasses = ['', '', 'text-end'];
$commenttable->data = [];
foreach ($comments as $c) {
    $tagspills = '';
    foreach (($c['tagids'] ?? []) as $tid) {
        $tagspills .= html_writer::span(
            format_string($tagnames[$tid] ?? '?'),
            'badge bg-secondary text-dark me-1',
        );
    }

    $editurl = new moodle_url($baseurl, ['action' => 'editcomment', 'id' => $c['id']]);
    $deleteurl = new moodle_url($baseurl, [
        'action' => 'deletecomment',
        'id' => $c['id'],
        'sesskey' => sesskey(),
    ]);
    $actions = html_writer::link(
        $editurl,
        $OUTPUT->pix_icon('t/edit', get_string('edit')),
        ['title' => get_string('edit'), 'class' => 'me-2'],
    );
    $actions .= html_writer::link(
        $deleteurl,
        $OUTPUT->pix_icon('t/delete', get_string('delete')),
        [
            'title' => get_string('delete'),
            'onclick' => 'return confirm('
                . json_encode(get_string('clib_confirm_delete', 'local_unifiedgrader'))
                . ');',
        ],
    );

    $commenttable->data[] = [
        format_text($c['content'], FORMAT_PLAIN),
        $tagspills ?: html_writer::tag('span', '—', ['class' => 'text-muted']),
        $actions,
    ];
}
if (empty($commenttable->data)) {
    echo html_writer::tag(
        'p',
        get_string('clib_no_system_comments_yet', 'local_unifiedgrader'),
        ['class' => 'text-muted'],
    );
} else {
    echo html_writer::table($commenttable);
}
echo $OUTPUT->single_button(
    new moodle_url($baseurl, ['action' => 'newcomment']),
    get_string('clib_new_system_comment', 'local_unifiedgrader'),
    'get',
);

echo $OUTPUT->footer();
