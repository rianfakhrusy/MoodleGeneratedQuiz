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
 * Page to edit gnrquizzes
 *
 * This page generally has two columns:
 * The right column lists all available questions in a chosen category and
 * allows them to be edited or more to be added. This column is only there if
 * the gnrquiz does not already have student attempts
 * The left column lists all questions that have been added to the current gnrquiz.
 * The lecturer can add questions from the right hand list to the gnrquiz or remove them
 *
 * The script also processes a number of actions:
 * Actions affecting a gnrquiz:
 * up and down  Changes the order of questions and page breaks
 * addquestion  Adds a single question to the gnrquiz
 * add          Adds several selected questions to the gnrquiz
 * addrandom    Adds a certain number of random questions to the gnrquiz
 * repaginate   Re-paginates the gnrquiz
 * delete       Removes a question from the gnrquiz
 * savechanges  Saves the order and grades for questions in the gnrquiz
 *
 * @package    mod_gnrquiz
 * @copyright  1999 onwards Martin Dougiamas and others {@link http://moodle.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/gnrquiz/locallib.php');
require_once($CFG->dirroot . '/mod/gnrquiz/addrandomform.php');
require_once($CFG->dirroot . '/question/editlib.php');
require_once($CFG->dirroot . '/question/category_class.php');

// These params are only passed from page request to request while we stay on
// this page otherwise they would go in question_edit_setup.
$scrollpos = optional_param('scrollpos', '', PARAM_INT);

list($thispageurl, $contexts, $cmid, $cm, $gnrquiz, $pagevars) =
        question_edit_setup('editq', '/mod/gnrquiz/edit.php', true);

$defaultcategoryobj = question_make_default_categories($contexts->all());
$defaultcategory = $defaultcategoryobj->id . ',' . $defaultcategoryobj->contextid;

$gnrquizhasattempts = gnrquiz_has_attempts($gnrquiz->id);

$PAGE->set_url($thispageurl);

// Get the course object and related bits.
$course = $DB->get_record('course', array('id' => $gnrquiz->course), '*', MUST_EXIST);
$gnrquizobj = new gnrquiz($gnrquiz, $cm, $course);
$structure = $gnrquizobj->get_structure();

// You need mod/gnrquiz:manage in addition to question capabilities to access this page.
require_capability('mod/gnrquiz:manage', $contexts->lowest());

// Log this visit.
$params = array(
    'courseid' => $course->id,
    'context' => $contexts->lowest(),
    'other' => array(
        'gnrquizid' => $gnrquiz->id
    )
);
$event = \mod_gnrquiz\event\edit_page_viewed::create($params);
$event->trigger();

// Process commands ============================================================.

// Get the list of question ids had their check-boxes ticked.
$selectedslots = array();
$params = (array) data_submitted();
foreach ($params as $key => $value) {
    if (preg_match('!^s([0-9]+)$!', $key, $matches)) {
        $selectedslots[] = $matches[1];
    }
}

$afteractionurl = new moodle_url($thispageurl);
if ($scrollpos) {
    $afteractionurl->param('scrollpos', $scrollpos);
}

if (optional_param('repaginate', false, PARAM_BOOL) && confirm_sesskey()) {
    // Re-paginate the gnrquiz.
    $structure->check_can_be_edited();
    $questionsperpage = optional_param('questionsperpage', $gnrquiz->questionsperpage, PARAM_INT);
    gnrquiz_repaginate_questions($gnrquiz->id, $questionsperpage );
    gnrquiz_delete_previews($gnrquiz);
    redirect($afteractionurl);
}

if (($addquestion = optional_param('addquestion', 0, PARAM_INT)) && confirm_sesskey()) {
    // Add a single question to the current gnrquiz.
    $structure->check_can_be_edited();
    gnrquiz_require_question_use($addquestion);
    $addonpage = optional_param('addonpage', 0, PARAM_INT);
    gnrquiz_add_gnrquiz_question($addquestion, $gnrquiz, $addonpage);
    gnrquiz_delete_previews($gnrquiz);
    gnrquiz_update_sumgrades($gnrquiz);
    $thispageurl->param('lastchanged', $addquestion);
    redirect($afteractionurl);
}

if (optional_param('add', false, PARAM_BOOL) && confirm_sesskey()) {
    $structure->check_can_be_edited();
    $addonpage = optional_param('addonpage', 0, PARAM_INT);
    // Add selected questions to the current gnrquiz.
    $rawdata = (array) data_submitted();
    foreach ($rawdata as $key => $value) { // Parse input for question ids.
        if (preg_match('!^q([0-9]+)$!', $key, $matches)) {
            $key = $matches[1];
            gnrquiz_require_question_use($key);
            gnrquiz_add_gnrquiz_question($key, $gnrquiz, $addonpage);
        }
    }
    gnrquiz_delete_previews($gnrquiz);
    gnrquiz_update_sumgrades($gnrquiz);
    redirect($afteractionurl);
}

if ($addsectionatpage = optional_param('addsectionatpage', false, PARAM_INT)) {
    // Add a section to the gnrquiz.
    $structure->check_can_be_edited();
    $structure->add_section_heading($addsectionatpage);
    gnrquiz_delete_previews($gnrquiz);
    redirect($afteractionurl);
}

if ((optional_param('addrandom', false, PARAM_BOOL)) && confirm_sesskey()) {
    // Add random questions to the gnrquiz.
    $structure->check_can_be_edited();
    $recurse = optional_param('recurse', 0, PARAM_BOOL);
    $addonpage = optional_param('addonpage', 0, PARAM_INT);
    $categoryid = required_param('categoryid', PARAM_INT);
    $randomcount = required_param('randomcount', PARAM_INT);
    gnrquiz_add_random_questions($gnrquiz, $addonpage, $categoryid, $randomcount, $recurse);

    gnrquiz_delete_previews($gnrquiz);
    gnrquiz_update_sumgrades($gnrquiz);
    redirect($afteractionurl);
}

if (optional_param('savechanges', false, PARAM_BOOL) && confirm_sesskey()) {

    // If rescaling is required save the new maximum.
    $maxgrade = unformat_float(optional_param('maxgrade', -1, PARAM_RAW));
    if ($maxgrade >= 0) {
        gnrquiz_set_grade($maxgrade, $gnrquiz);
        gnrquiz_update_all_final_grades($gnrquiz);
        gnrquiz_update_grades($gnrquiz, 0, true);
    }

    redirect($afteractionurl);
}

// Get the question bank view.
$questionbank = new mod_gnrquiz\question\bank\custom_view($contexts, $thispageurl, $course, $cm, $gnrquiz);
$questionbank->set_gnrquiz_has_attempts($gnrquizhasattempts);
$questionbank->process_actions($thispageurl, $cm);

// End of process commands =====================================================.

$PAGE->set_pagelayout('incourse');
$PAGE->set_pagetype('mod-gnrquiz-edit');

$output = $PAGE->get_renderer('mod_gnrquiz', 'edit');

$PAGE->set_title(get_string('editinggnrquizx', 'gnrquiz', format_string($gnrquiz->name)));
$PAGE->set_heading($course->fullname);
$node = $PAGE->settingsnav->find('mod_gnrquiz_edit', navigation_node::TYPE_SETTING);
if ($node) {
    $node->make_active();
}
echo $OUTPUT->header();

// Initialise the JavaScript.
$gnrquizeditconfig = new stdClass();
$gnrquizeditconfig->url = $thispageurl->out(true, array('qbanktool' => '0'));
$gnrquizeditconfig->dialoglisteners = array();
$numberoflisteners = $DB->get_field_sql("
    SELECT COALESCE(MAX(page), 1)
      FROM {gnrquiz_slots}
     WHERE gnrquizid = ?", array($gnrquiz->id));

for ($pageiter = 1; $pageiter <= $numberoflisteners; $pageiter++) {
    $gnrquizeditconfig->dialoglisteners[] = 'addrandomdialoglaunch_' . $pageiter;
}

$PAGE->requires->data_for_js('gnrquiz_edit_config', $gnrquizeditconfig);
$PAGE->requires->js('/question/qengine.js');

// Questions wrapper start.
echo html_writer::start_tag('div', array('class' => 'mod-gnrquiz-edit-content'));

echo $output->edit_page($gnrquizobj, $structure, $contexts, $thispageurl, $pagevars);

// Questions wrapper end.
echo html_writer::end_tag('div');

echo $OUTPUT->footer();
