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
 * Library of functions for the gnrquiz module.
 *
 * This contains functions that are called also from outside the gnrquiz module
 * Functions that are only called by the gnrquiz module itself are in {@link locallib.php}
 *
 * @package    mod_gnrquiz
 * @copyright  1999 onwards Martin Dougiamas {@link http://moodle.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/eventslib.php');
require_once($CFG->dirroot . '/calendar/lib.php');
require_once($CFG->dirroot.'/mod/gnrquiz/classes/structure.php');
require_once($CFG->dirroot.'/mod/gnrquiz/classes/population.php');
require_once($CFG->dirroot.'/mod/gnrquiz/classes/chromosome.php');


/**#@+
 * Option controlling what options are offered on the gnrquiz settings form.
 */
define('GNRQUIZ_MAX_ATTEMPT_OPTION', 10);
define('GNRQUIZ_MAX_QPP_OPTION', 50);
define('GNRQUIZ_MAX_DECIMAL_OPTION', 5);
define('GNRQUIZ_MAX_Q_DECIMAL_OPTION', 7);
/**#@-*/

/**#@+
 * Options determining how the grades from individual attempts are combined to give
 * the overall grade for a user
 */
define('GNRQUIZ_GRADEHIGHEST', '1');
define('GNRQUIZ_GRADEAVERAGE', '2');
define('GNRQUIZ_ATTEMPTFIRST', '3');
define('GNRQUIZ_ATTEMPTLAST',  '4');
/**#@-*/

/**
 * @var int If start and end date for the gnrquiz are more than this many seconds apart
 * they will be represented by two separate events in the calendar
 */
define('GNRQUIZ_MAX_EVENT_LENGTH', 5*24*60*60); // 5 days.

/**#@+
 * Options for navigation method within gnrquizzes.
 */
define('GNRQUIZ_NAVMETHOD_FREE', 'free');
define('GNRQUIZ_NAVMETHOD_SEQ',  'sequential');
/**#@-*/

/**
 * Given an object containing all the necessary data,
 * (defined by the form in mod_form.php) this function
 * will create a new instance and return the id number
 * of the new instance.
 *
 * @param object $gnrquiz the data that came from the form.
 * @return mixed the id of the new instance on success,
 *          false or a string error message on failure.
 */
function gnrquiz_add_instance($gnrquiz) {
    $alltypes['choicegnrquiz'] = $gnrquiz->multichoice;
    $alltypes['essaygnrquiz'] = $gnrquiz->essay;
    $alltypes['matchgnrquiz'] = $gnrquiz->match;
    $alltypes['truefalsegnrquiz'] = $gnrquiz->truefalse;
    $alltypes['shortgnrquiz'] = $gnrquiz->shortanswer;
/*
    $alltypes = array($gnrquiz->multichoice, 
        $gnrquiz->essay,
        $gnrquiz->match,
        $gnrquiz->truefalse,
        $gnrquiz->shortanswer
    );*/
    $gnrquiz->types = serialize($alltypes);

    #var_dump($gnrquiz);

    $questids = unserialize($gnrquiz->allids);
    $allchapters = array();
    foreach ($questids as $value) {
        $allchapters[$value] = $gnrquiz->{'category_' . $value};
    }
    $gnrquiz->chapters = serialize($allchapters);

    #var_dump($questids);
    #var_dump($allchapters);
    
    //var_dump($gnrquiz);


    global $DB;
    $cmid = $gnrquiz->coursemodule;

    // Process the options from the form.
    $gnrquiz->created = time();
    $result = gnrquiz_process_options($gnrquiz);
    if ($result && is_string($result)) {
        return $result;
    }

    // Try to store it in the database.
    $gnrquiz->id = $DB->insert_record('gnrquiz', $gnrquiz);

    $gnrquiz = generate_questions_using_genetic_algorihm($gnrquiz);

    $DB->update_record('gnrquiz', $gnrquiz);

    // Create the first section for this gnrquiz.
    $DB->insert_record('gnrquiz_sections', array('gnrquizid' => $gnrquiz->id,
            'firstslot' => 1, 'heading' => '', 'shufflequestions' => 0));

    // Do the processing required after an add or an update.
    gnrquiz_after_add_or_update($gnrquiz);

    return $gnrquiz->id;
}


function generate_questions_using_genetic_algorihm($gnrquiz){
    global $DB;
    structure::$constraints = $gnrquiz; //get constraints

    $query = "(SELECT q.id, q.defaultmark, q.qtype, qtf.difficulty, qc.id AS catid, qtf.distinguishingdegree, qtf.time 
    FROM {question} q, {question_categories} qc, {question_truefalsegnrquiz} qtf 
    WHERE q.category = qc.id AND q.id = qtf.question 
    ORDER BY q.id ASC) 
    UNION 
    (SELECT q.id, q.defaultmark, q.qtype, qmc.difficulty, qc.id AS catid, qmc.distinguishingdegree, qmc.time 
    FROM {question} q, {question_categories} qc, {qtype_choicegnrquiz_options} qmc
    WHERE q.category = qc.id AND q.id = qmc.questionid 
    ORDER BY q.id ASC)
    UNION 
    (SELECT q.id, q.defaultmark, q.qtype, qm.difficulty, qc.id AS catid, qm.distinguishingdegree, qm.time 
    FROM {question} q, {question_categories} qc, {qtype_matchgnrquiz_options} qm
    WHERE q.category = qc.id AND q.id = qm.questionid 
    ORDER BY q.id ASC)
    UNION 
    (SELECT q.id, q.defaultmark, q.qtype, qe.difficulty, qc.id AS catid, qe.distinguishingdegree, qe.time 
    FROM {question} q, {question_categories} qc, {qtype_essaygnrquiz_options} qe
    WHERE q.category = qc.id AND q.id = qe.questionid 
    ORDER BY q.id ASC)
    UNION 
    (SELECT q.id, q.defaultmark, q.qtype, qs.difficulty, qc.id AS catid, qs.distinguishingdegree, qs.time 
    FROM {question} q, {question_categories} qc, {qtype_shortgnrquiz_options} qs
    WHERE q.category = qc.id AND q.id = qs.questionid 
    ORDER BY q.id ASC)";

    structure::$allquestions = $DB->get_records_sql($query, array());

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
        $ss = structure::$allquestions[$value]->catid;
        if (array_key_exists($ss, $tempChapters)){
            $tempChapters[$ss] += 1;
        } else {
            $tempChapters[$ss] = 1;
        }
    }
    $tempDiff /= count($best->gene); #average quiz difficulty value
    $tempDist /= count($best->gene); #average quiz distinguishing degree value


    $gnrquiz->realsumscore = $tempScore;
    $gnrquiz->realtypes = serialize($tempTypes);
    $gnrquiz->realavgdiff = $tempDiff;
    $gnrquiz->realchapters = serialize($tempChapters);
    $gnrquiz->realavgdist = $tempDist;
    $gnrquiz->realtimelimit = $tempTime;
    structure::$constraints = $gnrquiz; //save statistics

    #var_dump($gnrquiz);

    return $gnrquiz;
}

/**
 * Given an object containing all the necessary data,
 * (defined by the form in mod_form.php) this function
 * will update an existing instance with new data.
 *
 * @param object $gnrquiz the data that came from the form.
 * @return mixed true on success, false or a string error message on failure.
 */
function gnrquiz_update_instance($gnrquiz, $mform) {
    $alltypes['choicegnrquiz'] = $gnrquiz->multichoice;
    $alltypes['essaygnrquiz'] = $gnrquiz->essay;
    $alltypes['matchgnrquiz'] = $gnrquiz->match;
    $alltypes['truefalsegnrquiz'] = $gnrquiz->truefalse;
    $alltypes['shortgnrquiz'] = $gnrquiz->shortanswer;
    /*
    $alltypes = array($gnrquiz->multichoice, 
        $gnrquiz->essay,
        $gnrquiz->match,
        $gnrquiz->truefalse,
        $gnrquiz->shortanswer
    );*/
    $gnrquiz->types = serialize($alltypes);

    #var_dump($gnrquiz);

    $questids = unserialize($gnrquiz->allids);
    $allchapters = array();
    foreach ($questids as $value) {
        $allchapters[$value] = $gnrquiz->{'category_' . $value};
    }
    $gnrquiz->chapters = serialize($allchapters);

    #var_dump($questids);
    #var_dump($allchapters);
    #var_dump($gnrquiz);

    global $CFG, $DB;
    require_once($CFG->dirroot . '/mod/gnrquiz/locallib.php');

    // Process the options from the form.
    $result = gnrquiz_process_options($gnrquiz);
    if ($result && is_string($result)) {
        return $result;
    }

    // Get the current value, so we can see what changed.
    $oldgnrquiz = $DB->get_record('gnrquiz', array('id' => $gnrquiz->instance));

    // We need two values from the existing DB record that are not in the form,
    // in some of the function calls below.
    $gnrquiz->sumgrades = $oldgnrquiz->sumgrades;
    $gnrquiz->grade     = $oldgnrquiz->grade;

    // Update the database.
    $gnrquiz->id = $gnrquiz->instance;

    $gnrquiz = generate_questions_using_genetic_algorihm($gnrquiz);
    #var_dump($gnrquiz);

    $DB->update_record('gnrquiz', $gnrquiz);

    // Do the processing required after an add or an update.
    gnrquiz_after_add_or_update($gnrquiz);

    if ($oldgnrquiz->grademethod != $gnrquiz->grademethod) {
        gnrquiz_update_all_final_grades($gnrquiz);
        gnrquiz_update_grades($gnrquiz);
    }

    $gnrquizdateschanged = $oldgnrquiz->timelimit   != $gnrquiz->timelimit
                     || $oldgnrquiz->timeclose   != $gnrquiz->timeclose
                     || $oldgnrquiz->graceperiod != $gnrquiz->graceperiod;
    if ($gnrquizdateschanged) {
        gnrquiz_update_open_attempts(array('gnrquizid' => $gnrquiz->id));
    }

    // Delete any previous preview attempts.
    gnrquiz_delete_previews($gnrquiz);

    // Repaginate, if asked to.
    if (!empty($gnrquiz->repaginatenow)) {
        gnrquiz_repaginate_questions($gnrquiz->id, $gnrquiz->questionsperpage);
    }

    return true;
}

/**
 * Given an ID of an instance of this module,
 * this function will permanently delete the instance
 * and any data that depends on it.
 *
 * @param int $id the id of the gnrquiz to delete.
 * @return bool success or failure.
 */
function gnrquiz_delete_instance($id) {
    global $DB;

    $gnrquiz = $DB->get_record('gnrquiz', array('id' => $id), '*', MUST_EXIST);

    gnrquiz_delete_all_attempts($gnrquiz);
    gnrquiz_delete_all_overrides($gnrquiz);

    // Look for random questions that may no longer be used when this gnrquiz is gone.
    $sql = "SELECT q.id
              FROM {gnrquiz_slots} slot
              JOIN {question} q ON q.id = slot.questionid
             WHERE slot.gnrquizid = ? AND q.qtype = ?";
    $questionids = $DB->get_fieldset_sql($sql, array($gnrquiz->id, 'random'));

    // We need to do this before we try and delete randoms, otherwise they would still be 'in use'.
    $DB->delete_records('gnrquiz_slots', array('gnrquizid' => $gnrquiz->id));
    $DB->delete_records('gnrquiz_sections', array('gnrquizid' => $gnrquiz->id));

    foreach ($questionids as $questionid) {
        question_delete_question($questionid);
    }

    $DB->delete_records('gnrquiz_feedback', array('gnrquizid' => $gnrquiz->id));

    gnrquiz_access_manager::delete_settings($gnrquiz);

    $events = $DB->get_records('event', array('modulename' => 'gnrquiz', 'instance' => $gnrquiz->id));
    foreach ($events as $event) {
        $event = calendar_event::load($event);
        $event->delete();
    }

    gnrquiz_grade_item_delete($gnrquiz);
    $DB->delete_records('gnrquiz', array('id' => $gnrquiz->id));

    return true;
}

/**
 * Deletes a gnrquiz override from the database and clears any corresponding calendar events
 *
 * @param object $gnrquiz The gnrquiz object.
 * @param int $overrideid The id of the override being deleted
 * @return bool true on success
 */
function gnrquiz_delete_override($gnrquiz, $overrideid) {
    global $DB;

    if (!isset($gnrquiz->cmid)) {
        $cm = get_coursemodule_from_instance('gnrquiz', $gnrquiz->id, $gnrquiz->course);
        $gnrquiz->cmid = $cm->id;
    }

    $override = $DB->get_record('gnrquiz_overrides', array('id' => $overrideid), '*', MUST_EXIST);

    // Delete the events.
    $events = $DB->get_records('event', array('modulename' => 'gnrquiz',
            'instance' => $gnrquiz->id, 'groupid' => (int)$override->groupid,
            'userid' => (int)$override->userid));
    foreach ($events as $event) {
        $eventold = calendar_event::load($event);
        $eventold->delete();
    }

    $DB->delete_records('gnrquiz_overrides', array('id' => $overrideid));

    // Set the common parameters for one of the events we will be triggering.
    $params = array(
        'objectid' => $override->id,
        'context' => context_module::instance($gnrquiz->cmid),
        'other' => array(
            'gnrquizid' => $override->gnrquiz
        )
    );
    // Determine which override deleted event to fire.
    if (!empty($override->userid)) {
        $params['relateduserid'] = $override->userid;
        $event = \mod_gnrquiz\event\user_override_deleted::create($params);
    } else {
        $params['other']['groupid'] = $override->groupid;
        $event = \mod_gnrquiz\event\group_override_deleted::create($params);
    }

    // Trigger the override deleted event.
    $event->add_record_snapshot('gnrquiz_overrides', $override);
    $event->trigger();

    return true;
}

/**
 * Deletes all gnrquiz overrides from the database and clears any corresponding calendar events
 *
 * @param object $gnrquiz The gnrquiz object.
 */
function gnrquiz_delete_all_overrides($gnrquiz) {
    global $DB;

    $overrides = $DB->get_records('gnrquiz_overrides', array('gnrquiz' => $gnrquiz->id), 'id');
    foreach ($overrides as $override) {
        gnrquiz_delete_override($gnrquiz, $override->id);
    }
}

/**
 * Updates a gnrquiz object with override information for a user.
 *
 * Algorithm:  For each gnrquiz setting, if there is a matching user-specific override,
 *   then use that otherwise, if there are group-specific overrides, return the most
 *   lenient combination of them.  If neither applies, leave the gnrquiz setting unchanged.
 *
 *   Special case: if there is more than one password that applies to the user, then
 *   gnrquiz->extrapasswords will contain an array of strings giving the remaining
 *   passwords.
 *
 * @param object $gnrquiz The gnrquiz object.
 * @param int $userid The userid.
 * @return object $gnrquiz The updated gnrquiz object.
 */
function gnrquiz_update_effective_access($gnrquiz, $userid) {
    global $DB;

    // Check for user override.
    $override = $DB->get_record('gnrquiz_overrides', array('gnrquiz' => $gnrquiz->id, 'userid' => $userid));

    if (!$override) {
        $override = new stdClass();
        $override->timeopen = null;
        $override->timeclose = null;
        $override->timelimit = null;
        $override->attempts = null;
        $override->password = null;
    }

    // Check for group overrides.
    $groupings = groups_get_user_groups($gnrquiz->course, $userid);

    if (!empty($groupings[0])) {
        // Select all overrides that apply to the User's groups.
        list($extra, $params) = $DB->get_in_or_equal(array_values($groupings[0]));
        $sql = "SELECT * FROM {gnrquiz_overrides}
                WHERE groupid $extra AND gnrquiz = ?";
        $params[] = $gnrquiz->id;
        $records = $DB->get_records_sql($sql, $params);

        // Combine the overrides.
        $opens = array();
        $closes = array();
        $limits = array();
        $attempts = array();
        $passwords = array();

        foreach ($records as $gpoverride) {
            if (isset($gpoverride->timeopen)) {
                $opens[] = $gpoverride->timeopen;
            }
            if (isset($gpoverride->timeclose)) {
                $closes[] = $gpoverride->timeclose;
            }
            if (isset($gpoverride->timelimit)) {
                $limits[] = $gpoverride->timelimit;
            }
            if (isset($gpoverride->attempts)) {
                $attempts[] = $gpoverride->attempts;
            }
            if (isset($gpoverride->password)) {
                $passwords[] = $gpoverride->password;
            }
        }
        // If there is a user override for a setting, ignore the group override.
        if (is_null($override->timeopen) && count($opens)) {
            $override->timeopen = min($opens);
        }
        if (is_null($override->timeclose) && count($closes)) {
            if (in_array(0, $closes)) {
                $override->timeclose = 0;
            } else {
                $override->timeclose = max($closes);
            }
        }
        if (is_null($override->timelimit) && count($limits)) {
            if (in_array(0, $limits)) {
                $override->timelimit = 0;
            } else {
                $override->timelimit = max($limits);
            }
        }
        if (is_null($override->attempts) && count($attempts)) {
            if (in_array(0, $attempts)) {
                $override->attempts = 0;
            } else {
                $override->attempts = max($attempts);
            }
        }
        if (is_null($override->password) && count($passwords)) {
            $override->password = array_shift($passwords);
            if (count($passwords)) {
                $override->extrapasswords = $passwords;
            }
        }

    }

    // Merge with gnrquiz defaults.
    $keys = array('timeopen', 'timeclose', 'timelimit', 'attempts', 'password', 'extrapasswords');
    foreach ($keys as $key) {
        if (isset($override->{$key})) {
            $gnrquiz->{$key} = $override->{$key};
        }
    }

    return $gnrquiz;
}

/**
 * Delete all the attempts belonging to a gnrquiz.
 *
 * @param object $gnrquiz The gnrquiz object.
 */
function gnrquiz_delete_all_attempts($gnrquiz) {
    global $CFG, $DB;
    require_once($CFG->dirroot . '/mod/gnrquiz/locallib.php');
    question_engine::delete_questions_usage_by_activities(new qubaids_for_gnrquiz($gnrquiz->id));
    $DB->delete_records('gnrquiz_attempts', array('gnrquiz' => $gnrquiz->id));
    $DB->delete_records('gnrquiz_grades', array('gnrquiz' => $gnrquiz->id));
}

/**
 * Get the best current grade for a particular user in a gnrquiz.
 *
 * @param object $gnrquiz the gnrquiz settings.
 * @param int $userid the id of the user.
 * @return float the user's current grade for this gnrquiz, or null if this user does
 * not have a grade on this gnrquiz.
 */
function gnrquiz_get_best_grade($gnrquiz, $userid) {
    global $DB;
    $grade = $DB->get_field('gnrquiz_grades', 'grade',
            array('gnrquiz' => $gnrquiz->id, 'userid' => $userid));

    // Need to detect errors/no result, without catching 0 grades.
    if ($grade === false) {
        return null;
    }

    return $grade + 0; // Convert to number.
}

/**
 * Is this a graded gnrquiz? If this method returns true, you can assume that
 * $gnrquiz->grade and $gnrquiz->sumgrades are non-zero (for example, if you want to
 * divide by them).
 *
 * @param object $gnrquiz a row from the gnrquiz table.
 * @return bool whether this is a graded gnrquiz.
 */
function gnrquiz_has_grades($gnrquiz) {
    return $gnrquiz->grade >= 0.000005 && $gnrquiz->sumgrades >= 0.000005;
}

/**
 * Does this gnrquiz allow multiple tries?
 *
 * @return bool
 */
function gnrquiz_allows_multiple_tries($gnrquiz) {
    $bt = question_engine::get_behaviour_type($gnrquiz->preferredbehaviour);
    return $bt->allows_multiple_submitted_responses();
}

/**
 * Return a small object with summary information about what a
 * user has done with a given particular instance of this module
 * Used for user activity reports.
 * $return->time = the time they did it
 * $return->info = a short text description
 *
 * @param object $course
 * @param object $user
 * @param object $mod
 * @param object $gnrquiz
 * @return object|null
 */
function gnrquiz_user_outline($course, $user, $mod, $gnrquiz) {
    global $DB, $CFG;
    require_once($CFG->libdir . '/gradelib.php');
    $grades = grade_get_grades($course->id, 'mod', 'gnrquiz', $gnrquiz->id, $user->id);

    if (empty($grades->items[0]->grades)) {
        return null;
    } else {
        $grade = reset($grades->items[0]->grades);
    }

    $result = new stdClass();
    // If the user can't see hidden grades, don't return that information.
    $gitem = grade_item::fetch(array('id' => $grades->items[0]->id));
    if (!$gitem->hidden || has_capability('moodle/grade:viewhidden', context_course::instance($course->id))) {
        $result->info = get_string('grade') . ': ' . $grade->str_long_grade;
    } else {
        $result->info = get_string('grade') . ': ' . get_string('hidden', 'grades');
    }

    // Datesubmitted == time created. dategraded == time modified or time overridden
    // if grade was last modified by the user themselves use date graded. Otherwise use
    // date submitted.
    // TODO: move this copied & pasted code somewhere in the grades API. See MDL-26704.
    if ($grade->usermodified == $user->id || empty($grade->datesubmitted)) {
        $result->time = $grade->dategraded;
    } else {
        $result->time = $grade->datesubmitted;
    }

    return $result;
}

/**
 * Print a detailed representation of what a  user has done with
 * a given particular instance of this module, for user activity reports.
 *
 * @param object $course
 * @param object $user
 * @param object $mod
 * @param object $gnrquiz
 * @return bool
 */
function gnrquiz_user_complete($course, $user, $mod, $gnrquiz) {
    global $DB, $CFG, $OUTPUT;
    require_once($CFG->libdir . '/gradelib.php');
    require_once($CFG->dirroot . '/mod/gnrquiz/locallib.php');

    $grades = grade_get_grades($course->id, 'mod', 'gnrquiz', $gnrquiz->id, $user->id);
    if (!empty($grades->items[0]->grades)) {
        $grade = reset($grades->items[0]->grades);
        // If the user can't see hidden grades, don't return that information.
        $gitem = grade_item::fetch(array('id' => $grades->items[0]->id));
        if (!$gitem->hidden || has_capability('moodle/grade:viewhidden', context_course::instance($course->id))) {
            echo $OUTPUT->container(get_string('grade').': '.$grade->str_long_grade);
            if ($grade->str_feedback) {
                echo $OUTPUT->container(get_string('feedback').': '.$grade->str_feedback);
            }
        } else {
            echo $OUTPUT->container(get_string('grade') . ': ' . get_string('hidden', 'grades'));
            if ($grade->str_feedback) {
                echo $OUTPUT->container(get_string('feedback').': '.get_string('hidden', 'grades'));
            }
        }
    }

    if ($attempts = $DB->get_records('gnrquiz_attempts',
            array('userid' => $user->id, 'gnrquiz' => $gnrquiz->id), 'attempt')) {
        foreach ($attempts as $attempt) {
            echo get_string('attempt', 'gnrquiz', $attempt->attempt) . ': ';
            if ($attempt->state != gnrquiz_attempt::FINISHED) {
                echo gnrquiz_attempt_state_name($attempt->state);
            } else {
                if (!isset($gitem)) {
                    if (!empty($grades->items[0]->grades)) {
                        $gitem = grade_item::fetch(array('id' => $grades->items[0]->id));
                    } else {
                        $gitem = new stdClass();
                        $gitem->hidden = true;
                    }
                }
                if (!$gitem->hidden || has_capability('moodle/grade:viewhidden', context_course::instance($course->id))) {
                    echo gnrquiz_format_grade($gnrquiz, $attempt->sumgrades) . '/' . gnrquiz_format_grade($gnrquiz, $gnrquiz->sumgrades);
                } else {
                    echo get_string('hidden', 'grades');
                }
            }
            echo ' - '.userdate($attempt->timemodified).'<br />';
        }
    } else {
        print_string('noattempts', 'gnrquiz');
    }

    return true;
}

/**
 * Quiz periodic clean-up tasks.
 */
function gnrquiz_cron() {
    global $CFG;

    require_once($CFG->dirroot . '/mod/gnrquiz/cronlib.php');
    mtrace('');

    $timenow = time();
    $overduehander = new mod_gnrquiz_overdue_attempt_updater();

    $processto = $timenow - get_config('quiz', 'graceperiodmin');

    mtrace('  Looking for gnrquiz overdue gnrquiz attempts...');

    list($count, $gnrquizcount) = $overduehander->update_overdue_attempts($timenow, $processto);

    mtrace('  Considered ' . $count . ' attempts in ' . $gnrquizcount . ' gnrquizzes.');

    // Run cron for our sub-plugin types.
    cron_execute_plugin_type('gnrquiz', 'gnrquiz reports');
    cron_execute_plugin_type('gnrquizaccess', 'gnrquiz access rules');

    return true;
}

/**
 * @param int|array $gnrquizids A gnrquiz ID, or an array of gnrquiz IDs.
 * @param int $userid the userid.
 * @param string $status 'all', 'finished' or 'unfinished' to control
 * @param bool $includepreviews
 * @return an array of all the user's attempts at this gnrquiz. Returns an empty
 *      array if there are none.
 */
function gnrquiz_get_user_attempts($gnrquizids, $userid, $status = 'finished', $includepreviews = false) {
    global $DB, $CFG;
    // TODO MDL-33071 it is very annoying to have to included all of locallib.php
    // just to get the gnrquiz_attempt::FINISHED constants, but I will try to sort
    // that out properly for Moodle 2.4. For now, I will just do a quick fix for
    // MDL-33048.
    require_once($CFG->dirroot . '/mod/gnrquiz/locallib.php');

    $params = array();
    switch ($status) {
        case 'all':
            $statuscondition = '';
            break;

        case 'finished':
            $statuscondition = ' AND state IN (:state1, :state2)';
            $params['state1'] = gnrquiz_attempt::FINISHED;
            $params['state2'] = gnrquiz_attempt::ABANDONED;
            break;

        case 'unfinished':
            $statuscondition = ' AND state IN (:state1, :state2)';
            $params['state1'] = gnrquiz_attempt::IN_PROGRESS;
            $params['state2'] = gnrquiz_attempt::OVERDUE;
            break;
    }

    $gnrquizids = (array) $gnrquizids;
    list($insql, $inparams) = $DB->get_in_or_equal($gnrquizids, SQL_PARAMS_NAMED);
    $params += $inparams;
    $params['userid'] = $userid;

    $previewclause = '';
    if (!$includepreviews) {
        $previewclause = ' AND preview = 0';
    }

    return $DB->get_records_select('gnrquiz_attempts',
            "gnrquiz $insql AND userid = :userid" . $previewclause . $statuscondition,
            $params, 'gnrquiz, attempt ASC');
}

/**
 * Return grade for given user or all users.
 *
 * @param int $gnrquizid id of gnrquiz
 * @param int $userid optional user id, 0 means all users
 * @return array array of grades, false if none. These are raw grades. They should
 * be processed with gnrquiz_format_grade for display.
 */
function gnrquiz_get_user_grades($gnrquiz, $userid = 0) {
    global $CFG, $DB;

    $params = array($gnrquiz->id);
    $usertest = '';
    if ($userid) {
        $params[] = $userid;
        $usertest = 'AND u.id = ?';
    }
    return $DB->get_records_sql("
            SELECT
                u.id,
                u.id AS userid,
                qg.grade AS rawgrade,
                qg.timemodified AS dategraded,
                MAX(qa.timefinish) AS datesubmitted

            FROM {user} u
            JOIN {gnrquiz_grades} qg ON u.id = qg.userid
            JOIN {gnrquiz_attempts} qa ON qa.gnrquiz = qg.gnrquiz AND qa.userid = u.id

            WHERE qg.gnrquiz = ?
            $usertest
            GROUP BY u.id, qg.grade, qg.timemodified", $params);
}

/**
 * Round a grade to to the correct number of decimal places, and format it for display.
 *
 * @param object $gnrquiz The gnrquiz table row, only $gnrquiz->decimalpoints is used.
 * @param float $grade The grade to round.
 * @return float
 */
function gnrquiz_format_grade($gnrquiz, $grade) {
    if (is_null($grade)) {
        return get_string('notyetgraded', 'gnrquiz');
    }
    return format_float($grade, $gnrquiz->decimalpoints);
}

/**
 * Determine the correct number of decimal places required to format a grade.
 *
 * @param object $gnrquiz The gnrquiz table row, only $gnrquiz->decimalpoints is used.
 * @return integer
 */
function gnrquiz_get_grade_format($gnrquiz) {
    if (empty($gnrquiz->questiondecimalpoints)) {
        $gnrquiz->questiondecimalpoints = -1;
    }

    if ($gnrquiz->questiondecimalpoints == -1) {
        return $gnrquiz->decimalpoints;
    }

    return $gnrquiz->questiondecimalpoints;
}

/**
 * Round a grade to the correct number of decimal places, and format it for display.
 *
 * @param object $gnrquiz The gnrquiz table row, only $gnrquiz->decimalpoints is used.
 * @param float $grade The grade to round.
 * @return float
 */
function gnrquiz_format_question_grade($gnrquiz, $grade) {
    return format_float($grade, gnrquiz_get_grade_format($gnrquiz));
}

/**
 * Update grades in central gradebook
 *
 * @category grade
 * @param object $gnrquiz the gnrquiz settings.
 * @param int $userid specific user only, 0 means all users.
 * @param bool $nullifnone If a single user is specified and $nullifnone is true a grade item with a null rawgrade will be inserted
 */
function gnrquiz_update_grades($gnrquiz, $userid = 0, $nullifnone = true) {
    global $CFG, $DB;
    require_once($CFG->libdir . '/gradelib.php');

    if ($gnrquiz->grade == 0) {
        gnrquiz_grade_item_update($gnrquiz);

    } else if ($grades = gnrquiz_get_user_grades($gnrquiz, $userid)) {
        gnrquiz_grade_item_update($gnrquiz, $grades);

    } else if ($userid && $nullifnone) {
        $grade = new stdClass();
        $grade->userid = $userid;
        $grade->rawgrade = null;
        gnrquiz_grade_item_update($gnrquiz, $grade);

    } else {
        gnrquiz_grade_item_update($gnrquiz);
    }
}

/**
 * Create or update the grade item for given gnrquiz
 *
 * @category grade
 * @param object $gnrquiz object with extra cmidnumber
 * @param mixed $grades optional array/object of grade(s); 'reset' means reset grades in gradebook
 * @return int 0 if ok, error code otherwise
 */
function gnrquiz_grade_item_update($gnrquiz, $grades = null) {
    global $CFG, $OUTPUT;
    require_once($CFG->dirroot . '/mod/gnrquiz/locallib.php');
    require_once($CFG->libdir . '/gradelib.php');

    if (array_key_exists('cmidnumber', $gnrquiz)) { // May not be always present.
        $params = array('itemname' => $gnrquiz->name, 'idnumber' => $gnrquiz->cmidnumber);
    } else {
        $params = array('itemname' => $gnrquiz->name);
    }

    if ($gnrquiz->grade > 0) {
        $params['gradetype'] = GRADE_TYPE_VALUE;
        $params['grademax']  = $gnrquiz->grade;
        $params['grademin']  = 0;

    } else {
        $params['gradetype'] = GRADE_TYPE_NONE;
    }

    // What this is trying to do:
    // 1. If the gnrquiz is set to not show grades while the gnrquiz is still open,
    //    and is set to show grades after the gnrquiz is closed, then create the
    //    grade_item with a show-after date that is the gnrquiz close date.
    // 2. If the gnrquiz is set to not show grades at either of those times,
    //    create the grade_item as hidden.
    // 3. If the gnrquiz is set to show grades, create the grade_item visible.
    $openreviewoptions = mod_gnrquiz_display_options::make_from_gnrquiz($gnrquiz,
            mod_gnrquiz_display_options::LATER_WHILE_OPEN);
    $closedreviewoptions = mod_gnrquiz_display_options::make_from_gnrquiz($gnrquiz,
            mod_gnrquiz_display_options::AFTER_CLOSE);
    if ($openreviewoptions->marks < question_display_options::MARK_AND_MAX &&
            $closedreviewoptions->marks < question_display_options::MARK_AND_MAX) {
        $params['hidden'] = 1;

    } else if ($openreviewoptions->marks < question_display_options::MARK_AND_MAX &&
            $closedreviewoptions->marks >= question_display_options::MARK_AND_MAX) {
        if ($gnrquiz->timeclose) {
            $params['hidden'] = $gnrquiz->timeclose;
        } else {
            $params['hidden'] = 1;
        }

    } else {
        // Either
        // a) both open and closed enabled
        // b) open enabled, closed disabled - we can not "hide after",
        //    grades are kept visible even after closing.
        $params['hidden'] = 0;
    }

    if (!$params['hidden']) {
        // If the grade item is not hidden by the gnrquiz logic, then we need to
        // hide it if the gnrquiz is hidden from students.
        if (property_exists($gnrquiz, 'visible')) {
            // Saving the gnrquiz form, and cm not yet updated in the database.
            $params['hidden'] = !$gnrquiz->visible;
        } else {
            $cm = get_coursemodule_from_instance('gnrquiz', $gnrquiz->id);
            $params['hidden'] = !$cm->visible;
        }
    }

    if ($grades  === 'reset') {
        $params['reset'] = true;
        $grades = null;
    }

    $gradebook_grades = grade_get_grades($gnrquiz->course, 'mod', 'gnrquiz', $gnrquiz->id);
    if (!empty($gradebook_grades->items)) {
        $grade_item = $gradebook_grades->items[0];
        if ($grade_item->locked) {
            // NOTE: this is an extremely nasty hack! It is not a bug if this confirmation fails badly. --skodak.
            $confirm_regrade = optional_param('confirm_regrade', 0, PARAM_INT);
            if (!$confirm_regrade) {
                if (!AJAX_SCRIPT) {
                    $message = get_string('gradeitemislocked', 'grades');
                    $back_link = $CFG->wwwroot . '/mod/gnrquiz/report.php?q=' . $gnrquiz->id .
                            '&amp;mode=overview';
                    $regrade_link = qualified_me() . '&amp;confirm_regrade=1';
                    echo $OUTPUT->box_start('generalbox', 'notice');
                    echo '<p>'. $message .'</p>';
                    echo $OUTPUT->container_start('buttons');
                    echo $OUTPUT->single_button($regrade_link, get_string('regradeanyway', 'grades'));
                    echo $OUTPUT->single_button($back_link,  get_string('cancel'));
                    echo $OUTPUT->container_end();
                    echo $OUTPUT->box_end();
                }
                return GRADE_UPDATE_ITEM_LOCKED;
            }
        }
    }

    return grade_update('mod/gnrquiz', $gnrquiz->course, 'mod', 'gnrquiz', $gnrquiz->id, 0, $grades, $params);
}

/**
 * Delete grade item for given gnrquiz
 *
 * @category grade
 * @param object $gnrquiz object
 * @return object gnrquiz
 */
function gnrquiz_grade_item_delete($gnrquiz) {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    return grade_update('mod/gnrquiz', $gnrquiz->course, 'mod', 'gnrquiz', $gnrquiz->id, 0,
            null, array('deleted' => 1));
}

/**
 * This standard function will check all instances of this module
 * and make sure there are up-to-date events created for each of them.
 * If courseid = 0, then every gnrquiz event in the site is checked, else
 * only gnrquiz events belonging to the course specified are checked.
 * This function is used, in its new format, by restore_refresh_events()
 *
 * @param int $courseid
 * @return bool
 */
function gnrquiz_refresh_events($courseid = 0) {
    global $DB;

    if ($courseid == 0) {
        if (!$gnrquizzes = $DB->get_records('gnrquiz')) {
            return true;
        }
    } else {
        if (!$gnrquizzes = $DB->get_records('gnrquiz', array('course' => $courseid))) {
            return true;
        }
    }

    foreach ($gnrquizzes as $gnrquiz) {
        gnrquiz_update_events($gnrquiz);
    }

    return true;
}

/**
 * Returns all gnrquiz graded users since a given time for specified gnrquiz
 */
function gnrquiz_get_recent_mod_activity(&$activities, &$index, $timestart,
        $courseid, $cmid, $userid = 0, $groupid = 0) {
    global $CFG, $USER, $DB;
    require_once($CFG->dirroot . '/mod/gnrquiz/locallib.php');

    $course = get_course($courseid);
    $modinfo = get_fast_modinfo($course);

    $cm = $modinfo->cms[$cmid];
    $gnrquiz = $DB->get_record('gnrquiz', array('id' => $cm->instance));

    if ($userid) {
        $userselect = "AND u.id = :userid";
        $params['userid'] = $userid;
    } else {
        $userselect = '';
    }

    if ($groupid) {
        $groupselect = 'AND gm.groupid = :groupid';
        $groupjoin   = 'JOIN {groups_members} gm ON  gm.userid=u.id';
        $params['groupid'] = $groupid;
    } else {
        $groupselect = '';
        $groupjoin   = '';
    }

    $params['timestart'] = $timestart;
    $params['gnrquizid'] = $gnrquiz->id;

    $ufields = user_picture::fields('u', null, 'useridagain');
    if (!$attempts = $DB->get_records_sql("
              SELECT qa.*,
                     {$ufields}
                FROM {gnrquiz_attempts} qa
                     JOIN {user} u ON u.id = qa.userid
                     $groupjoin
               WHERE qa.timefinish > :timestart
                 AND qa.gnrquiz = :gnrquizid
                 AND qa.preview = 0
                     $userselect
                     $groupselect
            ORDER BY qa.timefinish ASC", $params)) {
        return;
    }

    $context         = context_module::instance($cm->id);
    $accessallgroups = has_capability('moodle/site:accessallgroups', $context);
    $viewfullnames   = has_capability('moodle/site:viewfullnames', $context);
    $grader          = has_capability('mod/gnrquiz:viewreports', $context);
    $groupmode       = groups_get_activity_groupmode($cm, $course);

    $usersgroups = null;
    $aname = format_string($cm->name, true);
    foreach ($attempts as $attempt) {
        if ($attempt->userid != $USER->id) {
            if (!$grader) {
                // Grade permission required.
                continue;
            }

            if ($groupmode == SEPARATEGROUPS and !$accessallgroups) {
                $usersgroups = groups_get_all_groups($course->id,
                        $attempt->userid, $cm->groupingid);
                $usersgroups = array_keys($usersgroups);
                if (!array_intersect($usersgroups, $modinfo->get_groups($cm->groupingid))) {
                    continue;
                }
            }
        }

        $options = gnrquiz_get_review_options($gnrquiz, $attempt, $context);

        $tmpactivity = new stdClass();

        $tmpactivity->type       = 'gnrquiz';
        $tmpactivity->cmid       = $cm->id;
        $tmpactivity->name       = $aname;
        $tmpactivity->sectionnum = $cm->sectionnum;
        $tmpactivity->timestamp  = $attempt->timefinish;

        $tmpactivity->content = new stdClass();
        $tmpactivity->content->attemptid = $attempt->id;
        $tmpactivity->content->attempt   = $attempt->attempt;
        if (gnrquiz_has_grades($gnrquiz) && $options->marks >= question_display_options::MARK_AND_MAX) {
            $tmpactivity->content->sumgrades = gnrquiz_format_grade($gnrquiz, $attempt->sumgrades);
            $tmpactivity->content->maxgrade  = gnrquiz_format_grade($gnrquiz, $gnrquiz->sumgrades);
        } else {
            $tmpactivity->content->sumgrades = null;
            $tmpactivity->content->maxgrade  = null;
        }

        $tmpactivity->user = user_picture::unalias($attempt, null, 'useridagain');
        $tmpactivity->user->fullname  = fullname($tmpactivity->user, $viewfullnames);

        $activities[$index++] = $tmpactivity;
    }
}

function gnrquiz_print_recent_mod_activity($activity, $courseid, $detail, $modnames) {
    global $CFG, $OUTPUT;

    echo '<table border="0" cellpadding="3" cellspacing="0" class="forum-recent">';

    echo '<tr><td class="userpicture" valign="top">';
    echo $OUTPUT->user_picture($activity->user, array('courseid' => $courseid));
    echo '</td><td>';

    if ($detail) {
        $modname = $modnames[$activity->type];
        echo '<div class="title">';
        echo '<img src="' . $OUTPUT->pix_url('icon', $activity->type) . '" ' .
                'class="icon" alt="' . $modname . '" />';
        echo '<a href="' . $CFG->wwwroot . '/mod/gnrquiz/view.php?id=' .
                $activity->cmid . '">' . $activity->name . '</a>';
        echo '</div>';
    }

    echo '<div class="grade">';
    echo  get_string('attempt', 'gnrquiz', $activity->content->attempt);
    if (isset($activity->content->maxgrade)) {
        $grades = $activity->content->sumgrades . ' / ' . $activity->content->maxgrade;
        echo ': (<a href="' . $CFG->wwwroot . '/mod/gnrquiz/review.php?attempt=' .
                $activity->content->attemptid . '">' . $grades . '</a>)';
    }
    echo '</div>';

    echo '<div class="user">';
    echo '<a href="' . $CFG->wwwroot . '/user/view.php?id=' . $activity->user->id .
            '&amp;course=' . $courseid . '">' . $activity->user->fullname .
            '</a> - ' . userdate($activity->timestamp);
    echo '</div>';

    echo '</td></tr></table>';

    return;
}

/**
 * Pre-process the gnrquiz options form data, making any necessary adjustments.
 * Called by add/update instance in this file.
 *
 * @param object $gnrquiz The variables set on the form.
 */
function gnrquiz_process_options($gnrquiz) {
    global $CFG;
    require_once($CFG->dirroot . '/mod/gnrquiz/locallib.php');
    require_once($CFG->libdir . '/questionlib.php');

    $gnrquiz->timemodified = time();

    // Quiz name.
    if (!empty($gnrquiz->name)) {
        $gnrquiz->name = trim($gnrquiz->name);
    }

    // Password field - different in form to stop browsers that remember passwords
    // getting confused.
    $gnrquiz->password = $gnrquiz->gnrquizpassword;
    unset($gnrquiz->gnrquizpassword);

    // Quiz feedback.
    if (isset($gnrquiz->feedbacktext)) {
        // Clean up the boundary text.
        for ($i = 0; $i < count($gnrquiz->feedbacktext); $i += 1) {
            if (empty($gnrquiz->feedbacktext[$i]['text'])) {
                $gnrquiz->feedbacktext[$i]['text'] = '';
            } else {
                $gnrquiz->feedbacktext[$i]['text'] = trim($gnrquiz->feedbacktext[$i]['text']);
            }
        }

        // Check the boundary value is a number or a percentage, and in range.
        $i = 0;
        while (!empty($gnrquiz->feedbackboundaries[$i])) {
            $boundary = trim($gnrquiz->feedbackboundaries[$i]);
            if (!is_numeric($boundary)) {
                if (strlen($boundary) > 0 && $boundary[strlen($boundary) - 1] == '%') {
                    $boundary = trim(substr($boundary, 0, -1));
                    if (is_numeric($boundary)) {
                        $boundary = $boundary * $gnrquiz->grade / 100.0;
                    } else {
                        return get_string('feedbackerrorboundaryformat', 'gnrquiz', $i + 1);
                    }
                }
            }
            if ($boundary <= 0 || $boundary >= $gnrquiz->grade) {
                return get_string('feedbackerrorboundaryoutofrange', 'gnrquiz', $i + 1);
            }
            if ($i > 0 && $boundary >= $gnrquiz->feedbackboundaries[$i - 1]) {
                return get_string('feedbackerrororder', 'gnrquiz', $i + 1);
            }
            $gnrquiz->feedbackboundaries[$i] = $boundary;
            $i += 1;
        }
        $numboundaries = $i;

        // Check there is nothing in the remaining unused fields.
        if (!empty($gnrquiz->feedbackboundaries)) {
            for ($i = $numboundaries; $i < count($gnrquiz->feedbackboundaries); $i += 1) {
                if (!empty($gnrquiz->feedbackboundaries[$i]) &&
                        trim($gnrquiz->feedbackboundaries[$i]) != '') {
                    return get_string('feedbackerrorjunkinboundary', 'gnrquiz', $i + 1);
                }
            }
        }
        for ($i = $numboundaries + 1; $i < count($gnrquiz->feedbacktext); $i += 1) {
            if (!empty($gnrquiz->feedbacktext[$i]['text']) &&
                    trim($gnrquiz->feedbacktext[$i]['text']) != '') {
                return get_string('feedbackerrorjunkinfeedback', 'gnrquiz', $i + 1);
            }
        }
        // Needs to be bigger than $gnrquiz->grade because of '<' test in gnrquiz_feedback_for_grade().
        $gnrquiz->feedbackboundaries[-1] = $gnrquiz->grade + 1;
        $gnrquiz->feedbackboundaries[$numboundaries] = 0;
        $gnrquiz->feedbackboundarycount = $numboundaries;
    } else {
        $gnrquiz->feedbackboundarycount = -1;
    }

    // Combing the individual settings into the review columns.
    $gnrquiz->reviewattempt = gnrquiz_review_option_form_to_db($gnrquiz, 'attempt');
    $gnrquiz->reviewcorrectness = gnrquiz_review_option_form_to_db($gnrquiz, 'correctness');
    $gnrquiz->reviewmarks = gnrquiz_review_option_form_to_db($gnrquiz, 'marks');
    $gnrquiz->reviewspecificfeedback = gnrquiz_review_option_form_to_db($gnrquiz, 'specificfeedback');
    $gnrquiz->reviewgeneralfeedback = gnrquiz_review_option_form_to_db($gnrquiz, 'generalfeedback');
    $gnrquiz->reviewrightanswer = gnrquiz_review_option_form_to_db($gnrquiz, 'rightanswer');
    $gnrquiz->reviewoverallfeedback = gnrquiz_review_option_form_to_db($gnrquiz, 'overallfeedback');
    $gnrquiz->reviewattempt |= mod_gnrquiz_display_options::DURING;
    $gnrquiz->reviewoverallfeedback &= ~mod_gnrquiz_display_options::DURING;
}

/**
 * Helper function for {@link gnrquiz_process_options()}.
 * @param object $fromform the sumbitted form date.
 * @param string $field one of the review option field names.
 */
function gnrquiz_review_option_form_to_db($fromform, $field) {
    static $times = array(
        'during' => mod_gnrquiz_display_options::DURING,
        'immediately' => mod_gnrquiz_display_options::IMMEDIATELY_AFTER,
        'open' => mod_gnrquiz_display_options::LATER_WHILE_OPEN,
        'closed' => mod_gnrquiz_display_options::AFTER_CLOSE,
    );

    $review = 0;
    foreach ($times as $whenname => $when) {
        $fieldname = $field . $whenname;
        if (isset($fromform->$fieldname)) {
            $review |= $when;
            unset($fromform->$fieldname);
        }
    }

    return $review;
}

/**
 * This function is called at the end of gnrquiz_add_instance
 * and gnrquiz_update_instance, to do the common processing.
 *
 * @param object $gnrquiz the gnrquiz object.
 */
function gnrquiz_after_add_or_update($gnrquiz) {
    global $DB;
    $cmid = $gnrquiz->coursemodule;

    // We need to use context now, so we need to make sure all needed info is already in db.
    $DB->set_field('course_modules', 'instance', $gnrquiz->id, array('id'=>$cmid));
    $context = context_module::instance($cmid);

    // Save the feedback.
    $DB->delete_records('gnrquiz_feedback', array('gnrquizid' => $gnrquiz->id));

    for ($i = 0; $i <= $gnrquiz->feedbackboundarycount; $i++) {
        $feedback = new stdClass();
        $feedback->gnrquizid = $gnrquiz->id;
        $feedback->feedbacktext = $gnrquiz->feedbacktext[$i]['text'];
        $feedback->feedbacktextformat = $gnrquiz->feedbacktext[$i]['format'];
        $feedback->mingrade = $gnrquiz->feedbackboundaries[$i];
        $feedback->maxgrade = $gnrquiz->feedbackboundaries[$i - 1];
        $feedback->id = $DB->insert_record('gnrquiz_feedback', $feedback);
        $feedbacktext = file_save_draft_area_files((int)$gnrquiz->feedbacktext[$i]['itemid'],
                $context->id, 'mod_gnrquiz', 'feedback', $feedback->id,
                array('subdirs' => false, 'maxfiles' => -1, 'maxbytes' => 0),
                $gnrquiz->feedbacktext[$i]['text']);
        $DB->set_field('gnrquiz_feedback', 'feedbacktext', $feedbacktext,
                array('id' => $feedback->id));
    }

    // Store any settings belonging to the access rules.
    gnrquiz_access_manager::save_settings($gnrquiz);

    // Update the events relating to this gnrquiz.
    gnrquiz_update_events($gnrquiz);

    // Update related grade item.
    gnrquiz_grade_item_update($gnrquiz);
}

/**
 * This function updates the events associated to the gnrquiz.
 * If $override is non-zero, then it updates only the events
 * associated with the specified override.
 *
 * @uses GNRQUIZ_MAX_EVENT_LENGTH
 * @param object $gnrquiz the gnrquiz object.
 * @param object optional $override limit to a specific override
 */
function gnrquiz_update_events($gnrquiz, $override = null) {
    global $DB;

    // Load the old events relating to this gnrquiz.
    $conds = array('modulename'=>'gnrquiz',
                   'instance'=>$gnrquiz->id);
    if (!empty($override)) {
        // Only load events for this override.
        if (isset($override->userid)) {
            $conds['userid'] = $override->userid;
        } else if (isset($override->groupid)) {
            $conds['groupid'] = $override->groupid;
        }
    }
    $oldevents = $DB->get_records('event', $conds);

    // Now make a todo list of all that needs to be updated.
    if (empty($override)) {
        // We are updating the primary settings for the gnrquiz, so we
        // need to add all the overrides.
        $overrides = $DB->get_records('gnrquiz_overrides', array('gnrquiz' => $gnrquiz->id));
        // As well as the original gnrquiz (empty override).
        $overrides[] = new stdClass();
    } else {
        // Just do the one override.
        $overrides = array($override);
    }

    foreach ($overrides as $current) {
        $groupid   = isset($current->groupid)?  $current->groupid : 0;
        $userid    = isset($current->userid)? $current->userid : 0;
        $timeopen  = isset($current->timeopen)?  $current->timeopen : $gnrquiz->timeopen;
        $timeclose = isset($current->timeclose)? $current->timeclose : $gnrquiz->timeclose;

        // Only add open/close events for an override if they differ from the gnrquiz default.
        $addopen  = empty($current->id) || !empty($current->timeopen);
        $addclose = empty($current->id) || !empty($current->timeclose);

        if (!empty($gnrquiz->coursemodule)) {
            $cmid = $gnrquiz->coursemodule;
        } else {
            $cmid = get_coursemodule_from_instance('gnrquiz', $gnrquiz->id, $gnrquiz->course)->id;
        }

        $event = new stdClass();
        $event->description = format_module_intro('gnrquiz', $gnrquiz, $cmid);
        // Events module won't show user events when the courseid is nonzero.
        $event->courseid    = ($userid) ? 0 : $gnrquiz->course;
        $event->groupid     = $groupid;
        $event->userid      = $userid;
        $event->modulename  = 'gnrquiz';
        $event->instance    = $gnrquiz->id;
        $event->timestart   = $timeopen;
        $event->timeduration = max($timeclose - $timeopen, 0);
        $event->visible     = instance_is_visible('gnrquiz', $gnrquiz);
        $event->eventtype   = 'open';

        // Determine the event name.
        if ($groupid) {
            $params = new stdClass();
            $params->gnrquiz = $gnrquiz->name;
            $params->group = groups_get_group_name($groupid);
            if ($params->group === false) {
                // Group doesn't exist, just skip it.
                continue;
            }
            $eventname = get_string('overridegroupeventname', 'gnrquiz', $params);
        } else if ($userid) {
            $params = new stdClass();
            $params->gnrquiz = $gnrquiz->name;
            $eventname = get_string('overrideusereventname', 'gnrquiz', $params);
        } else {
            $eventname = $gnrquiz->name;
        }
        if ($addopen or $addclose) {
            if ($timeclose and $timeopen and $event->timeduration <= GNRQUIZ_MAX_EVENT_LENGTH) {
                // Single event for the whole gnrquiz.
                if ($oldevent = array_shift($oldevents)) {
                    $event->id = $oldevent->id;
                } else {
                    unset($event->id);
                }
                $event->name = $eventname;
                // The method calendar_event::create will reuse a db record if the id field is set.
                calendar_event::create($event);
            } else {
                // Separate start and end events.
                $event->timeduration  = 0;
                if ($timeopen && $addopen) {
                    if ($oldevent = array_shift($oldevents)) {
                        $event->id = $oldevent->id;
                    } else {
                        unset($event->id);
                    }
                    $event->name = $eventname.' ('.get_string('gnrquizopens', 'gnrquiz').')';
                    // The method calendar_event::create will reuse a db record if the id field is set.
                    calendar_event::create($event);
                }
                if ($timeclose && $addclose) {
                    if ($oldevent = array_shift($oldevents)) {
                        $event->id = $oldevent->id;
                    } else {
                        unset($event->id);
                    }
                    $event->name      = $eventname.' ('.get_string('gnrquizcloses', 'gnrquiz').')';
                    $event->timestart = $timeclose;
                    $event->eventtype = 'close';
                    calendar_event::create($event);
                }
            }
        }
    }

    // Delete any leftover events.
    foreach ($oldevents as $badevent) {
        $badevent = calendar_event::load($badevent);
        $badevent->delete();
    }
}

/**
 * List the actions that correspond to a view of this module.
 * This is used by the participation report.
 *
 * Note: This is not used by new logging system. Event with
 *       crud = 'r' and edulevel = LEVEL_PARTICIPATING will
 *       be considered as view action.
 *
 * @return array
 */
function gnrquiz_get_view_actions() {
    return array('view', 'view all', 'report', 'review');
}

/**
 * List the actions that correspond to a post of this module.
 * This is used by the participation report.
 *
 * Note: This is not used by new logging system. Event with
 *       crud = ('c' || 'u' || 'd') and edulevel = LEVEL_PARTICIPATING
 *       will be considered as post action.
 *
 * @return array
 */
function gnrquiz_get_post_actions() {
    return array('attempt', 'close attempt', 'preview', 'editquestions',
            'delete attempt', 'manualgrade');
}

/**
 * @param array $questionids of question ids.
 * @return bool whether any of these questions are used by any instance of this module.
 */
function gnrquiz_questions_in_use($questionids) {
    global $DB, $CFG;
    require_once($CFG->libdir . '/questionlib.php');
    list($test, $params) = $DB->get_in_or_equal($questionids);
    return $DB->record_exists_select('gnrquiz_slots',
            'questionid ' . $test, $params) || question_engine::questions_in_use(
            $questionids, new qubaid_join('{gnrquiz_attempts} gnrquiza',
            'gnrquiza.uniqueid', 'gnrquiza.preview = 0'));
}

/**
 * Implementation of the function for printing the form elements that control
 * whether the course reset functionality affects the gnrquiz.
 *
 * @param $mform the course reset form that is being built.
 */
function gnrquiz_reset_course_form_definition($mform) {
    $mform->addElement('header', 'gnrquizheader', get_string('modulenameplural', 'gnrquiz'));
    $mform->addElement('advcheckbox', 'reset_gnrquiz_attempts',
            get_string('removeallgnrquizattempts', 'gnrquiz'));
    $mform->addElement('advcheckbox', 'reset_gnrquiz_user_overrides',
            get_string('removealluseroverrides', 'gnrquiz'));
    $mform->addElement('advcheckbox', 'reset_gnrquiz_group_overrides',
            get_string('removeallgroupoverrides', 'gnrquiz'));
}

/**
 * Course reset form defaults.
 * @return array the defaults.
 */
function gnrquiz_reset_course_form_defaults($course) {
    return array('reset_gnrquiz_attempts' => 1,
                 'reset_gnrquiz_group_overrides' => 1,
                 'reset_gnrquiz_user_overrides' => 1);
}

/**
 * Removes all grades from gradebook
 *
 * @param int $courseid
 * @param string optional type
 */
function gnrquiz_reset_gradebook($courseid, $type='') {
    global $CFG, $DB;

    $gnrquizzes = $DB->get_records_sql("
            SELECT q.*, cm.idnumber as cmidnumber, q.course as courseid
            FROM {modules} m
            JOIN {course_modules} cm ON m.id = cm.module
            JOIN {gnrquiz} q ON cm.instance = q.id
            WHERE m.name = 'gnrquiz' AND cm.course = ?", array($courseid));

    foreach ($gnrquizzes as $gnrquiz) {
        gnrquiz_grade_item_update($gnrquiz, 'reset');
    }
}

/**
 * Actual implementation of the reset course functionality, delete all the
 * gnrquiz attempts for course $data->courseid, if $data->reset_gnrquiz_attempts is
 * set and true.
 *
 * Also, move the gnrquiz open and close dates, if the course start date is changing.
 *
 * @param object $data the data submitted from the reset course.
 * @return array status array
 */
function gnrquiz_reset_userdata($data) {
    global $CFG, $DB;
    require_once($CFG->libdir . '/questionlib.php');

    $componentstr = get_string('modulenameplural', 'gnrquiz');
    $status = array();

    // Delete attempts.
    if (!empty($data->reset_gnrquiz_attempts)) {
        question_engine::delete_questions_usage_by_activities(new qubaid_join(
                '{gnrquiz_attempts} gnrquiza JOIN {gnrquiz} gnrquiz ON gnrquiza.gnrquiz = gnrquiz.id',
                'gnrquiza.uniqueid', 'gnrquiz.course = :gnrquizcourseid',
                array('gnrquizcourseid' => $data->courseid)));

        $DB->delete_records_select('gnrquiz_attempts',
                'gnrquiz IN (SELECT id FROM {gnrquiz} WHERE course = ?)', array($data->courseid));
        $status[] = array(
            'component' => $componentstr,
            'item' => get_string('attemptsdeleted', 'gnrquiz'),
            'error' => false);

        // Remove all grades from gradebook.
        $DB->delete_records_select('gnrquiz_grades',
                'gnrquiz IN (SELECT id FROM {gnrquiz} WHERE course = ?)', array($data->courseid));
        if (empty($data->reset_gradebook_grades)) {
            gnrquiz_reset_gradebook($data->courseid);
        }
        $status[] = array(
            'component' => $componentstr,
            'item' => get_string('gradesdeleted', 'gnrquiz'),
            'error' => false);
    }

    // Remove user overrides.
    if (!empty($data->reset_gnrquiz_user_overrides)) {
        $DB->delete_records_select('gnrquiz_overrides',
                'gnrquiz IN (SELECT id FROM {gnrquiz} WHERE course = ?) AND userid IS NOT NULL', array($data->courseid));
        $status[] = array(
            'component' => $componentstr,
            'item' => get_string('useroverridesdeleted', 'gnrquiz'),
            'error' => false);
    }
    // Remove group overrides.
    if (!empty($data->reset_gnrquiz_group_overrides)) {
        $DB->delete_records_select('gnrquiz_overrides',
                'gnrquiz IN (SELECT id FROM {gnrquiz} WHERE course = ?) AND groupid IS NOT NULL', array($data->courseid));
        $status[] = array(
            'component' => $componentstr,
            'item' => get_string('groupoverridesdeleted', 'gnrquiz'),
            'error' => false);
    }

    // Updating dates - shift may be negative too.
    if ($data->timeshift) {
        $DB->execute("UPDATE {gnrquiz_overrides}
                         SET timeopen = timeopen + ?
                       WHERE gnrquiz IN (SELECT id FROM {gnrquiz} WHERE course = ?)
                         AND timeopen <> 0", array($data->timeshift, $data->courseid));
        $DB->execute("UPDATE {gnrquiz_overrides}
                         SET timeclose = timeclose + ?
                       WHERE gnrquiz IN (SELECT id FROM {gnrquiz} WHERE course = ?)
                         AND timeclose <> 0", array($data->timeshift, $data->courseid));

        shift_course_mod_dates('gnrquiz', array('timeopen', 'timeclose'),
                $data->timeshift, $data->courseid);

        $status[] = array(
            'component' => $componentstr,
            'item' => get_string('openclosedatesupdated', 'gnrquiz'),
            'error' => false);
    }

    return $status;
}

/**
 * Prints gnrquiz summaries on MyMoodle Page
 * @param arry $courses
 * @param array $htmlarray
 */
function gnrquiz_print_overview($courses, &$htmlarray) {
    global $USER, $CFG;
    // These next 6 Lines are constant in all modules (just change module name).
    if (empty($courses) || !is_array($courses) || count($courses) == 0) {
        return array();
    }

    if (!$gnrquizzes = get_all_instances_in_courses('gnrquiz', $courses)) {
        return;
    }

    // Get the gnrquizzes attempts.
    $attemptsinfo = [];
    $gnrquizids = [];
    foreach ($gnrquizzes as $gnrquiz) {
        $gnrquizids[] = $gnrquiz->id;
        $attemptsinfo[$gnrquiz->id] = ['count' => 0, 'hasfinished' => false];
    }
    $attempts = gnrquiz_get_user_attempts($gnrquizids, $USER->id);
    foreach ($attempts as $attempt) {
        $attemptsinfo[$attempt->gnrquiz]['count']++;
        $attemptsinfo[$attempt->gnrquiz]['hasfinished'] = true;
    }
    unset($attempts);

    // Fetch some language strings outside the main loop.
    $strgnrquiz = get_string('modulename', 'gnrquiz');
    $strnoattempts = get_string('noattempts', 'gnrquiz');

    // We want to list gnrquizzes that are currently available, and which have a close date.
    // This is the same as what the lesson does, and the dabate is in MDL-10568.
    $now = time();
    foreach ($gnrquizzes as $gnrquiz) {
        if ($gnrquiz->timeclose >= $now && $gnrquiz->timeopen < $now) {
            $str = '';

            // Now provide more information depending on the uers's role.
            $context = context_module::instance($gnrquiz->coursemodule);
            if (has_capability('mod/gnrquiz:viewreports', $context)) {
                // For teacher-like people, show a summary of the number of student attempts.
                // The $gnrquiz objects returned by get_all_instances_in_course have the necessary $cm
                // fields set to make the following call work.
                $str .= '<div class="info">' . gnrquiz_num_attempt_summary($gnrquiz, $gnrquiz, true) . '</div>';

            } else if (has_any_capability(array('mod/gnrquiz:reviewmyattempts', 'mod/gnrquiz:attempt'), $context)) { // Student
                // For student-like people, tell them how many attempts they have made.

                if (isset($USER->id)) {
                    if ($attemptsinfo[$gnrquiz->id]['hasfinished']) {
                        // The student's last attempt is finished.
                        continue;
                    }

                    if ($attemptsinfo[$gnrquiz->id]['count'] > 0) {
                        $str .= '<div class="info">' .
                            get_string('numattemptsmade', 'gnrquiz', $attemptsinfo[$gnrquiz->id]['count']) . '</div>';
                    } else {
                        $str .= '<div class="info">' . $strnoattempts . '</div>';
                    }

                } else {
                    $str .= '<div class="info">' . $strnoattempts . '</div>';
                }

            } else {
                // For ayone else, there is no point listing this gnrquiz, so stop processing.
                continue;
            }

            // Give a link to the gnrquiz, and the deadline.
            $html = '<div class="gnrquiz overview">' .
                    '<div class="name">' . $strgnrquiz . ': <a ' .
                    ($gnrquiz->visible ? '' : ' class="dimmed"') .
                    ' href="' . $CFG->wwwroot . '/mod/gnrquiz/view.php?id=' .
                    $gnrquiz->coursemodule . '">' .
                    $gnrquiz->name . '</a></div>';
            $html .= '<div class="info">' . get_string('gnrquizcloseson', 'gnrquiz',
                    userdate($gnrquiz->timeclose)) . '</div>';
            $html .= $str;
            $html .= '</div>';
            if (empty($htmlarray[$gnrquiz->course]['gnrquiz'])) {
                $htmlarray[$gnrquiz->course]['gnrquiz'] = $html;
            } else {
                $htmlarray[$gnrquiz->course]['gnrquiz'] .= $html;
            }
        }
    }
}

/**
 * Return a textual summary of the number of attempts that have been made at a particular gnrquiz,
 * returns '' if no attempts have been made yet, unless $returnzero is passed as true.
 *
 * @param object $gnrquiz the gnrquiz object. Only $gnrquiz->id is used at the moment.
 * @param object $cm the cm object. Only $cm->course, $cm->groupmode and
 *      $cm->groupingid fields are used at the moment.
 * @param bool $returnzero if false (default), when no attempts have been
 *      made '' is returned instead of 'Attempts: 0'.
 * @param int $currentgroup if there is a concept of current group where this method is being called
 *         (e.g. a report) pass it in here. Default 0 which means no current group.
 * @return string a string like "Attempts: 123", "Attemtps 123 (45 from your groups)" or
 *          "Attemtps 123 (45 from this group)".
 */
function gnrquiz_num_attempt_summary($gnrquiz, $cm, $returnzero = false, $currentgroup = 0) {
    global $DB, $USER;
    $numattempts = $DB->count_records('gnrquiz_attempts', array('gnrquiz'=> $gnrquiz->id, 'preview'=>0));
    if ($numattempts || $returnzero) {
        if (groups_get_activity_groupmode($cm)) {
            $a = new stdClass();
            $a->total = $numattempts;
            if ($currentgroup) {
                $a->group = $DB->count_records_sql('SELECT COUNT(DISTINCT qa.id) FROM ' .
                        '{gnrquiz_attempts} qa JOIN ' .
                        '{groups_members} gm ON qa.userid = gm.userid ' .
                        'WHERE gnrquiz = ? AND preview = 0 AND groupid = ?',
                        array($gnrquiz->id, $currentgroup));
                return get_string('attemptsnumthisgroup', 'gnrquiz', $a);
            } else if ($groups = groups_get_all_groups($cm->course, $USER->id, $cm->groupingid)) {
                list($usql, $params) = $DB->get_in_or_equal(array_keys($groups));
                $a->group = $DB->count_records_sql('SELECT COUNT(DISTINCT qa.id) FROM ' .
                        '{gnrquiz_attempts} qa JOIN ' .
                        '{groups_members} gm ON qa.userid = gm.userid ' .
                        'WHERE gnrquiz = ? AND preview = 0 AND ' .
                        "groupid $usql", array_merge(array($gnrquiz->id), $params));
                return get_string('attemptsnumyourgroups', 'gnrquiz', $a);
            }
        }
        return get_string('attemptsnum', 'gnrquiz', $numattempts);
    }
    return '';
}

/**
 * Returns the same as {@link gnrquiz_num_attempt_summary()} but wrapped in a link
 * to the gnrquiz reports.
 *
 * @param object $gnrquiz the gnrquiz object. Only $gnrquiz->id is used at the moment.
 * @param object $cm the cm object. Only $cm->course, $cm->groupmode and
 *      $cm->groupingid fields are used at the moment.
 * @param object $context the gnrquiz context.
 * @param bool $returnzero if false (default), when no attempts have been made
 *      '' is returned instead of 'Attempts: 0'.
 * @param int $currentgroup if there is a concept of current group where this method is being called
 *         (e.g. a report) pass it in here. Default 0 which means no current group.
 * @return string HTML fragment for the link.
 */
function gnrquiz_attempt_summary_link_to_reports($gnrquiz, $cm, $context, $returnzero = false,
        $currentgroup = 0) {
    global $CFG;
    $summary = gnrquiz_num_attempt_summary($gnrquiz, $cm, $returnzero, $currentgroup);
    if (!$summary) {
        return '';
    }

    require_once($CFG->dirroot . '/mod/gnrquiz/report/reportlib.php');
    $url = new moodle_url('/mod/gnrquiz/report.php', array(
            'id' => $cm->id, 'mode' => gnrquiz_report_default_report($context)));
    return html_writer::link($url, $summary);
}

/**
 * @param string $feature FEATURE_xx constant for requested feature
 * @return bool True if gnrquiz supports feature
 */
function gnrquiz_supports($feature) {
    switch($feature) {
        case FEATURE_GROUPS:                    return true;
        case FEATURE_GROUPINGS:                 return true;
        case FEATURE_MOD_INTRO:                 return true;
        case FEATURE_COMPLETION_TRACKS_VIEWS:   return true;
        case FEATURE_COMPLETION_HAS_RULES:      return true;
        case FEATURE_GRADE_HAS_GRADE:           return true;
        case FEATURE_GRADE_OUTCOMES:            return true;
        case FEATURE_BACKUP_MOODLE2:            return true;
        case FEATURE_SHOW_DESCRIPTION:          return true;
        case FEATURE_CONTROLS_GRADE_VISIBILITY: return true;
        case FEATURE_USES_QUESTIONS:            return true;

        default: return null;
    }
}

/**
 * @return array all other caps used in module
 */
function gnrquiz_get_extra_capabilities() {
    global $CFG;
    require_once($CFG->libdir . '/questionlib.php');
    $caps = question_get_all_capabilities();
    $caps[] = 'moodle/site:accessallgroups';
    return $caps;
}

/**
 * This function extends the settings navigation block for the site.
 *
 * It is safe to rely on PAGE here as we will only ever be within the module
 * context when this is called
 *
 * @param settings_navigation $settings
 * @param navigation_node $gnrquiznode
 * @return void
 */
function gnrquiz_extend_settings_navigation($settings, $gnrquiznode) {
    global $PAGE, $CFG;

    // Require {@link questionlib.php}
    // Included here as we only ever want to include this file if we really need to.
    require_once($CFG->libdir . '/questionlib.php');

    // We want to add these new nodes after the Edit settings node, and before the
    // Locally assigned roles node. Of course, both of those are controlled by capabilities.
    $keys = $gnrquiznode->get_children_key_list();
    $beforekey = null;
    $i = array_search('modedit', $keys);
    if ($i === false and array_key_exists(0, $keys)) {
        $beforekey = $keys[0];
    } else if (array_key_exists($i + 1, $keys)) {
        $beforekey = $keys[$i + 1];
    }

    if (has_capability('mod/gnrquiz:manageoverrides', $PAGE->cm->context)) {
        $url = new moodle_url('/mod/gnrquiz/overrides.php', array('cmid'=>$PAGE->cm->id));
        $node = navigation_node::create(get_string('groupoverrides', 'gnrquiz'),
                new moodle_url($url, array('mode'=>'group')),
                navigation_node::TYPE_SETTING, null, 'mod_gnrquiz_groupoverrides');
        $gnrquiznode->add_node($node, $beforekey);

        $node = navigation_node::create(get_string('useroverrides', 'gnrquiz'),
                new moodle_url($url, array('mode'=>'user')),
                navigation_node::TYPE_SETTING, null, 'mod_gnrquiz_useroverrides');
        $gnrquiznode->add_node($node, $beforekey);
    }

    if (has_capability('mod/gnrquiz:manage', $PAGE->cm->context)) {
        $node = navigation_node::create(get_string('editgnrquiz', 'gnrquiz'),
                new moodle_url('/mod/gnrquiz/edit.php', array('cmid'=>$PAGE->cm->id)),
                navigation_node::TYPE_SETTING, null, 'mod_gnrquiz_edit',
                new pix_icon('t/edit', ''));
        $gnrquiznode->add_node($node, $beforekey);
    }

    if (has_capability('mod/gnrquiz:preview', $PAGE->cm->context)) {
        $url = new moodle_url('/mod/gnrquiz/startattempt.php',
                array('cmid'=>$PAGE->cm->id, 'sesskey'=>sesskey()));
        $node = navigation_node::create(get_string('preview', 'gnrquiz'), $url,
                navigation_node::TYPE_SETTING, null, 'mod_gnrquiz_preview',
                new pix_icon('i/preview', ''));
        $gnrquiznode->add_node($node, $beforekey);
    }

    if (has_any_capability(array('mod/gnrquiz:viewreports', 'mod/gnrquiz:grade'), $PAGE->cm->context)) {
        require_once($CFG->dirroot . '/mod/gnrquiz/report/reportlib.php');
        $reportlist = gnrquiz_report_list($PAGE->cm->context);

        $url = new moodle_url('/mod/gnrquiz/report.php',
                array('id' => $PAGE->cm->id, 'mode' => reset($reportlist)));
        $reportnode = $gnrquiznode->add_node(navigation_node::create(get_string('results', 'gnrquiz'), $url,
                navigation_node::TYPE_SETTING,
                null, null, new pix_icon('i/report', '')), $beforekey);

        foreach ($reportlist as $report) {
            $url = new moodle_url('/mod/gnrquiz/report.php',
                    array('id' => $PAGE->cm->id, 'mode' => $report));
            $reportnode->add_node(navigation_node::create(get_string($report, 'gnrquiz_'.$report), $url,
                    navigation_node::TYPE_SETTING,
                    null, 'gnrquiz_report_' . $report, new pix_icon('i/item', '')));
        }
    }

    question_extend_settings_navigation($gnrquiznode, $PAGE->cm->context)->trim_if_empty();
}

/**
 * Serves the gnrquiz files.
 *
 * @package  mod_gnrquiz
 * @category files
 * @param stdClass $course course object
 * @param stdClass $cm course module object
 * @param stdClass $context context object
 * @param string $filearea file area
 * @param array $args extra arguments
 * @param bool $forcedownload whether or not force download
 * @param array $options additional options affecting the file serving
 * @return bool false if file not found, does not return if found - justsend the file
 */
function gnrquiz_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options=array()) {
    global $CFG, $DB;

    if ($context->contextlevel != CONTEXT_MODULE) {
        return false;
    }

    require_login($course, false, $cm);

    if (!$gnrquiz = $DB->get_record('gnrquiz', array('id'=>$cm->instance))) {
        return false;
    }

    // The 'intro' area is served by pluginfile.php.
    $fileareas = array('feedback');
    if (!in_array($filearea, $fileareas)) {
        return false;
    }

    $feedbackid = (int)array_shift($args);
    if (!$feedback = $DB->get_record('gnrquiz_feedback', array('id'=>$feedbackid))) {
        return false;
    }

    $fs = get_file_storage();
    $relativepath = implode('/', $args);
    $fullpath = "/$context->id/mod_gnrquiz/$filearea/$feedbackid/$relativepath";
    if (!$file = $fs->get_file_by_hash(sha1($fullpath)) or $file->is_directory()) {
        return false;
    }
    send_stored_file($file, 0, 0, true, $options);
}

/**
 * Called via pluginfile.php -> question_pluginfile to serve files belonging to
 * a question in a question_attempt when that attempt is a gnrquiz attempt.
 *
 * @package  mod_gnrquiz
 * @category files
 * @param stdClass $course course settings object
 * @param stdClass $context context object
 * @param string $component the name of the component we are serving files for.
 * @param string $filearea the name of the file area.
 * @param int $qubaid the attempt usage id.
 * @param int $slot the id of a question in this gnrquiz attempt.
 * @param array $args the remaining bits of the file path.
 * @param bool $forcedownload whether the user must be forced to download the file.
 * @param array $options additional options affecting the file serving
 * @return bool false if file not found, does not return if found - justsend the file
 */
function gnrquiz_question_pluginfile($course, $context, $component,
        $filearea, $qubaid, $slot, $args, $forcedownload, array $options=array()) {
    global $CFG;
    require_once($CFG->dirroot . '/mod/gnrquiz/locallib.php');

    $attemptobj = gnrquiz_attempt::create_from_usage_id($qubaid);
    require_login($attemptobj->get_course(), false, $attemptobj->get_cm());

    if ($attemptobj->is_own_attempt() && !$attemptobj->is_finished()) {
        // In the middle of an attempt.
        if (!$attemptobj->is_preview_user()) {
            $attemptobj->require_capability('mod/gnrquiz:attempt');
        }
        $isreviewing = false;

    } else {
        // Reviewing an attempt.
        $attemptobj->check_review_capability();
        $isreviewing = true;
    }

    if (!$attemptobj->check_file_access($slot, $isreviewing, $context->id,
            $component, $filearea, $args, $forcedownload)) {
        send_file_not_found();
    }

    $fs = get_file_storage();
    $relativepath = implode('/', $args);
    $fullpath = "/$context->id/$component/$filearea/$relativepath";
    if (!$file = $fs->get_file_by_hash(sha1($fullpath)) or $file->is_directory()) {
        send_file_not_found();
    }

    send_stored_file($file, 0, 0, $forcedownload, $options);
}

/**
 * Return a list of page types
 * @param string $pagetype current page type
 * @param stdClass $parentcontext Block's parent context
 * @param stdClass $currentcontext Current context of block
 */
function gnrquiz_page_type_list($pagetype, $parentcontext, $currentcontext) {
    $module_pagetype = array(
        'mod-gnrquiz-*'       => get_string('page-mod-gnrquiz-x', 'gnrquiz'),
        'mod-gnrquiz-view'    => get_string('page-mod-gnrquiz-view', 'gnrquiz'),
        'mod-gnrquiz-attempt' => get_string('page-mod-gnrquiz-attempt', 'gnrquiz'),
        'mod-gnrquiz-summary' => get_string('page-mod-gnrquiz-summary', 'gnrquiz'),
        'mod-gnrquiz-review'  => get_string('page-mod-gnrquiz-review', 'gnrquiz'),
        'mod-gnrquiz-edit'    => get_string('page-mod-gnrquiz-edit', 'gnrquiz'),
        'mod-gnrquiz-report'  => get_string('page-mod-gnrquiz-report', 'gnrquiz'),
    );
    return $module_pagetype;
}

/**
 * @return the options for gnrquiz navigation.
 */
function gnrquiz_get_navigation_options() {
    return array(
        GNRQUIZ_NAVMETHOD_FREE => get_string('navmethod_free', 'gnrquiz'),
        GNRQUIZ_NAVMETHOD_SEQ  => get_string('navmethod_seq', 'gnrquiz')
    );
}

/**
 * Obtains the automatic completion state for this gnrquiz on any conditions
 * in gnrquiz settings, such as if all attempts are used or a certain grade is achieved.
 *
 * @param object $course Course
 * @param object $cm Course-module
 * @param int $userid User ID
 * @param bool $type Type of comparison (or/and; can be used as return value if no conditions)
 * @return bool True if completed, false if not. (If no conditions, then return
 *   value depends on comparison type)
 */
function gnrquiz_get_completion_state($course, $cm, $userid, $type) {
    global $DB;
    global $CFG;

    $gnrquiz = $DB->get_record('gnrquiz', array('id' => $cm->instance), '*', MUST_EXIST);
    if (!$gnrquiz->completionattemptsexhausted && !$gnrquiz->completionpass) {
        return $type;
    }

    // Check if the user has used up all attempts.
    if ($gnrquiz->completionattemptsexhausted) {
        $attempts = gnrquiz_get_user_attempts($gnrquiz->id, $userid, 'finished', true);
        if ($attempts) {
            $lastfinishedattempt = end($attempts);
            $context = context_module::instance($cm->id);
            $gnrquizobj = gnrquiz::create($gnrquiz->id, $userid);
            $accessmanager = new gnrquiz_access_manager($gnrquizobj, time(),
                    has_capability('mod/gnrquiz:ignoretimelimits', $context, $userid, false));
            if ($accessmanager->is_finished(count($attempts), $lastfinishedattempt)) {
                return true;
            }
        }
    }

    // Check for passing grade.
    if ($gnrquiz->completionpass) {
        require_once($CFG->libdir . '/gradelib.php');
        $item = grade_item::fetch(array('courseid' => $course->id, 'itemtype' => 'mod',
                'itemmodule' => 'gnrquiz', 'iteminstance' => $cm->instance, 'outcomeid' => null));
        if ($item) {
            $grades = grade_grade::fetch_users_grades($item, array($userid), false);
            if (!empty($grades[$userid])) {
                return $grades[$userid]->is_passed($item);
            }
        }
    }
    return false;
}
