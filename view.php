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
 * @copyright 1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


require_once(dirname(__FILE__) . '/../../config.php');
require_once($CFG->libdir.'/gradelib.php');
require_once($CFG->dirroot.'/mod/gnrquiz/locallib.php');
require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->dirroot . '/course/format/lib.php');
require_once($CFG->dirroot.'/mod/gnrquiz/classes/structure.php');
require_once($CFG->dirroot.'/mod/gnrquiz/classes/population.php');
require_once($CFG->dirroot.'/mod/gnrquiz/classes/chromosome.php');

$id = optional_param('id', 0, PARAM_INT); // Course Module ID, or ...
$q = optional_param('q',  0, PARAM_INT);  // Quiz ID.

if ($id) {
    if (!$cm = get_coursemodule_from_id('gnrquiz', $id)) {
        print_error('invalidcoursemodule');
    }
    if (!$course = $DB->get_record('course', array('id' => $cm->course))) {
        print_error('coursemisconf');
    }
} else {
    if (!$gnrquiz = $DB->get_record('gnrquiz', array('id' => $q))) {
        print_error('invalidgnrquizid', 'gnrquiz');
    }
    if (!$course = $DB->get_record('course', array('id' => $gnrquiz->course))) {
        print_error('invalidcourseid');
    }
    if (!$cm = get_coursemodule_from_instance("gnrquiz", $gnrquiz->id, $course->id)) {
        print_error('invalidcoursemodule');
    }
}

// Check login and get context.
require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/gnrquiz:view', $context);

// Cache some other capabilities we use several times.
$canattempt = has_capability('mod/gnrquiz:attempt', $context);
$canreviewmine = has_capability('mod/gnrquiz:reviewmyattempts', $context);
$canpreview = has_capability('mod/gnrquiz:preview', $context);

// Create an object to manage all the other (non-roles) access rules.
$timenow = time();
$gnrquizobj = gnrquiz::create($cm->instance, $USER->id);
$accessmanager = new gnrquiz_access_manager($gnrquizobj, $timenow,
        has_capability('mod/gnrquiz:ignoretimelimits', $context, null, false));

$gnrquiz = $gnrquizobj->get_gnrquiz();
/*
structure::$constraints = $gnrquizobj->get_gnrquiz(); //get constraints
//get all questions

$query = "(SELECT q.id, q.defaultmark, q.qtype, qtf.difficulty, qc.name, qtf.distinguishingdegree, qtf.time 
FROM {question} q, {question_categories} qc, {question_truefalsegnrquiz} qtf 
WHERE q.category = qc.id AND q.id = qtf.question 
ORDER BY q.id ASC) 
UNION 
(SELECT q.id, q.defaultmark, q.qtype, qmc.difficulty, qc.name, qmc.distinguishingdegree, qmc.time 
FROM {question} q, {question_categories} qc, {qtype_choicegnrquiz_options} qmc
WHERE q.category = qc.id AND q.id = qmc.questionid 
ORDER BY q.id ASC)
UNION 
(SELECT q.id, q.defaultmark, q.qtype, qm.difficulty, qc.name, qm.distinguishingdegree, qm.time 
FROM {question} q, {question_categories} qc, {qtype_matchgnrquiz_options} qm
WHERE q.category = qc.id AND q.id = qm.questionid 
ORDER BY q.id ASC)
UNION 
(SELECT q.id, q.defaultmark, q.qtype, qe.difficulty, qc.name, qe.distinguishingdegree, qe.time 
FROM {question} q, {question_categories} qc, {qtype_essaygnrquiz_options} qe
WHERE q.category = qc.id AND q.id = qe.questionid 
ORDER BY q.id ASC)
UNION 
(SELECT q.id, q.defaultmark, q.qtype, qs.difficulty, qc.name, qs.distinguishingdegree, qs.time 
FROM {question} q, {question_categories} qc, {qtype_shortgnrquiz_options} qs
WHERE q.category = qc.id AND q.id = qs.questionid 
ORDER BY q.id ASC)";

structure::$allquestions = $DB->get_records_sql($query, array());
#var_dump(structure::$allquestions);
#var_dump(structure::$constraints);
*/

// Trigger course_module_viewed event and completion.
gnrquiz_view($gnrquiz, $course, $cm, $context);

// Initialize $PAGE, compute blocks.
$PAGE->set_url('/mod/gnrquiz/view.php', array('id' => $cm->id));

// Create view object which collects all the information the renderer will need.
$viewobj = new mod_gnrquiz_view_object();
$viewobj->accessmanager = $accessmanager;
$viewobj->canreviewmine = $canreviewmine || $canpreview;

// Get this user's attempts.
$attempts = gnrquiz_get_user_attempts($gnrquiz->id, $USER->id, 'finished', true);
$lastfinishedattempt = end($attempts);
$unfinished = false;
$unfinishedattemptid = null;
if ($unfinishedattempt = gnrquiz_get_user_attempt_unfinished($gnrquiz->id, $USER->id)) {
    $attempts[] = $unfinishedattempt;

    // If the attempt is now overdue, deal with that - and pass isonline = false.
    // We want the student notified in this case.
    $gnrquizobj->create_attempt_object($unfinishedattempt)->handle_if_time_expired(time(), false);

    $unfinished = $unfinishedattempt->state == gnrquiz_attempt::IN_PROGRESS ||
            $unfinishedattempt->state == gnrquiz_attempt::OVERDUE;
    if (!$unfinished) {
        $lastfinishedattempt = $unfinishedattempt;
    }
    $unfinishedattemptid = $unfinishedattempt->id;
    $unfinishedattempt = null; // To make it clear we do not use this again.
}
$numattempts = count($attempts);

$viewobj->attempts = $attempts;
$viewobj->attemptobjs = array();
foreach ($attempts as $attempt) {
    $viewobj->attemptobjs[] = new gnrquiz_attempt($attempt, $gnrquiz, $cm, $course, false);
}

// Work out the final grade, checking whether it was overridden in the gradebook.
if (!$canpreview) {
    $mygrade = gnrquiz_get_best_grade($gnrquiz, $USER->id);
} else if ($lastfinishedattempt) {
    // Users who can preview the gnrquiz don't get a proper grade, so work out a
    // plausible value to display instead, so the page looks right.
    $mygrade = gnrquiz_rescale_grade($lastfinishedattempt->sumgrades, $gnrquiz, false);
} else {
    $mygrade = null;
}

$mygradeoverridden = false;
$gradebookfeedback = '';

$grading_info = grade_get_grades($course->id, 'mod', 'gnrquiz', $gnrquiz->id, $USER->id);
if (!empty($grading_info->items)) {
    $item = $grading_info->items[0];
    if (isset($item->grades[$USER->id])) {
        $grade = $item->grades[$USER->id];

        if ($grade->overridden) {
            $mygrade = $grade->grade + 0; // Convert to number.
            $mygradeoverridden = true;
        }
        if (!empty($grade->str_feedback)) {
            $gradebookfeedback = $grade->str_feedback;
        }
    }
}

$title = $course->shortname . ': ' . format_string($gnrquiz->name);
$PAGE->set_title($title);
$PAGE->set_heading($course->fullname);
$output = $PAGE->get_renderer('mod_gnrquiz');

// Print table with existing attempts.
if ($attempts) {
    // Work out which columns we need, taking account what data is available in each attempt.
    list($someoptions, $alloptions) = gnrquiz_get_combined_reviewoptions($gnrquiz, $attempts);

    $viewobj->attemptcolumn  = $gnrquiz->attempts != 1;

    $viewobj->gradecolumn    = $someoptions->marks >= question_display_options::MARK_AND_MAX &&
            gnrquiz_has_grades($gnrquiz);
    $viewobj->markcolumn     = $viewobj->gradecolumn && ($gnrquiz->grade != $gnrquiz->sumgrades);
    $viewobj->overallstats   = $lastfinishedattempt && $alloptions->marks >= question_display_options::MARK_AND_MAX;

    $viewobj->feedbackcolumn = gnrquiz_has_feedback($gnrquiz) && $alloptions->overallfeedback;
}

$viewobj->timenow = $timenow;
$viewobj->numattempts = $numattempts;
$viewobj->mygrade = $mygrade;
$viewobj->moreattempts = $unfinished ||
        !$accessmanager->is_finished($numattempts, $lastfinishedattempt);
$viewobj->mygradeoverridden = $mygradeoverridden;
$viewobj->gradebookfeedback = $gradebookfeedback;
$viewobj->lastfinishedattempt = $lastfinishedattempt;
$viewobj->canedit = has_capability('mod/gnrquiz:manage', $context);
$viewobj->editurl = new moodle_url('/mod/gnrquiz/edit.php', array('cmid' => $cm->id));
$viewobj->backtocourseurl = new moodle_url('/course/view.php', array('id' => $course->id));
$viewobj->startattempturl = $gnrquizobj->start_attempt_url();

if ($accessmanager->is_preflight_check_required($unfinishedattemptid)) {
    $viewobj->preflightcheckform = $accessmanager->get_preflight_check_form(
            $viewobj->startattempturl, $unfinishedattemptid);
}
$viewobj->popuprequired = $accessmanager->attempt_must_be_in_popup();
$viewobj->popupoptions = $accessmanager->get_popup_options();

// Display information about this gnrquiz.
$viewobj->infomessages = $viewobj->accessmanager->describe_rules();
if ($gnrquiz->attempts != 1) {
    $viewobj->infomessages[] = get_string('gradingmethod', 'gnrquiz',
            gnrquiz_get_grading_option_name($gnrquiz->grademethod));
}

// Determine wheter a start attempt button should be displayed.
$viewobj->gnrquizhasquestions = $gnrquizobj->has_questions();
$viewobj->preventmessages = array();

#var_dump($gnrquiz);
/*
if (!$viewobj->gnrquizhasquestions) {
    $viewobj->buttontext = '';
    
    #genetic algorithm process
    $p = new population();
    for ($x=1; $x<=512; $x++){
        $best = reset($p->population);
        
        #printf("Generation %d: %s<br>", $x, $best->fitness);

        $p->evolve();
    }
    #var_dump($best->gene);

    foreach ($best->gene as &$value) {
        gnrquiz_add_gnrquiz_question($value,$gnrquiz);
    }
    #gnrquiz_add_random_questions($gnrquiz, 0, 4, 1, false);
    #gnrquiz_add_gnrquiz_question(10,$gnrquiz);
    gnrquiz_delete_previews($gnrquiz);
    gnrquiz_update_sumgrades($gnrquiz);

    #temporary variables for storing new quiz attributes
    $tempScore = 0;
    $tempTypes = [];
    $tempDiff = 0;
    $tempChapters = [];
    $tempDist = 0;
    $tempTime = 0;

    #compute the value of all new quiz attributes
    foreach($best->gene as $key => $value)
    {
        $tempScore += structure::$allquestions[$value]->defaultmark; #sum of new quiz score value
        $tempDiff += structure::$allquestions[$value]->difficulty;
        $tempDist += structure::$allquestions[$value]->distinguishingdegree;
        $tempTime += structure::$allquestions[$value]->time; #sum of new quiz time value
        
        #count the value of all question types in a quiz
        $s = structure::$allquestions[$value]->qtype;
        if (array_key_exists($s, $tempTypes)){
            $tempTypes[$s] += 1;
        } else {
            $tempTypes[$s] = 1;
        }

        #count the value of all chapter covered in a quiz
        $ss = structure::$allquestions[$value]->name;
        if (array_key_exists($ss, $tempChapters)){
            $tempChapters[$ss] += 1;
        } else {
            $tempChapters[$ss] = 1;
        }
    }
    $tempDiff /= count($best->gene); #average quiz difficulty value
    $tempDist /= count($best->gene); #average quiz distinguishing degree value


    $gnrquiz->realsumscore = $tempScore;
    $gnrquiz->realtypes = $tempTypes;
    $gnrquiz->realavgdiff = $tempDiff;
    $gnrquiz->realchapters = $tempChapters;
    $gnrquiz->realavgdist = $tempDist;
    $gnrquiz->realtimelimit = $tempTime;

    
    var_dump($tempScore);
    var_dump($tempTypes);
    var_dump($tempDiff);
    var_dump($tempChapters);
    var_dump($tempDist);
    var_dump($tempTime);
} #else is removed, there should be a defined questions all the time

var_dump($gnrquiz);*/

/*
$genequery = "SELECT g.questionid FROM {gnrquiz_slots} g WHERE g.gnrquizid = 2";

$bestgene = $DB->get_records_sql($genequery, array());

var_dump($bestgene);*/

if ($unfinished) {
    if ($canattempt) {
        $viewobj->buttontext = get_string('continueattemptgnrquiz', 'gnrquiz');
    } else if ($canpreview) {
        $viewobj->buttontext = get_string('continuepreview', 'gnrquiz');
    }

} else {
    if ($canattempt) {
        $viewobj->preventmessages = $viewobj->accessmanager->prevent_new_attempt(
                $viewobj->numattempts, $viewobj->lastfinishedattempt);
        if ($viewobj->preventmessages) {
            $viewobj->buttontext = '';
        } else if ($viewobj->numattempts == 0) {
            $viewobj->buttontext = get_string('attemptgnrquiznow', 'gnrquiz');
        } else {
            $viewobj->buttontext = get_string('reattemptgnrquiz', 'gnrquiz');
        }

    } else if ($canpreview) {
        $viewobj->buttontext = get_string('previewgnrquiznow', 'gnrquiz');
    }
}

// If, so far, we think a button should be printed, so check if they will be
// allowed to access it.
if ($viewobj->buttontext) {
    if (!$viewobj->moreattempts) {
        $viewobj->buttontext = '';
    } else if ($canattempt
            && $viewobj->preventmessages = $viewobj->accessmanager->prevent_access()) {
        $viewobj->buttontext = '';
    }
}
#var_dump($gnrquizobj);

$viewobj->showbacktocourse = ($viewobj->buttontext === '' &&
        course_get_format($course)->has_view_page());

echo $OUTPUT->header();

if (isguestuser()) {
    // Guests can't do a gnrquiz, so offer them a choice of logging in or going back.
    echo $output->view_page_guest($course, $gnrquiz, $cm, $context, $viewobj->infomessages);
} else if (!isguestuser() && !($canattempt || $canpreview
          || $viewobj->canreviewmine)) {
    // If they are not enrolled in this course in a good enough role, tell them to enrol.
    echo $output->view_page_notenrolled($course, $gnrquiz, $cm, $context, $viewobj->infomessages);
} else {

    echo $output->view_page($course, $gnrquiz, $cm, $context, $viewobj);
}

echo $OUTPUT->footer();
