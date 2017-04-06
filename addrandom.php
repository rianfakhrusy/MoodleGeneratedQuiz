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
 * Fallback page of /mod/gnrquiz/edit.php add random question dialog,
 * for users who do not use javascript.
 *
 * @package   mod_gnrquiz
 * @copyright 2008 Olli Savolainen
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/gnrquiz/locallib.php');
require_once($CFG->dirroot . '/mod/gnrquiz/addrandomform.php');
require_once($CFG->dirroot . '/question/editlib.php');
require_once($CFG->dirroot . '/question/category_class.php');

list($thispageurl, $contexts, $cmid, $cm, $gnrquiz, $pagevars) =
        question_edit_setup('editq', '/mod/gnrquiz/addrandom.php', true);

// These params are only passed from page request to request while we stay on
// this page otherwise they would go in question_edit_setup.
$returnurl = optional_param('returnurl', '', PARAM_LOCALURL);
$addonpage = optional_param('addonpage', 0, PARAM_INT);
$category = optional_param('category', 0, PARAM_INT);
$scrollpos = optional_param('scrollpos', 0, PARAM_INT);

// Get the course object and related bits.
if (!$course = $DB->get_record('course', array('id' => $gnrquiz->course))) {
    print_error('invalidcourseid');
}
// You need mod/gnrquiz:manage in addition to question capabilities to access this page.
// You also need the moodle/question:useall capability somewhere.
require_capability('mod/gnrquiz:manage', $contexts->lowest());
if (!$contexts->having_cap('moodle/question:useall')) {
    print_error('nopermissions', '', '', 'use');
}

$PAGE->set_url($thispageurl);

if ($returnurl) {
    $returnurl = new moodle_url($returnurl);
} else {
    $returnurl = new moodle_url('/mod/gnrquiz/edit.php', array('cmid' => $cmid));
}
if ($scrollpos) {
    $returnurl->param('scrollpos', $scrollpos);
}

$defaultcategoryobj = question_make_default_categories($contexts->all());
$defaultcategory = $defaultcategoryobj->id . ',' . $defaultcategoryobj->contextid;

$qcobject = new question_category_object(
    $pagevars['cpage'],
    $thispageurl,
    $contexts->having_one_edit_tab_cap('categories'),
    $defaultcategoryobj->id,
    $defaultcategory,
    null,
    $contexts->having_cap('moodle/question:add'));

$mform = new gnrquiz_add_random_form(new moodle_url('/mod/gnrquiz/addrandom.php'),
                array('contexts' => $contexts, 'cat' => $pagevars['cat']));

if ($mform->is_cancelled()) {
    redirect($returnurl);
}

if ($data = $mform->get_data()) {
    if (!empty($data->existingcategory)) {
        list($categoryid) = explode(',', $data->category);
        $includesubcategories = !empty($data->includesubcategories);
        $returnurl->param('cat', $data->category);

    } else if (!empty($data->newcategory)) {
        list($parentid, $contextid) = explode(',', $data->parent);
        $categoryid = $qcobject->add_category($data->parent, $data->name, '', true);
        $includesubcategories = 0;

        $returnurl->param('cat', $categoryid . ',' . $contextid);
    } else {
        throw new coding_exception(
                'It seems a form was submitted without any button being pressed???');
    }

    gnrquiz_add_random_questions($gnrquiz, $addonpage, $categoryid, $data->numbertoadd, $includesubcategories);
    gnrquiz_delete_previews($gnrquiz);
    gnrquiz_update_sumgrades($gnrquiz);
    redirect($returnurl);
}

$mform->set_data(array(
    'addonpage' => $addonpage,
    'returnurl' => $returnurl,
    'cmid' => $cm->id,
    'category' => $category,
));

// Setup $PAGE.
$streditinggnrquiz = get_string('editinga', 'moodle', get_string('modulename', 'gnrquiz'));
$PAGE->navbar->add($streditinggnrquiz);
$PAGE->set_title($streditinggnrquiz);
$PAGE->set_heading($course->fullname);
echo $OUTPUT->header();

if (!$gnrquizname = $DB->get_field($cm->modname, 'name', array('id' => $cm->instance))) {
            print_error('invalidcoursemodule');
}

echo $OUTPUT->heading(get_string('addrandomquestiontognrquiz', 'gnrquiz', $gnrquizname), 2);
$mform->display();
echo $OUTPUT->footer();

