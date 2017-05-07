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
 * This page is the entry page into the gnrquiz UI. Displays information about the
 * gnrquiz to students and teachers, and lets students see their previous attempts.
 *
 * @package   mod_gnrquiz
 * @copyright 2017 Rian Fakhrusy  {@link http://moodle.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(dirname(__FILE__) . '/../../config.php');
require_once($CFG->dirroot.'/mod/gnrquiz/classes/structure.php');

$cmid = required_param('id', PARAM_INT);
$cm = get_coursemodule_from_id('gnrquiz', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', array('id' => $cm->course), '*', MUST_EXIST);
 
require_login($course, true, $cm);
$context = context_module::instance($cm->id);
$id = required_param('gnrquizid', PARAM_INT);
$PAGE->set_url('/mod/gnrquiz/statistics.php', array('id' => $cmid, 'gnrquizid' => $id));
$PAGE->set_title('Statistics');
$PAGE->set_heading('Generated Quiz Statistics');
$output = $PAGE->get_renderer('mod_gnrquiz');


if (!$gnrquiz = $DB->get_record('gnrquiz', array('id' => $id))) {
    print_error('invalidgnrquizid', 'gnrquiz');
}

$query = "SELECT qc.id, qc.name FROM {question_categories} qc";
$category = $DB->get_records_sql($query, array());
$keys = array_map(function($o) { return $o->id; }, $category);
$values = array_map(function($o) { return $o->name; }, $category);
$categoryname = array_combine($keys, $values);

echo $OUTPUT->header();

if (isguestuser()) {
    // Guests can't do a gnrquiz, so offer them a choice of logging in.
    echo $output->view_page_guest($course, $gnrquiz, $cm, $context, array('You must be logged in to view this page'));
} else {
	echo $output->view_statistics_table($gnrquiz, $categoryname);
	echo $output->return_button($cm->id);
}

echo $OUTPUT->footer();