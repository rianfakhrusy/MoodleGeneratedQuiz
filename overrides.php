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
 * This page handles listing of gnrquiz overrides
 *
 * @package    mod_gnrquiz
 * @copyright  2010 Matt Petro
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


require_once(dirname(__FILE__) . '/../../config.php');
require_once($CFG->dirroot.'/mod/gnrquiz/lib.php');
require_once($CFG->dirroot.'/mod/gnrquiz/locallib.php');
require_once($CFG->dirroot.'/mod/gnrquiz/override_form.php');


$cmid = required_param('cmid', PARAM_INT);
$mode = optional_param('mode', '', PARAM_ALPHA); // One of 'user' or 'group', default is 'group'.

list($course, $cm) = get_course_and_cm_from_cmid($cmid, 'gnrquiz');
$gnrquiz = $DB->get_record('gnrquiz', array('id' => $cm->instance), '*', MUST_EXIST);

// Get the course groups.
$groups = groups_get_all_groups($cm->course);
if ($groups === false) {
    $groups = array();
}

// Default mode is "group", unless there are no groups.
if ($mode != "user" and $mode != "group") {
    if (!empty($groups)) {
        $mode = "group";
    } else {
        $mode = "user";
    }
}
$groupmode = ($mode == "group");

$url = new moodle_url('/mod/gnrquiz/overrides.php', array('cmid'=>$cm->id, 'mode'=>$mode));

$PAGE->set_url($url);

require_login($course, false, $cm);

$context = context_module::instance($cm->id);

// Check the user has the required capabilities to list overrides.
require_capability('mod/gnrquiz:manageoverrides', $context);

// Display a list of overrides.
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('overrides', 'gnrquiz'));
$PAGE->set_heading($course->fullname);
echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($gnrquiz->name, true, array('context' => $context)));

// Delete orphaned group overrides.
$sql = 'SELECT o.id
            FROM {gnrquiz_overrides} o LEFT JOIN {groups} g
            ON o.groupid = g.id
            WHERE o.groupid IS NOT NULL
              AND g.id IS NULL
              AND o.gnrquiz = ?';
$params = array($gnrquiz->id);
$orphaned = $DB->get_records_sql($sql, $params);
if (!empty($orphaned)) {
    $DB->delete_records_list('gnrquiz_overrides', 'id', array_keys($orphaned));
}

// Fetch all overrides.
if ($groupmode) {
    $colname = get_string('group');
    $sql = 'SELECT o.*, g.name
                FROM {gnrquiz_overrides} o
                JOIN {groups} g ON o.groupid = g.id
                WHERE o.gnrquiz = :gnrquizid
                ORDER BY g.name';
    $params = array('gnrquizid' => $gnrquiz->id);
} else {
    $colname = get_string('user');
    list($sort, $params) = users_order_by_sql('u');
    $sql = 'SELECT o.*, ' . get_all_user_name_fields(true, 'u') . '
            FROM {gnrquiz_overrides} o
            JOIN {user} u ON o.userid = u.id
            WHERE o.gnrquiz = :gnrquizid
            ORDER BY ' . $sort;
    $params['gnrquizid'] = $gnrquiz->id;
}

$overrides = $DB->get_records_sql($sql, $params);

// Initialise table.
$table = new html_table();
$table->headspan = array(1, 2, 1);
$table->colclasses = array('colname', 'colsetting', 'colvalue', 'colaction');
$table->head = array(
        $colname,
        get_string('overrides', 'gnrquiz'),
        get_string('action'),
);

$userurl = new moodle_url('/user/view.php', array());
$groupurl = new moodle_url('/group/overview.php', array('id' => $cm->course));

$overridedeleteurl = new moodle_url('/mod/gnrquiz/overridedelete.php');
$overrideediturl = new moodle_url('/mod/gnrquiz/overrideedit.php');

$hasinactive = false; // Whether there are any inactive overrides.

foreach ($overrides as $override) {

    $fields = array();
    $values = array();
    $active = true;

    // Check for inactive overrides.
    if (!$groupmode) {
        if (!has_capability('mod/gnrquiz:attempt', $context, $override->userid)) {
            // User not allowed to take the gnrquiz.
            $active = false;
        } else if (!\core_availability\info_module::is_user_visible($cm, $override->userid)) {
            // User cannot access the module.
            $active = false;
        }
    }

    // Format timeopen.
    if (isset($override->timeopen)) {
        $fields[] = get_string('gnrquizopens', 'gnrquiz');
        $values[] = $override->timeopen > 0 ?
                userdate($override->timeopen) : get_string('noopen', 'gnrquiz');
    }

    // Format timeclose.
    if (isset($override->timeclose)) {
        $fields[] = get_string('gnrquizcloses', 'gnrquiz');
        $values[] = $override->timeclose > 0 ?
                userdate($override->timeclose) : get_string('noclose', 'gnrquiz');
    }

    // Format timelimit.
    if (isset($override->timelimit)) {
        $fields[] = get_string('timelimit', 'gnrquiz');
        $values[] = $override->timelimit > 0 ?
                format_time($override->timelimit) : get_string('none', 'gnrquiz');
    }

    // Format number of attempts.
    if (isset($override->attempts)) {
        $fields[] = get_string('attempts', 'gnrquiz');
        $values[] = $override->attempts > 0 ?
                $override->attempts : get_string('unlimited');
    }

    // Format password.
    if (isset($override->password)) {
        $fields[] = get_string('requirepassword', 'gnrquiz');
        $values[] = $override->password !== '' ?
                get_string('enabled', 'gnrquiz') : get_string('none', 'gnrquiz');
    }

    // Icons.
    $iconstr = '';

    if ($active) {
        // Edit.
        $editurlstr = $overrideediturl->out(true, array('id' => $override->id));
        $iconstr = '<a title="' . get_string('edit') . '" href="'. $editurlstr . '">' .
                '<img src="' . $OUTPUT->pix_url('t/edit') . '" class="iconsmall" alt="' .
                get_string('edit') . '" /></a> ';
        // Duplicate.
        $copyurlstr = $overrideediturl->out(true,
                array('id' => $override->id, 'action' => 'duplicate'));
        $iconstr .= '<a title="' . get_string('copy') . '" href="' . $copyurlstr . '">' .
                '<img src="' . $OUTPUT->pix_url('t/copy') . '" class="iconsmall" alt="' .
                get_string('copy') . '" /></a> ';
    }
    // Delete.
    $deleteurlstr = $overridedeleteurl->out(true,
            array('id' => $override->id, 'sesskey' => sesskey()));
    $iconstr .= '<a title="' . get_string('delete') . '" href="' . $deleteurlstr . '">' .
            '<img src="' . $OUTPUT->pix_url('t/delete') . '" class="iconsmall" alt="' .
            get_string('delete') . '" /></a> ';

    if ($groupmode) {
        $usergroupstr = '<a href="' . $groupurl->out(true,
                array('group' => $override->groupid)) . '" >' . $override->name . '</a>';
    } else {
        $usergroupstr = '<a href="' . $userurl->out(true,
                array('id' => $override->userid)) . '" >' . fullname($override) . '</a>';
    }

    $class = '';
    if (!$active) {
        $class = "dimmed_text";
        $usergroupstr .= '*';
        $hasinactive = true;
    }

    $usergroupcell = new html_table_cell();
    $usergroupcell->rowspan = count($fields);
    $usergroupcell->text = $usergroupstr;
    $actioncell = new html_table_cell();
    $actioncell->rowspan = count($fields);
    $actioncell->text = $iconstr;

    for ($i = 0; $i < count($fields); ++$i) {
        $row = new html_table_row();
        $row->attributes['class'] = $class;
        if ($i == 0) {
            $row->cells[] = $usergroupcell;
        }
        $cell1 = new html_table_cell();
        $cell1->text = $fields[$i];
        $row->cells[] = $cell1;
        $cell2 = new html_table_cell();
        $cell2->text = $values[$i];
        $row->cells[] = $cell2;
        if ($i == 0) {
            $row->cells[] = $actioncell;
        }
        $table->data[] = $row;
    }
}

// Output the table and button.
echo html_writer::start_tag('div', array('id' => 'gnrquizoverrides'));
if (count($table->data)) {
    echo html_writer::table($table);
}
if ($hasinactive) {
    echo $OUTPUT->notification(get_string('inactiveoverridehelp', 'gnrquiz'), 'dimmed_text');
}

echo html_writer::start_tag('div', array('class' => 'buttons'));
$options = array();
if ($groupmode) {
    if (empty($groups)) {
        // There are no groups.
        echo $OUTPUT->notification(get_string('groupsnone', 'gnrquiz'), 'error');
        $options['disabled'] = true;
    }
    echo $OUTPUT->single_button($overrideediturl->out(true,
            array('action' => 'addgroup', 'cmid' => $cm->id)),
            get_string('addnewgroupoverride', 'gnrquiz'), 'post', $options);
} else {
    $users = array();
    // See if there are any students in the gnrquiz.
    $users = get_users_by_capability($context, 'mod/gnrquiz:attempt', 'u.id');
    $info = new \core_availability\info_module($cm);
    $users = $info->filter_user_list($users);

    if (empty($users)) {
        // There are no students.
        echo $OUTPUT->notification(get_string('usersnone', 'gnrquiz'), 'error');
        $options['disabled'] = true;
    }
    echo $OUTPUT->single_button($overrideediturl->out(true,
            array('action' => 'adduser', 'cmid' => $cm->id)),
            get_string('addnewuseroverride', 'gnrquiz'), 'get', $options);
}
echo html_writer::end_tag('div');
echo html_writer::end_tag('div');

// Finish the page.
echo $OUTPUT->footer();
