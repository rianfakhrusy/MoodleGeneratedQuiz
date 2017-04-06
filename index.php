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
 * This script lists all the instances of gnrquiz in a particular course
 *
 * @package    mod_gnrquiz
 * @copyright  1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


require_once("../../config.php");
require_once("locallib.php");

$id = required_param('id', PARAM_INT);
$PAGE->set_url('/mod/gnrquiz/index.php', array('id'=>$id));
if (!$course = $DB->get_record('course', array('id' => $id))) {
    print_error('invalidcourseid');
}
$coursecontext = context_course::instance($id);
require_login($course);
$PAGE->set_pagelayout('incourse');

$params = array(
    'context' => $coursecontext
);
$event = \mod_gnrquiz\event\course_module_instance_list_viewed::create($params);
$event->trigger();

// Print the header.
$strgnrquizzes = get_string("modulenameplural", "gnrquiz");
$streditquestions = '';
$editqcontexts = new question_edit_contexts($coursecontext);
if ($editqcontexts->have_one_edit_tab_cap('questions')) {
    $streditquestions =
            "<form target=\"_parent\" method=\"get\" action=\"$CFG->wwwroot/question/edit.php\">
               <div>
               <input type=\"hidden\" name=\"courseid\" value=\"$course->id\" />
               <input type=\"submit\" value=\"".get_string("editquestions", "gnrquiz")."\" />
               </div>
             </form>";
}
$PAGE->navbar->add($strgnrquizzes);
$PAGE->set_title($strgnrquizzes);
$PAGE->set_button($streditquestions);
$PAGE->set_heading($course->fullname);
echo $OUTPUT->header();
echo $OUTPUT->heading($strgnrquizzes, 2);

// Get all the appropriate data.
if (!$gnrquizzes = get_all_instances_in_course("gnrquiz", $course)) {
    notice(get_string('thereareno', 'moodle', $strgnrquizzes), "../../course/view.php?id=$course->id");
    die;
}

// Check if we need the closing date header.
$showclosingheader = false;
$showfeedback = false;
foreach ($gnrquizzes as $gnrquiz) {
    if ($gnrquiz->timeclose!=0) {
        $showclosingheader=true;
    }
    if (gnrquiz_has_feedback($gnrquiz)) {
        $showfeedback=true;
    }
    if ($showclosingheader && $showfeedback) {
        break;
    }
}

// Configure table for displaying the list of instances.
$headings = array(get_string('name'));
$align = array('left');

if ($showclosingheader) {
    array_push($headings, get_string('gnrquizcloses', 'gnrquiz'));
    array_push($align, 'left');
}

if (course_format_uses_sections($course->format)) {
    array_unshift($headings, get_string('sectionname', 'format_'.$course->format));
} else {
    array_unshift($headings, '');
}
array_unshift($align, 'center');

$showing = '';

if (has_capability('mod/gnrquiz:viewreports', $coursecontext)) {
    array_push($headings, get_string('attempts', 'gnrquiz'));
    array_push($align, 'left');
    $showing = 'stats';

} else if (has_any_capability(array('mod/gnrquiz:reviewmyattempts', 'mod/gnrquiz:attempt'),
        $coursecontext)) {
    array_push($headings, get_string('grade', 'gnrquiz'));
    array_push($align, 'left');
    if ($showfeedback) {
        array_push($headings, get_string('feedback', 'gnrquiz'));
        array_push($align, 'left');
    }
    $showing = 'grades';

    $grades = $DB->get_records_sql_menu('
            SELECT qg.gnrquiz, qg.grade
            FROM {gnrquiz_grades} qg
            JOIN {gnrquiz} q ON q.id = qg.gnrquiz
            WHERE q.course = ? AND qg.userid = ?',
            array($course->id, $USER->id));
}

$table = new html_table();
$table->head = $headings;
$table->align = $align;

// Populate the table with the list of instances.
$currentsection = '';
foreach ($gnrquizzes as $gnrquiz) {
    $cm = get_coursemodule_from_instance('gnrquiz', $gnrquiz->id);
    $context = context_module::instance($cm->id);
    $data = array();

    // Section number if necessary.
    $strsection = '';
    if ($gnrquiz->section != $currentsection) {
        if ($gnrquiz->section) {
            $strsection = $gnrquiz->section;
            $strsection = get_section_name($course, $gnrquiz->section);
        }
        if ($currentsection) {
            $learningtable->data[] = 'hr';
        }
        $currentsection = $gnrquiz->section;
    }
    $data[] = $strsection;

    // Link to the instance.
    $class = '';
    if (!$gnrquiz->visible) {
        $class = ' class="dimmed"';
    }
    $data[] = "<a$class href=\"view.php?id=$gnrquiz->coursemodule\">" .
            format_string($gnrquiz->name, true) . '</a>';

    // Close date.
    if ($gnrquiz->timeclose) {
        $data[] = userdate($gnrquiz->timeclose);
    } else if ($showclosingheader) {
        $data[] = '';
    }

    if ($showing == 'stats') {
        // The $gnrquiz objects returned by get_all_instances_in_course have the necessary $cm
        // fields set to make the following call work.
        $data[] = gnrquiz_attempt_summary_link_to_reports($gnrquiz, $cm, $context);

    } else if ($showing == 'grades') {
        // Grade and feedback.
        $attempts = gnrquiz_get_user_attempts($gnrquiz->id, $USER->id, 'all');
        list($someoptions, $alloptions) = gnrquiz_get_combined_reviewoptions(
                $gnrquiz, $attempts);

        $grade = '';
        $feedback = '';
        if ($gnrquiz->grade && array_key_exists($gnrquiz->id, $grades)) {
            if ($alloptions->marks >= question_display_options::MARK_AND_MAX) {
                $a = new stdClass();
                $a->grade = gnrquiz_format_grade($gnrquiz, $grades[$gnrquiz->id]);
                $a->maxgrade = gnrquiz_format_grade($gnrquiz, $gnrquiz->grade);
                $grade = get_string('outofshort', 'gnrquiz', $a);
            }
            if ($alloptions->overallfeedback) {
                $feedback = gnrquiz_feedback_for_grade($grades[$gnrquiz->id], $gnrquiz, $context);
            }
        }
        $data[] = $grade;
        if ($showfeedback) {
            $data[] = $feedback;
        }
    }

    $table->data[] = $data;
} // End of loop over gnrquiz instances.

// Display the table.
echo html_writer::table($table);

// Finish the page.
echo $OUTPUT->footer();
