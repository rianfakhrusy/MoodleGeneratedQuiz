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
 * Library of functions used by the gnrquiz module.
 *
 * This contains functions that are called from within the gnrquiz module only
 * Functions that are also called by core Moodle are in {@link lib.php}
 * This script also loads the code in {@link questionlib.php} which holds
 * the module-indpendent code for handling questions and which in turn
 * initialises all the questiontype classes.
 *
 * @package    mod_gnrquiz
 * @copyright  1999 onwards Martin Dougiamas and others {@link http://moodle.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/gnrquiz/lib.php');
require_once($CFG->dirroot . '/mod/gnrquiz/accessmanager.php');
require_once($CFG->dirroot . '/mod/gnrquiz/accessmanager_form.php');
require_once($CFG->dirroot . '/mod/gnrquiz/renderer.php');
require_once($CFG->dirroot . '/mod/gnrquiz/attemptlib.php');
require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->libdir . '/eventslib.php');
require_once($CFG->libdir . '/filelib.php');
require_once($CFG->libdir . '/questionlib.php');


/**
 * @var int We show the countdown timer if there is less than this amount of time left before the
 * the gnrquiz close date. (1 hour)
 */
define('GNRQUIZ_SHOW_TIME_BEFORE_DEADLINE', '3600');

/**
 * @var int If there are fewer than this many seconds left when the student submits
 * a page of the gnrquiz, then do not take them to the next page of the gnrquiz. Instead
 * close the gnrquiz immediately.
 */
define('GNRQUIZ_MIN_TIME_TO_CONTINUE', '2');

/**
 * @var int We show no image when user selects No image from dropdown menu in gnrquiz settings.
 */
define('GNRQUIZ_SHOWIMAGE_NONE', 0);

/**
 * @var int We show small image when user selects small image from dropdown menu in gnrquiz settings.
 */
define('GNRQUIZ_SHOWIMAGE_SMALL', 1);

/**
 * @var int We show Large image when user selects Large image from dropdown menu in gnrquiz settings.
 */
define('GNRQUIZ_SHOWIMAGE_LARGE', 2);


// Functions related to attempts ///////////////////////////////////////////////

/**
 * Creates an object to represent a new attempt at a gnrquiz
 *
 * Creates an attempt object to represent an attempt at the gnrquiz by the current
 * user starting at the current time. The ->id field is not set. The object is
 * NOT written to the database.
 *
 * @param object $gnrquizobj the gnrquiz object to create an attempt for.
 * @param int $attemptnumber the sequence number for the attempt.
 * @param object $lastattempt the previous attempt by this user, if any. Only needed
 *         if $attemptnumber > 1 and $gnrquiz->attemptonlast is true.
 * @param int $timenow the time the attempt was started at.
 * @param bool $ispreview whether this new attempt is a preview.
 * @param int $userid  the id of the user attempting this gnrquiz.
 *
 * @return object the newly created attempt object.
 */
function gnrquiz_create_attempt(gnrquiz $gnrquizobj, $attemptnumber, $lastattempt, $timenow, $ispreview = false, $userid = null) {
    global $USER;

    if ($userid === null) {
        $userid = $USER->id;
    }

    $gnrquiz = $gnrquizobj->get_gnrquiz();
    if ($gnrquiz->sumgrades < 0.000005 && $gnrquiz->grade > 0.000005) {
        throw new moodle_exception('cannotstartgradesmismatch', 'gnrquiz',
                new moodle_url('/mod/gnrquiz/view.php', array('q' => $gnrquiz->id)),
                    array('grade' => gnrquiz_format_grade($gnrquiz, $gnrquiz->grade)));
    }

    if ($attemptnumber == 1 || !$gnrquiz->attemptonlast) {
        // We are not building on last attempt so create a new attempt.
        $attempt = new stdClass();
        $attempt->gnrquiz = $gnrquiz->id;
        $attempt->userid = $userid;
        $attempt->preview = 0;
        $attempt->layout = '';
    } else {
        // Build on last attempt.
        if (empty($lastattempt)) {
            print_error('cannotfindprevattempt', 'gnrquiz');
        }
        $attempt = $lastattempt;
    }

    $attempt->attempt = $attemptnumber;
    $attempt->timestart = $timenow;
    $attempt->timefinish = 0;
    $attempt->timemodified = $timenow;
    $attempt->state = gnrquiz_attempt::IN_PROGRESS;
    $attempt->currentpage = 0;
    $attempt->sumgrades = null;

    // If this is a preview, mark it as such.
    if ($ispreview) {
        $attempt->preview = 1;
    }

    $timeclose = $gnrquizobj->get_access_manager($timenow)->get_end_time($attempt);
    if ($timeclose === false || $ispreview) {
        $attempt->timecheckstate = null;
    } else {
        $attempt->timecheckstate = $timeclose;
    }

    return $attempt;
}
/**
 * Start a normal, new, gnrquiz attempt.
 *
 * @param gnrquiz      $gnrquizobj            the gnrquiz object to start an attempt for.
 * @param question_usage_by_activity $quba
 * @param object    $attempt
 * @param integer   $attemptnumber      starting from 1
 * @param integer   $timenow            the attempt start time
 * @param array     $questionids        slot number => question id. Used for random questions, to force the choice
 *                                        of a particular actual question. Intended for testing purposes only.
 * @param array     $forcedvariantsbyslot slot number => variant. Used for questions with variants,
 *                                          to force the choice of a particular variant. Intended for testing
 *                                          purposes only.
 * @throws moodle_exception
 * @return object   modified attempt object
 */
function gnrquiz_start_new_attempt($gnrquizobj, $quba, $attempt, $attemptnumber, $timenow,
                                $questionids = array(), $forcedvariantsbyslot = array()) {

    // Usages for this user's previous gnrquiz attempts.
    $qubaids = new \mod_gnrquiz\question\qubaids_for_users_attempts(
            $gnrquizobj->get_gnrquizid(), $attempt->userid);

    // Fully load all the questions in this gnrquiz.
    $gnrquizobj->preload_questions();
    $gnrquizobj->load_questions();

    // First load all the non-random questions.
    $randomfound = false;
    $slot = 0;
    $questions = array();
    $maxmark = array();
    $page = array();
    foreach ($gnrquizobj->get_questions() as $questiondata) {
        $slot += 1;
        $maxmark[$slot] = $questiondata->maxmark;
        $page[$slot] = $questiondata->page;
        if ($questiondata->qtype == 'random') {
            $randomfound = true;
            continue;
        }
        if (!$gnrquizobj->get_gnrquiz()->shuffleanswers) {
            $questiondata->options->shuffleanswers = false;
        }
        $questions[$slot] = question_bank::make_question($questiondata);
    }

    // Then find a question to go in place of each random question.
    if ($randomfound) {
        $slot = 0;
        $usedquestionids = array();
        foreach ($questions as $question) {
            if (isset($usedquestions[$question->id])) {
                $usedquestionids[$question->id] += 1;
            } else {
                $usedquestionids[$question->id] = 1;
            }
        }
        $randomloader = new \core_question\bank\random_question_loader($qubaids, $usedquestionids);

        foreach ($gnrquizobj->get_questions() as $questiondata) {
            $slot += 1;
            if ($questiondata->qtype != 'random') {
                continue;
            }

            // Deal with fixed random choices for testing.
            if (isset($questionids[$quba->next_slot_number()])) {
                if ($randomloader->is_question_available($questiondata->category,
                        (bool) $questiondata->questiontext, $questionids[$quba->next_slot_number()])) {
                    $questions[$slot] = question_bank::load_question(
                            $questionids[$quba->next_slot_number()], $gnrquizobj->get_gnrquiz()->shuffleanswers);
                    continue;
                } else {
                    throw new coding_exception('Forced question id not available.');
                }
            }

            // Normal case, pick one at random.
            $questionid = $randomloader->get_next_question_id($questiondata->category,
                        (bool) $questiondata->questiontext);
            if ($questionid === null) {
                throw new moodle_exception('notenoughrandomquestions', 'gnrquiz',
                                           $gnrquizobj->view_url(), $questiondata);
            }

            $questions[$slot] = question_bank::load_question($questionid,
                    $gnrquizobj->get_gnrquiz()->shuffleanswers);
        }
    }

    // Finally add them all to the usage.
    ksort($questions);
    foreach ($questions as $slot => $question) {
        $newslot = $quba->add_question($question, $maxmark[$slot]);
        if ($newslot != $slot) {
            throw new coding_exception('Slot numbers have got confused.');
        }
    }

    // Start all the questions.
    $variantstrategy = new core_question\engine\variants\least_used_strategy($quba, $qubaids);

    if (!empty($forcedvariantsbyslot)) {
        $forcedvariantsbyseed = question_variant_forced_choices_selection_strategy::prepare_forced_choices_array(
            $forcedvariantsbyslot, $quba);
        $variantstrategy = new question_variant_forced_choices_selection_strategy(
            $forcedvariantsbyseed, $variantstrategy);
    }

    $quba->start_all_questions($variantstrategy, $timenow);

    // Work out the attempt layout.
    $sections = $gnrquizobj->get_sections();
    foreach ($sections as $i => $section) {
        if (isset($sections[$i + 1])) {
            $sections[$i]->lastslot = $sections[$i + 1]->firstslot - 1;
        } else {
            $sections[$i]->lastslot = count($questions);
        }
    }

    $layout = array();
    foreach ($sections as $section) {
        if ($section->shufflequestions) {
            $questionsinthissection = array();
            for ($slot = $section->firstslot; $slot <= $section->lastslot; $slot += 1) {
                $questionsinthissection[] = $slot;
            }
            shuffle($questionsinthissection);
            $questionsonthispage = 0;
            foreach ($questionsinthissection as $slot) {
                if ($questionsonthispage && $questionsonthispage == $gnrquizobj->get_gnrquiz()->questionsperpage) {
                    $layout[] = 0;
                    $questionsonthispage = 0;
                }
                $layout[] = $slot;
                $questionsonthispage += 1;
            }

        } else {
            $currentpage = $page[$section->firstslot];
            for ($slot = $section->firstslot; $slot <= $section->lastslot; $slot += 1) {
                if ($currentpage !== null && $page[$slot] != $currentpage) {
                    $layout[] = 0;
                }
                $layout[] = $slot;
                $currentpage = $page[$slot];
            }
        }

        // Each section ends with a page break.
        $layout[] = 0;
    }
    $attempt->layout = implode(',', $layout);

    return $attempt;
}

/**
 * Start a subsequent new attempt, in each attempt builds on last mode.
 *
 * @param question_usage_by_activity    $quba         this question usage
 * @param object                        $attempt      this attempt
 * @param object                        $lastattempt  last attempt
 * @return object                       modified attempt object
 *
 */
function gnrquiz_start_attempt_built_on_last($quba, $attempt, $lastattempt) {
    $oldquba = question_engine::load_questions_usage_by_activity($lastattempt->uniqueid);

    $oldnumberstonew = array();
    foreach ($oldquba->get_attempt_iterator() as $oldslot => $oldqa) {
        $newslot = $quba->add_question($oldqa->get_question(), $oldqa->get_max_mark());

        $quba->start_question_based_on($newslot, $oldqa);

        $oldnumberstonew[$oldslot] = $newslot;
    }

    // Update attempt layout.
    $newlayout = array();
    foreach (explode(',', $lastattempt->layout) as $oldslot) {
        if ($oldslot != 0) {
            $newlayout[] = $oldnumberstonew[$oldslot];
        } else {
            $newlayout[] = 0;
        }
    }
    $attempt->layout = implode(',', $newlayout);
    return $attempt;
}

/**
 * The save started question usage and gnrquiz attempt in db and log the started attempt.
 *
 * @param gnrquiz                       $gnrquizobj
 * @param question_usage_by_activity $quba
 * @param object                     $attempt
 * @return object                    attempt object with uniqueid and id set.
 */
function gnrquiz_attempt_save_started($gnrquizobj, $quba, $attempt) {
    global $DB;
    // Save the attempt in the database.
    question_engine::save_questions_usage_by_activity($quba);
    $attempt->uniqueid = $quba->get_id();
    $attempt->id = $DB->insert_record('gnrquiz_attempts', $attempt);

    // Params used by the events below.
    $params = array(
        'objectid' => $attempt->id,
        'relateduserid' => $attempt->userid,
        'courseid' => $gnrquizobj->get_courseid(),
        'context' => $gnrquizobj->get_context()
    );
    // Decide which event we are using.
    if ($attempt->preview) {
        $params['other'] = array(
            'gnrquizid' => $gnrquizobj->get_gnrquizid()
        );
        $event = \mod_gnrquiz\event\attempt_preview_started::create($params);
    } else {
        $event = \mod_gnrquiz\event\attempt_started::create($params);

    }

    // Trigger the event.
    $event->add_record_snapshot('gnrquiz', $gnrquizobj->get_gnrquiz());
    $event->add_record_snapshot('gnrquiz_attempts', $attempt);
    $event->trigger();

    return $attempt;
}

/**
 * Returns an unfinished attempt (if there is one) for the given
 * user on the given gnrquiz. This function does not return preview attempts.
 *
 * @param int $gnrquizid the id of the gnrquiz.
 * @param int $userid the id of the user.
 *
 * @return mixed the unfinished attempt if there is one, false if not.
 */
function gnrquiz_get_user_attempt_unfinished($gnrquizid, $userid) {
    $attempts = gnrquiz_get_user_attempts($gnrquizid, $userid, 'unfinished', true);
    if ($attempts) {
        return array_shift($attempts);
    } else {
        return false;
    }
}

/**
 * Delete a gnrquiz attempt.
 * @param mixed $attempt an integer attempt id or an attempt object
 *      (row of the gnrquiz_attempts table).
 * @param object $gnrquiz the gnrquiz object.
 */
function gnrquiz_delete_attempt($attempt, $gnrquiz) {
    global $DB;
    if (is_numeric($attempt)) {
        if (!$attempt = $DB->get_record('gnrquiz_attempts', array('id' => $attempt))) {
            return;
        }
    }

    if ($attempt->gnrquiz != $gnrquiz->id) {
        debugging("Trying to delete attempt $attempt->id which belongs to gnrquiz $attempt->gnrquiz " .
                "but was passed gnrquiz $gnrquiz->id.");
        return;
    }

    if (!isset($gnrquiz->cmid)) {
        $cm = get_coursemodule_from_instance('gnrquiz', $gnrquiz->id, $gnrquiz->course);
        $gnrquiz->cmid = $cm->id;
    }

    question_engine::delete_questions_usage_by_activity($attempt->uniqueid);
    $DB->delete_records('gnrquiz_attempts', array('id' => $attempt->id));

    // Log the deletion of the attempt if not a preview.
    if (!$attempt->preview) {
        $params = array(
            'objectid' => $attempt->id,
            'relateduserid' => $attempt->userid,
            'context' => context_module::instance($gnrquiz->cmid),
            'other' => array(
                'gnrquizid' => $gnrquiz->id
            )
        );
        $event = \mod_gnrquiz\event\attempt_deleted::create($params);
        $event->add_record_snapshot('gnrquiz_attempts', $attempt);
        $event->trigger();
    }

    // Search gnrquiz_attempts for other instances by this user.
    // If none, then delete record for this gnrquiz, this user from gnrquiz_grades
    // else recalculate best grade.
    $userid = $attempt->userid;
    if (!$DB->record_exists('gnrquiz_attempts', array('userid' => $userid, 'gnrquiz' => $gnrquiz->id))) {
        $DB->delete_records('gnrquiz_grades', array('userid' => $userid, 'gnrquiz' => $gnrquiz->id));
    } else {
        gnrquiz_save_best_grade($gnrquiz, $userid);
    }

    gnrquiz_update_grades($gnrquiz, $userid);
}

/**
 * Delete all the preview attempts at a gnrquiz, or possibly all the attempts belonging
 * to one user.
 * @param object $gnrquiz the gnrquiz object.
 * @param int $userid (optional) if given, only delete the previews belonging to this user.
 */
function gnrquiz_delete_previews($gnrquiz, $userid = null) {
    global $DB;
    $conditions = array('gnrquiz' => $gnrquiz->id, 'preview' => 1);
    if (!empty($userid)) {
        $conditions['userid'] = $userid;
    }
    $previewattempts = $DB->get_records('gnrquiz_attempts', $conditions);
    foreach ($previewattempts as $attempt) {
        gnrquiz_delete_attempt($attempt, $gnrquiz);
    }
}

/**
 * @param int $gnrquizid The gnrquiz id.
 * @return bool whether this gnrquiz has any (non-preview) attempts.
 */
function gnrquiz_has_attempts($gnrquizid) {
    global $DB;
    return $DB->record_exists('gnrquiz_attempts', array('gnrquiz' => $gnrquizid, 'preview' => 0));
}

// Functions to do with gnrquiz layout and pages //////////////////////////////////

/**
 * Repaginate the questions in a gnrquiz
 * @param int $gnrquizid the id of the gnrquiz to repaginate.
 * @param int $slotsperpage number of items to put on each page. 0 means unlimited.
 */
function gnrquiz_repaginate_questions($gnrquizid, $slotsperpage) {
    global $DB;
    $trans = $DB->start_delegated_transaction();

    $sections = $DB->get_records('gnrquiz_sections', array('gnrquizid' => $gnrquizid), 'firstslot ASC');
    $firstslots = array();
    foreach ($sections as $section) {
        if ((int)$section->firstslot === 1) {
            continue;
        }
        $firstslots[] = $section->firstslot;
    }

    $slots = $DB->get_records('gnrquiz_slots', array('gnrquizid' => $gnrquizid),
            'slot');
    $currentpage = 1;
    $slotsonthispage = 0;
    foreach ($slots as $slot) {
        if (($firstslots && in_array($slot->slot, $firstslots)) ||
            ($slotsonthispage && $slotsonthispage == $slotsperpage)) {
            $currentpage += 1;
            $slotsonthispage = 0;
        }
        if ($slot->page != $currentpage) {
            $DB->set_field('gnrquiz_slots', 'page', $currentpage, array('id' => $slot->id));
        }
        $slotsonthispage += 1;
    }

    $trans->allow_commit();
}

// Functions to do with gnrquiz grades ////////////////////////////////////////////

/**
 * Convert the raw grade stored in $attempt into a grade out of the maximum
 * grade for this gnrquiz.
 *
 * @param float $rawgrade the unadjusted grade, fof example $attempt->sumgrades
 * @param object $gnrquiz the gnrquiz object. Only the fields grade, sumgrades and decimalpoints are used.
 * @param bool|string $format whether to format the results for display
 *      or 'question' to format a question grade (different number of decimal places.
 * @return float|string the rescaled grade, or null/the lang string 'notyetgraded'
 *      if the $grade is null.
 */
function gnrquiz_rescale_grade($rawgrade, $gnrquiz, $format = true) {
    if (is_null($rawgrade)) {
        $grade = null;
    } else if ($gnrquiz->sumgrades >= 0.000005) {
        $grade = $rawgrade * $gnrquiz->grade / $gnrquiz->sumgrades;
    } else {
        $grade = 0;
    }
    if ($format === 'question') {
        $grade = gnrquiz_format_question_grade($gnrquiz, $grade);
    } else if ($format) {
        $grade = gnrquiz_format_grade($gnrquiz, $grade);
    }
    return $grade;
}

/**
 * Get the feedback object for this grade on this gnrquiz.
 *
 * @param float $grade a grade on this gnrquiz.
 * @param object $gnrquiz the gnrquiz settings.
 * @return false|stdClass the record object or false if there is not feedback for the given grade
 * @since  Moodle 3.1
 */
function gnrquiz_feedback_record_for_grade($grade, $gnrquiz) {
    global $DB;

    // With CBM etc, it is possible to get -ve grades, which would then not match
    // any feedback. Therefore, we replace -ve grades with 0.
    $grade = max($grade, 0);

    $feedback = $DB->get_record_select('gnrquiz_feedback',
            'gnrquizid = ? AND mingrade <= ? AND ? < maxgrade', array($gnrquiz->id, $grade, $grade));

    return $feedback;
}

/**
 * Get the feedback text that should be show to a student who
 * got this grade on this gnrquiz. The feedback is processed ready for diplay.
 *
 * @param float $grade a grade on this gnrquiz.
 * @param object $gnrquiz the gnrquiz settings.
 * @param object $context the gnrquiz context.
 * @return string the comment that corresponds to this grade (empty string if there is not one.
 */
function gnrquiz_feedback_for_grade($grade, $gnrquiz, $context) {

    if (is_null($grade)) {
        return '';
    }

    $feedback = gnrquiz_feedback_record_for_grade($grade, $gnrquiz);

    if (empty($feedback->feedbacktext)) {
        return '';
    }

    // Clean the text, ready for display.
    $formatoptions = new stdClass();
    $formatoptions->noclean = true;
    $feedbacktext = file_rewrite_pluginfile_urls($feedback->feedbacktext, 'pluginfile.php',
            $context->id, 'mod_gnrquiz', 'feedback', $feedback->id);
    $feedbacktext = format_text($feedbacktext, $feedback->feedbacktextformat, $formatoptions);

    return $feedbacktext;
}

/**
 * @param object $gnrquiz the gnrquiz database row.
 * @return bool Whether this gnrquiz has any non-blank feedback text.
 */
function gnrquiz_has_feedback($gnrquiz) {
    global $DB;
    static $cache = array();
    if (!array_key_exists($gnrquiz->id, $cache)) {
        $cache[$gnrquiz->id] = gnrquiz_has_grades($gnrquiz) &&
                $DB->record_exists_select('gnrquiz_feedback', "gnrquizid = ? AND " .
                    $DB->sql_isnotempty('gnrquiz_feedback', 'feedbacktext', false, true),
                array($gnrquiz->id));
    }
    return $cache[$gnrquiz->id];
}

/**
 * Update the sumgrades field of the gnrquiz. This needs to be called whenever
 * the grading structure of the gnrquiz is changed. For example if a question is
 * added or removed, or a question weight is changed.
 *
 * You should call {@link gnrquiz_delete_previews()} before you call this function.
 *
 * @param object $gnrquiz a gnrquiz.
 */
function gnrquiz_update_sumgrades($gnrquiz) {
    global $DB;

    $sql = 'UPDATE {gnrquiz}
            SET sumgrades = COALESCE((
                SELECT SUM(maxmark)
                FROM {gnrquiz_slots}
                WHERE gnrquizid = {gnrquiz}.id
            ), 0)
            WHERE id = ?';
    $DB->execute($sql, array($gnrquiz->id));
    $gnrquiz->sumgrades = $DB->get_field('gnrquiz', 'sumgrades', array('id' => $gnrquiz->id));

    if ($gnrquiz->sumgrades < 0.000005 && gnrquiz_has_attempts($gnrquiz->id)) {
        // If the gnrquiz has been attempted, and the sumgrades has been
        // set to 0, then we must also set the maximum possible grade to 0, or
        // we will get a divide by zero error.
        gnrquiz_set_grade(0, $gnrquiz);
    }
}

/**
 * Update the sumgrades field of the attempts at a gnrquiz.
 *
 * @param object $gnrquiz a gnrquiz.
 */
function gnrquiz_update_all_attempt_sumgrades($gnrquiz) {
    global $DB;
    $dm = new question_engine_data_mapper();
    $timenow = time();

    $sql = "UPDATE {gnrquiz_attempts}
            SET
                timemodified = :timenow,
                sumgrades = (
                    {$dm->sum_usage_marks_subquery('uniqueid')}
                )
            WHERE gnrquiz = :gnrquizid AND state = :finishedstate";
    $DB->execute($sql, array('timenow' => $timenow, 'gnrquizid' => $gnrquiz->id,
            'finishedstate' => gnrquiz_attempt::FINISHED));
}

/**
 * The gnrquiz grade is the maximum that student's results are marked out of. When it
 * changes, the corresponding data in gnrquiz_grades and gnrquiz_feedback needs to be
 * rescaled. After calling this function, you probably need to call
 * gnrquiz_update_all_attempt_sumgrades, gnrquiz_update_all_final_grades and
 * gnrquiz_update_grades.
 *
 * @param float $newgrade the new maximum grade for the gnrquiz.
 * @param object $gnrquiz the gnrquiz we are updating. Passed by reference so its
 *      grade field can be updated too.
 * @return bool indicating success or failure.
 */
function gnrquiz_set_grade($newgrade, $gnrquiz) {
    global $DB;
    // This is potentially expensive, so only do it if necessary.
    if (abs($gnrquiz->grade - $newgrade) < 1e-7) {
        // Nothing to do.
        return true;
    }

    $oldgrade = $gnrquiz->grade;
    $gnrquiz->grade = $newgrade;

    // Use a transaction, so that on those databases that support it, this is safer.
    $transaction = $DB->start_delegated_transaction();

    // Update the gnrquiz table.
    $DB->set_field('gnrquiz', 'grade', $newgrade, array('id' => $gnrquiz->instance));

    if ($oldgrade < 1) {
        // If the old grade was zero, we cannot rescale, we have to recompute.
        // We also recompute if the old grade was too small to avoid underflow problems.
        gnrquiz_update_all_final_grades($gnrquiz);

    } else {
        // We can rescale the grades efficiently.
        $timemodified = time();
        $DB->execute("
                UPDATE {gnrquiz_grades}
                SET grade = ? * grade, timemodified = ?
                WHERE gnrquiz = ?
        ", array($newgrade/$oldgrade, $timemodified, $gnrquiz->id));
    }

    if ($oldgrade > 1e-7) {
        // Update the gnrquiz_feedback table.
        $factor = $newgrade/$oldgrade;
        $DB->execute("
                UPDATE {gnrquiz_feedback}
                SET mingrade = ? * mingrade, maxgrade = ? * maxgrade
                WHERE gnrquizid = ?
        ", array($factor, $factor, $gnrquiz->id));
    }

    // Update grade item and send all grades to gradebook.
    gnrquiz_grade_item_update($gnrquiz);
    gnrquiz_update_grades($gnrquiz);

    $transaction->allow_commit();
    return true;
}

/**
 * Save the overall grade for a user at a gnrquiz in the gnrquiz_grades table
 *
 * @param object $gnrquiz The gnrquiz for which the best grade is to be calculated and then saved.
 * @param int $userid The userid to calculate the grade for. Defaults to the current user.
 * @param array $attempts The attempts of this user. Useful if you are
 * looping through many users. Attempts can be fetched in one master query to
 * avoid repeated querying.
 * @return bool Indicates success or failure.
 */
function gnrquiz_save_best_grade($gnrquiz, $userid = null, $attempts = array()) {
    global $DB, $OUTPUT, $USER;

    if (empty($userid)) {
        $userid = $USER->id;
    }

    if (!$attempts) {
        // Get all the attempts made by the user.
        $attempts = gnrquiz_get_user_attempts($gnrquiz->id, $userid);
    }

    // Calculate the best grade.
    $bestgrade = gnrquiz_calculate_best_grade($gnrquiz, $attempts);
    $bestgrade = gnrquiz_rescale_grade($bestgrade, $gnrquiz, false);

    // Save the best grade in the database.
    if (is_null($bestgrade)) {
        $DB->delete_records('gnrquiz_grades', array('gnrquiz' => $gnrquiz->id, 'userid' => $userid));

    } else if ($grade = $DB->get_record('gnrquiz_grades',
            array('gnrquiz' => $gnrquiz->id, 'userid' => $userid))) {
        $grade->grade = $bestgrade;
        $grade->timemodified = time();
        $DB->update_record('gnrquiz_grades', $grade);

    } else {
        $grade = new stdClass();
        $grade->gnrquiz = $gnrquiz->id;
        $grade->userid = $userid;
        $grade->grade = $bestgrade;
        $grade->timemodified = time();
        $DB->insert_record('gnrquiz_grades', $grade);
    }

    gnrquiz_update_grades($gnrquiz, $userid);
}

/**
 * Calculate the overall grade for a gnrquiz given a number of attempts by a particular user.
 *
 * @param object $gnrquiz    the gnrquiz settings object.
 * @param array $attempts an array of all the user's attempts at this gnrquiz in order.
 * @return float          the overall grade
 */
function gnrquiz_calculate_best_grade($gnrquiz, $attempts) {

    switch ($gnrquiz->grademethod) {

        case GNRQUIZ_ATTEMPTFIRST:
            $firstattempt = reset($attempts);
            return $firstattempt->sumgrades;

        case GNRQUIZ_ATTEMPTLAST:
            $lastattempt = end($attempts);
            return $lastattempt->sumgrades;

        case GNRQUIZ_GRADEAVERAGE:
            $sum = 0;
            $count = 0;
            foreach ($attempts as $attempt) {
                if (!is_null($attempt->sumgrades)) {
                    $sum += $attempt->sumgrades;
                    $count++;
                }
            }
            if ($count == 0) {
                return null;
            }
            return $sum / $count;

        case GNRQUIZ_GRADEHIGHEST:
        default:
            $max = null;
            foreach ($attempts as $attempt) {
                if ($attempt->sumgrades > $max) {
                    $max = $attempt->sumgrades;
                }
            }
            return $max;
    }
}

/**
 * Update the final grade at this gnrquiz for all students.
 *
 * This function is equivalent to calling gnrquiz_save_best_grade for all
 * users, but much more efficient.
 *
 * @param object $gnrquiz the gnrquiz settings.
 */
function gnrquiz_update_all_final_grades($gnrquiz) {
    global $DB;

    if (!$gnrquiz->sumgrades) {
        return;
    }

    $param = array('ignrquizid' => $gnrquiz->id, 'istatefinished' => gnrquiz_attempt::FINISHED);
    $firstlastattemptjoin = "JOIN (
            SELECT
                ignrquiza.userid,
                MIN(attempt) AS firstattempt,
                MAX(attempt) AS lastattempt

            FROM {gnrquiz_attempts} ignrquiza

            WHERE
                ignrquiza.state = :istatefinished AND
                ignrquiza.preview = 0 AND
                ignrquiza.gnrquiz = :ignrquizid

            GROUP BY ignrquiza.userid
        ) first_last_attempts ON first_last_attempts.userid = gnrquiza.userid";

    switch ($gnrquiz->grademethod) {
        case GNRQUIZ_ATTEMPTFIRST:
            // Because of the where clause, there will only be one row, but we
            // must still use an aggregate function.
            $select = 'MAX(gnrquiza.sumgrades)';
            $join = $firstlastattemptjoin;
            $where = 'gnrquiza.attempt = first_last_attempts.firstattempt AND';
            break;

        case GNRQUIZ_ATTEMPTLAST:
            // Because of the where clause, there will only be one row, but we
            // must still use an aggregate function.
            $select = 'MAX(gnrquiza.sumgrades)';
            $join = $firstlastattemptjoin;
            $where = 'gnrquiza.attempt = first_last_attempts.lastattempt AND';
            break;

        case GNRQUIZ_GRADEAVERAGE:
            $select = 'AVG(gnrquiza.sumgrades)';
            $join = '';
            $where = '';
            break;

        default:
        case GNRQUIZ_GRADEHIGHEST:
            $select = 'MAX(gnrquiza.sumgrades)';
            $join = '';
            $where = '';
            break;
    }

    if ($gnrquiz->sumgrades >= 0.000005) {
        $finalgrade = $select . ' * ' . ($gnrquiz->grade / $gnrquiz->sumgrades);
    } else {
        $finalgrade = '0';
    }
    $param['gnrquizid'] = $gnrquiz->id;
    $param['gnrquizid2'] = $gnrquiz->id;
    $param['gnrquizid3'] = $gnrquiz->id;
    $param['gnrquizid4'] = $gnrquiz->id;
    $param['statefinished'] = gnrquiz_attempt::FINISHED;
    $param['statefinished2'] = gnrquiz_attempt::FINISHED;
    $finalgradesubquery = "
            SELECT gnrquiza.userid, $finalgrade AS newgrade
            FROM {gnrquiz_attempts} gnrquiza
            $join
            WHERE
                $where
                gnrquiza.state = :statefinished AND
                gnrquiza.preview = 0 AND
                gnrquiza.gnrquiz = :gnrquizid3
            GROUP BY gnrquiza.userid";

    $changedgrades = $DB->get_records_sql("
            SELECT users.userid, qg.id, qg.grade, newgrades.newgrade

            FROM (
                SELECT userid
                FROM {gnrquiz_grades} qg
                WHERE gnrquiz = :gnrquizid
            UNION
                SELECT DISTINCT userid
                FROM {gnrquiz_attempts} gnrquiza2
                WHERE
                    gnrquiza2.state = :statefinished2 AND
                    gnrquiza2.preview = 0 AND
                    gnrquiza2.gnrquiz = :gnrquizid2
            ) users

            LEFT JOIN {gnrquiz_grades} qg ON qg.userid = users.userid AND qg.gnrquiz = :gnrquizid4

            LEFT JOIN (
                $finalgradesubquery
            ) newgrades ON newgrades.userid = users.userid

            WHERE
                ABS(newgrades.newgrade - qg.grade) > 0.000005 OR
                ((newgrades.newgrade IS NULL OR qg.grade IS NULL) AND NOT
                          (newgrades.newgrade IS NULL AND qg.grade IS NULL))",
                // The mess on the previous line is detecting where the value is
                // NULL in one column, and NOT NULL in the other, but SQL does
                // not have an XOR operator, and MS SQL server can't cope with
                // (newgrades.newgrade IS NULL) <> (qg.grade IS NULL).
            $param);

    $timenow = time();
    $todelete = array();
    foreach ($changedgrades as $changedgrade) {

        if (is_null($changedgrade->newgrade)) {
            $todelete[] = $changedgrade->userid;

        } else if (is_null($changedgrade->grade)) {
            $toinsert = new stdClass();
            $toinsert->gnrquiz = $gnrquiz->id;
            $toinsert->userid = $changedgrade->userid;
            $toinsert->timemodified = $timenow;
            $toinsert->grade = $changedgrade->newgrade;
            $DB->insert_record('gnrquiz_grades', $toinsert);

        } else {
            $toupdate = new stdClass();
            $toupdate->id = $changedgrade->id;
            $toupdate->grade = $changedgrade->newgrade;
            $toupdate->timemodified = $timenow;
            $DB->update_record('gnrquiz_grades', $toupdate);
        }
    }

    if (!empty($todelete)) {
        list($test, $params) = $DB->get_in_or_equal($todelete);
        $DB->delete_records_select('gnrquiz_grades', 'gnrquiz = ? AND userid ' . $test,
                array_merge(array($gnrquiz->id), $params));
    }
}

/**
 * Efficiently update check state time on all open attempts
 *
 * @param array $conditions optional restrictions on which attempts to update
 *                    Allowed conditions:
 *                      courseid => (array|int) attempts in given course(s)
 *                      userid   => (array|int) attempts for given user(s)
 *                      gnrquizid   => (array|int) attempts in given gnrquiz(s)
 *                      groupid  => (array|int) gnrquizzes with some override for given group(s)
 *
 */
function gnrquiz_update_open_attempts(array $conditions) {
    global $DB;

    foreach ($conditions as &$value) {
        if (!is_array($value)) {
            $value = array($value);
        }
    }

    $params = array();
    $wheres = array("gnrquiza.state IN ('inprogress', 'overdue')");
    $iwheres = array("ignrquiza.state IN ('inprogress', 'overdue')");

    if (isset($conditions['courseid'])) {
        list ($incond, $inparams) = $DB->get_in_or_equal($conditions['courseid'], SQL_PARAMS_NAMED, 'cid');
        $params = array_merge($params, $inparams);
        $wheres[] = "gnrquiza.gnrquiz IN (SELECT q.id FROM {gnrquiz} q WHERE q.course $incond)";
        list ($incond, $inparams) = $DB->get_in_or_equal($conditions['courseid'], SQL_PARAMS_NAMED, 'icid');
        $params = array_merge($params, $inparams);
        $iwheres[] = "ignrquiza.gnrquiz IN (SELECT q.id FROM {gnrquiz} q WHERE q.course $incond)";
    }

    if (isset($conditions['userid'])) {
        list ($incond, $inparams) = $DB->get_in_or_equal($conditions['userid'], SQL_PARAMS_NAMED, 'uid');
        $params = array_merge($params, $inparams);
        $wheres[] = "gnrquiza.userid $incond";
        list ($incond, $inparams) = $DB->get_in_or_equal($conditions['userid'], SQL_PARAMS_NAMED, 'iuid');
        $params = array_merge($params, $inparams);
        $iwheres[] = "ignrquiza.userid $incond";
    }

    if (isset($conditions['gnrquizid'])) {
        list ($incond, $inparams) = $DB->get_in_or_equal($conditions['gnrquizid'], SQL_PARAMS_NAMED, 'qid');
        $params = array_merge($params, $inparams);
        $wheres[] = "gnrquiza.gnrquiz $incond";
        list ($incond, $inparams) = $DB->get_in_or_equal($conditions['gnrquizid'], SQL_PARAMS_NAMED, 'iqid');
        $params = array_merge($params, $inparams);
        $iwheres[] = "ignrquiza.gnrquiz $incond";
    }

    if (isset($conditions['groupid'])) {
        list ($incond, $inparams) = $DB->get_in_or_equal($conditions['groupid'], SQL_PARAMS_NAMED, 'gid');
        $params = array_merge($params, $inparams);
        $wheres[] = "gnrquiza.gnrquiz IN (SELECT qo.gnrquiz FROM {gnrquiz_overrides} qo WHERE qo.groupid $incond)";
        list ($incond, $inparams) = $DB->get_in_or_equal($conditions['groupid'], SQL_PARAMS_NAMED, 'igid');
        $params = array_merge($params, $inparams);
        $iwheres[] = "ignrquiza.gnrquiz IN (SELECT qo.gnrquiz FROM {gnrquiz_overrides} qo WHERE qo.groupid $incond)";
    }

    // SQL to compute timeclose and timelimit for each attempt:
    $gnrquizausersql = gnrquiz_get_attempt_usertime_sql(
            implode("\n                AND ", $iwheres));

    // SQL to compute the new timecheckstate
    $timecheckstatesql = "
          CASE WHEN gnrquizauser.usertimelimit = 0 AND gnrquizauser.usertimeclose = 0 THEN NULL
               WHEN gnrquizauser.usertimelimit = 0 THEN gnrquizauser.usertimeclose
               WHEN gnrquizauser.usertimeclose = 0 THEN gnrquiza.timestart + gnrquizauser.usertimelimit
               WHEN gnrquiza.timestart + gnrquizauser.usertimelimit < gnrquizauser.usertimeclose THEN gnrquiza.timestart + gnrquizauser.usertimelimit
               ELSE gnrquizauser.usertimeclose END +
          CASE WHEN gnrquiza.state = 'overdue' THEN gnrquiz.graceperiod ELSE 0 END";

    // SQL to select which attempts to process
    $attemptselect = implode("\n                         AND ", $wheres);

   /*
    * Each database handles updates with inner joins differently:
    *  - mysql does not allow a FROM clause
    *  - postgres and mssql allow FROM but handle table aliases differently
    *  - oracle requires a subquery
    *
    * Different code for each database.
    */

    $dbfamily = $DB->get_dbfamily();
    if ($dbfamily == 'mysql') {
        $updatesql = "UPDATE {gnrquiz_attempts} gnrquiza
                        JOIN {gnrquiz} gnrquiz ON gnrquiz.id = gnrquiza.gnrquiz
                        JOIN ( $gnrquizausersql ) gnrquizauser ON gnrquizauser.id = gnrquiza.id
                         SET gnrquiza.timecheckstate = $timecheckstatesql
                       WHERE $attemptselect";
    } else if ($dbfamily == 'postgres') {
        $updatesql = "UPDATE {gnrquiz_attempts} gnrquiza
                         SET timecheckstate = $timecheckstatesql
                        FROM {gnrquiz} gnrquiz, ( $gnrquizausersql ) gnrquizauser
                       WHERE gnrquiz.id = gnrquiza.gnrquiz
                         AND gnrquizauser.id = gnrquiza.id
                         AND $attemptselect";
    } else if ($dbfamily == 'mssql') {
        $updatesql = "UPDATE gnrquiza
                         SET timecheckstate = $timecheckstatesql
                        FROM {gnrquiz_attempts} gnrquiza
                        JOIN {gnrquiz} gnrquiz ON gnrquiz.id = gnrquiza.gnrquiz
                        JOIN ( $gnrquizausersql ) gnrquizauser ON gnrquizauser.id = gnrquiza.id
                       WHERE $attemptselect";
    } else {
        // oracle, sqlite and others
        $updatesql = "UPDATE {gnrquiz_attempts} gnrquiza
                         SET timecheckstate = (
                           SELECT $timecheckstatesql
                             FROM {gnrquiz} gnrquiz, ( $gnrquizausersql ) gnrquizauser
                            WHERE gnrquiz.id = gnrquiza.gnrquiz
                              AND gnrquizauser.id = gnrquiza.id
                         )
                         WHERE $attemptselect";
    }

    $DB->execute($updatesql, $params);
}

/**
 * Returns SQL to compute timeclose and timelimit for every attempt, taking into account user and group overrides.
 *
 * @param string $redundantwhereclauses extra where clauses to add to the subquery
 *      for performance. These can use the table alias ignrquiza for the gnrquiz attempts table.
 * @return string SQL select with columns attempt.id, usertimeclose, usertimelimit.
 */
function gnrquiz_get_attempt_usertime_sql($redundantwhereclauses = '') {
    if ($redundantwhereclauses) {
        $redundantwhereclauses = 'WHERE ' . $redundantwhereclauses;
    }
    // The multiple qgo JOINS are necessary because we want timeclose/timelimit = 0 (unlimited) to supercede
    // any other group override
    $gnrquizausersql = "
          SELECT ignrquiza.id,
           COALESCE(MAX(quo.timeclose), MAX(qgo1.timeclose), MAX(qgo2.timeclose), ignrquiz.timeclose) AS usertimeclose,
           COALESCE(MAX(quo.timelimit), MAX(qgo3.timelimit), MAX(qgo4.timelimit), ignrquiz.timelimit) AS usertimelimit

           FROM {gnrquiz_attempts} ignrquiza
           JOIN {gnrquiz} ignrquiz ON ignrquiz.id = ignrquiza.gnrquiz
      LEFT JOIN {gnrquiz_overrides} quo ON quo.gnrquiz = ignrquiza.gnrquiz AND quo.userid = ignrquiza.userid
      LEFT JOIN {groups_members} gm ON gm.userid = ignrquiza.userid
      LEFT JOIN {gnrquiz_overrides} qgo1 ON qgo1.gnrquiz = ignrquiza.gnrquiz AND qgo1.groupid = gm.groupid AND qgo1.timeclose = 0
      LEFT JOIN {gnrquiz_overrides} qgo2 ON qgo2.gnrquiz = ignrquiza.gnrquiz AND qgo2.groupid = gm.groupid AND qgo2.timeclose > 0
      LEFT JOIN {gnrquiz_overrides} qgo3 ON qgo3.gnrquiz = ignrquiza.gnrquiz AND qgo3.groupid = gm.groupid AND qgo3.timelimit = 0
      LEFT JOIN {gnrquiz_overrides} qgo4 ON qgo4.gnrquiz = ignrquiza.gnrquiz AND qgo4.groupid = gm.groupid AND qgo4.timelimit > 0
          $redundantwhereclauses
       GROUP BY ignrquiza.id, ignrquiz.id, ignrquiz.timeclose, ignrquiz.timelimit";
    return $gnrquizausersql;
}

/**
 * Return the attempt with the best grade for a gnrquiz
 *
 * Which attempt is the best depends on $gnrquiz->grademethod. If the grade
 * method is GRADEAVERAGE then this function simply returns the last attempt.
 * @return object         The attempt with the best grade
 * @param object $gnrquiz    The gnrquiz for which the best grade is to be calculated
 * @param array $attempts An array of all the attempts of the user at the gnrquiz
 */
function gnrquiz_calculate_best_attempt($gnrquiz, $attempts) {

    switch ($gnrquiz->grademethod) {

        case GNRQUIZ_ATTEMPTFIRST:
            foreach ($attempts as $attempt) {
                return $attempt;
            }
            break;

        case GNRQUIZ_GRADEAVERAGE: // We need to do something with it.
        case GNRQUIZ_ATTEMPTLAST:
            foreach ($attempts as $attempt) {
                $final = $attempt;
            }
            return $final;

        default:
        case GNRQUIZ_GRADEHIGHEST:
            $max = -1;
            foreach ($attempts as $attempt) {
                if ($attempt->sumgrades > $max) {
                    $max = $attempt->sumgrades;
                    $maxattempt = $attempt;
                }
            }
            return $maxattempt;
    }
}

/**
 * @return array int => lang string the options for calculating the gnrquiz grade
 *      from the individual attempt grades.
 */
function gnrquiz_get_grading_options() {
    return array(
        GNRQUIZ_GRADEHIGHEST => get_string('gradehighest', 'gnrquiz'),
        GNRQUIZ_GRADEAVERAGE => get_string('gradeaverage', 'gnrquiz'),
        GNRQUIZ_ATTEMPTFIRST => get_string('attemptfirst', 'gnrquiz'),
        GNRQUIZ_ATTEMPTLAST  => get_string('attemptlast', 'gnrquiz')
    );
}

/**
 * @param int $option one of the values GNRQUIZ_GRADEHIGHEST, GNRQUIZ_GRADEAVERAGE,
 *      GNRQUIZ_ATTEMPTFIRST or GNRQUIZ_ATTEMPTLAST.
 * @return the lang string for that option.
 */
function gnrquiz_get_grading_option_name($option) {
    $strings = gnrquiz_get_grading_options();
    return $strings[$option];
}

/**
 * @return array string => lang string the options for handling overdue gnrquiz
 *      attempts.
 */
function gnrquiz_get_overdue_handling_options() {
    return array(
        'autosubmit'  => get_string('overduehandlingautosubmit', 'gnrquiz'),
        'graceperiod' => get_string('overduehandlinggraceperiod', 'gnrquiz'),
        'autoabandon' => get_string('overduehandlingautoabandon', 'gnrquiz'),
    );
}

/**
 * Get the choices for what size user picture to show.
 * @return array string => lang string the options for whether to display the user's picture.
 */
function gnrquiz_get_user_image_options() {
    return array(
        GNRQUIZ_SHOWIMAGE_NONE  => get_string('shownoimage', 'gnrquiz'),
        GNRQUIZ_SHOWIMAGE_SMALL => get_string('showsmallimage', 'gnrquiz'),
        GNRQUIZ_SHOWIMAGE_LARGE => get_string('showlargeimage', 'gnrquiz'),
    );
}

/**
 * Get the choices to offer for the 'Questions per page' option.
 * @return array int => string.
 */
function gnrquiz_questions_per_page_options() {
    $pageoptions = array();
    $pageoptions[0] = get_string('neverallononepage', 'gnrquiz');
    $pageoptions[1] = get_string('everyquestion', 'gnrquiz');
    for ($i = 2; $i <= GNRQUIZ_MAX_QPP_OPTION; ++$i) {
        $pageoptions[$i] = get_string('everynquestions', 'gnrquiz', $i);
    }
    return $pageoptions;
}

/**
 * Get the human-readable name for a gnrquiz attempt state.
 * @param string $state one of the state constants like {@link gnrquiz_attempt::IN_PROGRESS}.
 * @return string The lang string to describe that state.
 */
function gnrquiz_attempt_state_name($state) {
    switch ($state) {
        case gnrquiz_attempt::IN_PROGRESS:
            return get_string('stateinprogress', 'gnrquiz');
        case gnrquiz_attempt::OVERDUE:
            return get_string('stateoverdue', 'gnrquiz');
        case gnrquiz_attempt::FINISHED:
            return get_string('statefinished', 'gnrquiz');
        case gnrquiz_attempt::ABANDONED:
            return get_string('stateabandoned', 'gnrquiz');
        default:
            throw new coding_exception('Unknown gnrquiz attempt state.');
    }
}

// Other gnrquiz functions ////////////////////////////////////////////////////////

/**
 * @param object $gnrquiz the gnrquiz.
 * @param int $cmid the course_module object for this gnrquiz.
 * @param object $question the question.
 * @param string $returnurl url to return to after action is done.
 * @param int $variant which question variant to preview (optional).
 * @return string html for a number of icons linked to action pages for a
 * question - preview and edit / view icons depending on user capabilities.
 */
function gnrquiz_question_action_icons($gnrquiz, $cmid, $question, $returnurl, $variant = null) {
    $html = gnrquiz_question_preview_button($gnrquiz, $question, false, $variant) . ' ' .
            gnrquiz_question_edit_button($cmid, $question, $returnurl);
    return $html;
}

/**
 * @param int $cmid the course_module.id for this gnrquiz.
 * @param object $question the question.
 * @param string $returnurl url to return to after action is done.
 * @param string $contentbeforeicon some HTML content to be added inside the link, before the icon.
 * @return the HTML for an edit icon, view icon, or nothing for a question
 *      (depending on permissions).
 */
function gnrquiz_question_edit_button($cmid, $question, $returnurl, $contentaftericon = '') {
    global $CFG, $OUTPUT;

    // Minor efficiency saving. Only get strings once, even if there are a lot of icons on one page.
    static $stredit = null;
    static $strview = null;
    if ($stredit === null) {
        $stredit = get_string('edit');
        $strview = get_string('view');
    }

    // What sort of icon should we show?
    $action = '';
    if (!empty($question->id) &&
            (question_has_capability_on($question, 'edit', $question->category) ||
                    question_has_capability_on($question, 'move', $question->category))) {
        $action = $stredit;
        $icon = '/t/edit';
    } else if (!empty($question->id) &&
            question_has_capability_on($question, 'view', $question->category)) {
        $action = $strview;
        $icon = '/i/info';
    }

    // Build the icon.
    if ($action) {
        if ($returnurl instanceof moodle_url) {
            $returnurl = $returnurl->out_as_local_url(false);
        }
        $questionparams = array('returnurl' => $returnurl, 'cmid' => $cmid, 'id' => $question->id);
        $questionurl = new moodle_url("$CFG->wwwroot/question/question.php", $questionparams);
        return '<a title="' . $action . '" href="' . $questionurl->out() . '" class="questioneditbutton"><img src="' .
                $OUTPUT->pix_url($icon) . '" alt="' . $action . '" />' . $contentaftericon .
                '</a>';
    } else if ($contentaftericon) {
        return '<span class="questioneditbutton">' . $contentaftericon . '</span>';
    } else {
        return '';
    }
}

/**
 * @param object $gnrquiz the gnrquiz settings
 * @param object $question the question
 * @param int $variant which question variant to preview (optional).
 * @return moodle_url to preview this question with the options from this gnrquiz.
 */
function gnrquiz_question_preview_url($gnrquiz, $question, $variant = null) {
    // Get the appropriate display options.
    $displayoptions = mod_gnrquiz_display_options::make_from_gnrquiz($gnrquiz,
            mod_gnrquiz_display_options::DURING);

    $maxmark = null;
    if (isset($question->maxmark)) {
        $maxmark = $question->maxmark;
    }

    // Work out the correcte preview URL.
    return question_preview_url($question->id, $gnrquiz->preferredbehaviour,
            $maxmark, $displayoptions, $variant);
}

/**
 * @param object $gnrquiz the gnrquiz settings
 * @param object $question the question
 * @param bool $label if true, show the preview question label after the icon
 * @param int $variant which question variant to preview (optional).
 * @return the HTML for a preview question icon.
 */
function gnrquiz_question_preview_button($gnrquiz, $question, $label = false, $variant = null) {
    global $PAGE;
    if (!question_has_capability_on($question, 'use', $question->category)) {
        return '';
    }

    return $PAGE->get_renderer('mod_gnrquiz', 'edit')->question_preview_icon($gnrquiz, $question, $label, $variant);
}

/**
 * @param object $attempt the attempt.
 * @param object $context the gnrquiz context.
 * @return int whether flags should be shown/editable to the current user for this attempt.
 */
function gnrquiz_get_flag_option($attempt, $context) {
    global $USER;
    if (!has_capability('moodle/question:flag', $context)) {
        return question_display_options::HIDDEN;
    } else if ($attempt->userid == $USER->id) {
        return question_display_options::EDITABLE;
    } else {
        return question_display_options::VISIBLE;
    }
}

/**
 * Work out what state this gnrquiz attempt is in - in the sense used by
 * gnrquiz_get_review_options, not in the sense of $attempt->state.
 * @param object $gnrquiz the gnrquiz settings
 * @param object $attempt the gnrquiz_attempt database row.
 * @return int one of the mod_gnrquiz_display_options::DURING,
 *      IMMEDIATELY_AFTER, LATER_WHILE_OPEN or AFTER_CLOSE constants.
 */
function gnrquiz_attempt_state($gnrquiz, $attempt) {
    if ($attempt->state == gnrquiz_attempt::IN_PROGRESS) {
        return mod_gnrquiz_display_options::DURING;
    } else if ($gnrquiz->timeclose && time() >= $gnrquiz->timeclose) {
        return mod_gnrquiz_display_options::AFTER_CLOSE;
    } else if (time() < $attempt->timefinish + 120) {
        return mod_gnrquiz_display_options::IMMEDIATELY_AFTER;
    } else {
        return mod_gnrquiz_display_options::LATER_WHILE_OPEN;
    }
}

/**
 * The the appropraite mod_gnrquiz_display_options object for this attempt at this
 * gnrquiz right now.
 *
 * @param object $gnrquiz the gnrquiz instance.
 * @param object $attempt the attempt in question.
 * @param $context the gnrquiz context.
 *
 * @return mod_gnrquiz_display_options
 */
function gnrquiz_get_review_options($gnrquiz, $attempt, $context) {
    $options = mod_gnrquiz_display_options::make_from_gnrquiz($gnrquiz, gnrquiz_attempt_state($gnrquiz, $attempt));

    $options->readonly = true;
    $options->flags = gnrquiz_get_flag_option($attempt, $context);
    if (!empty($attempt->id)) {
        $options->questionreviewlink = new moodle_url('/mod/gnrquiz/reviewquestion.php',
                array('attempt' => $attempt->id));
    }

    // Show a link to the comment box only for closed attempts.
    if (!empty($attempt->id) && $attempt->state == gnrquiz_attempt::FINISHED && !$attempt->preview &&
            !is_null($context) && has_capability('mod/gnrquiz:grade', $context)) {
        $options->manualcomment = question_display_options::VISIBLE;
        $options->manualcommentlink = new moodle_url('/mod/gnrquiz/comment.php',
                array('attempt' => $attempt->id));
    }

    if (!is_null($context) && !$attempt->preview &&
            has_capability('mod/gnrquiz:viewreports', $context) &&
            has_capability('moodle/grade:viewhidden', $context)) {
        // People who can see reports and hidden grades should be shown everything,
        // except during preview when teachers want to see what students see.
        $options->attempt = question_display_options::VISIBLE;
        $options->correctness = question_display_options::VISIBLE;
        $options->marks = question_display_options::MARK_AND_MAX;
        $options->feedback = question_display_options::VISIBLE;
        $options->numpartscorrect = question_display_options::VISIBLE;
        $options->manualcomment = question_display_options::VISIBLE;
        $options->generalfeedback = question_display_options::VISIBLE;
        $options->rightanswer = question_display_options::VISIBLE;
        $options->overallfeedback = question_display_options::VISIBLE;
        $options->history = question_display_options::VISIBLE;

    }

    return $options;
}

/**
 * Combines the review options from a number of different gnrquiz attempts.
 * Returns an array of two ojects, so the suggested way of calling this
 * funciton is:
 * list($someoptions, $alloptions) = gnrquiz_get_combined_reviewoptions(...)
 *
 * @param object $gnrquiz the gnrquiz instance.
 * @param array $attempts an array of attempt objects.
 *
 * @return array of two options objects, one showing which options are true for
 *          at least one of the attempts, the other showing which options are true
 *          for all attempts.
 */
function gnrquiz_get_combined_reviewoptions($gnrquiz, $attempts) {
    $fields = array('feedback', 'generalfeedback', 'rightanswer', 'overallfeedback');
    $someoptions = new stdClass();
    $alloptions = new stdClass();
    foreach ($fields as $field) {
        $someoptions->$field = false;
        $alloptions->$field = true;
    }
    $someoptions->marks = question_display_options::HIDDEN;
    $alloptions->marks = question_display_options::MARK_AND_MAX;

    // This shouldn't happen, but we need to prevent reveal information.
    if (empty($attempts)) {
        return array($someoptions, $someoptions);
    }

    foreach ($attempts as $attempt) {
        $attemptoptions = mod_gnrquiz_display_options::make_from_gnrquiz($gnrquiz,
                gnrquiz_attempt_state($gnrquiz, $attempt));
        foreach ($fields as $field) {
            $someoptions->$field = $someoptions->$field || $attemptoptions->$field;
            $alloptions->$field = $alloptions->$field && $attemptoptions->$field;
        }
        $someoptions->marks = max($someoptions->marks, $attemptoptions->marks);
        $alloptions->marks = min($alloptions->marks, $attemptoptions->marks);
    }
    return array($someoptions, $alloptions);
}

// Functions for sending notification messages /////////////////////////////////

/**
 * Sends a confirmation message to the student confirming that the attempt was processed.
 *
 * @param object $a lots of useful information that can be used in the message
 *      subject and body.
 *
 * @return int|false as for {@link message_send()}.
 */
function gnrquiz_send_confirmation($recipient, $a) {

    // Add information about the recipient to $a.
    // Don't do idnumber. we want idnumber to be the submitter's idnumber.
    $a->username     = fullname($recipient);
    $a->userusername = $recipient->username;

    // Prepare the message.
    $eventdata = new stdClass();
    $eventdata->component         = 'mod_gnrquiz';
    $eventdata->name              = 'confirmation';
    $eventdata->notification      = 1;

    $eventdata->userfrom          = core_user::get_noreply_user();
    $eventdata->userto            = $recipient;
    $eventdata->subject           = get_string('emailconfirmsubject', 'gnrquiz', $a);
    $eventdata->fullmessage       = get_string('emailconfirmbody', 'gnrquiz', $a);
    $eventdata->fullmessageformat = FORMAT_PLAIN;
    $eventdata->fullmessagehtml   = '';

    $eventdata->smallmessage      = get_string('emailconfirmsmall', 'gnrquiz', $a);
    $eventdata->contexturl        = $a->gnrquizurl;
    $eventdata->contexturlname    = $a->gnrquizname;

    // ... and send it.
    return message_send($eventdata);
}

/**
 * Sends notification messages to the interested parties that assign the role capability
 *
 * @param object $recipient user object of the intended recipient
 * @param object $a associative array of replaceable fields for the templates
 *
 * @return int|false as for {@link message_send()}.
 */
function gnrquiz_send_notification($recipient, $submitter, $a) {

    // Recipient info for template.
    $a->useridnumber = $recipient->idnumber;
    $a->username     = fullname($recipient);
    $a->userusername = $recipient->username;

    // Prepare the message.
    $eventdata = new stdClass();
    $eventdata->component         = 'mod_gnrquiz';
    $eventdata->name              = 'submission';
    $eventdata->notification      = 1;

    $eventdata->userfrom          = $submitter;
    $eventdata->userto            = $recipient;
    $eventdata->subject           = get_string('emailnotifysubject', 'gnrquiz', $a);
    $eventdata->fullmessage       = get_string('emailnotifybody', 'gnrquiz', $a);
    $eventdata->fullmessageformat = FORMAT_PLAIN;
    $eventdata->fullmessagehtml   = '';

    $eventdata->smallmessage      = get_string('emailnotifysmall', 'gnrquiz', $a);
    $eventdata->contexturl        = $a->gnrquizreviewurl;
    $eventdata->contexturlname    = $a->gnrquizname;

    // ... and send it.
    return message_send($eventdata);
}

/**
 * Send all the requried messages when a gnrquiz attempt is submitted.
 *
 * @param object $course the course
 * @param object $gnrquiz the gnrquiz
 * @param object $attempt this attempt just finished
 * @param object $context the gnrquiz context
 * @param object $cm the coursemodule for this gnrquiz
 *
 * @return bool true if all necessary messages were sent successfully, else false.
 */
function gnrquiz_send_notification_messages($course, $gnrquiz, $attempt, $context, $cm) {
    global $CFG, $DB;

    // Do nothing if required objects not present.
    if (empty($course) or empty($gnrquiz) or empty($attempt) or empty($context)) {
        throw new coding_exception('$course, $gnrquiz, $attempt, $context and $cm must all be set.');
    }

    $submitter = $DB->get_record('user', array('id' => $attempt->userid), '*', MUST_EXIST);

    // Check for confirmation required.
    $sendconfirm = false;
    $notifyexcludeusers = '';
    if (has_capability('mod/gnrquiz:emailconfirmsubmission', $context, $submitter, false)) {
        $notifyexcludeusers = $submitter->id;
        $sendconfirm = true;
    }

    // Check for notifications required.
    $notifyfields = 'u.id, u.username, u.idnumber, u.email, u.emailstop, u.lang,
            u.timezone, u.mailformat, u.maildisplay, u.auth, u.suspended, u.deleted, ';
    $notifyfields .= get_all_user_name_fields(true, 'u');
    $groups = groups_get_all_groups($course->id, $submitter->id, $cm->groupingid);
    if (is_array($groups) && count($groups) > 0) {
        $groups = array_keys($groups);
    } else if (groups_get_activity_groupmode($cm, $course) != NOGROUPS) {
        // If the user is not in a group, and the gnrquiz is set to group mode,
        // then set $groups to a non-existant id so that only users with
        // 'moodle/site:accessallgroups' get notified.
        $groups = -1;
    } else {
        $groups = '';
    }
    $userstonotify = get_users_by_capability($context, 'mod/gnrquiz:emailnotifysubmission',
            $notifyfields, '', '', '', $groups, $notifyexcludeusers, false, false, true);

    if (empty($userstonotify) && !$sendconfirm) {
        return true; // Nothing to do.
    }

    $a = new stdClass();
    // Course info.
    $a->coursename      = $course->fullname;
    $a->courseshortname = $course->shortname;
    // Quiz info.
    $a->gnrquizname        = $gnrquiz->name;
    $a->gnrquizreporturl   = $CFG->wwwroot . '/mod/gnrquiz/report.php?id=' . $cm->id;
    $a->gnrquizreportlink  = '<a href="' . $a->gnrquizreporturl . '">' .
            format_string($gnrquiz->name) . ' report</a>';
    $a->gnrquizurl         = $CFG->wwwroot . '/mod/gnrquiz/view.php?id=' . $cm->id;
    $a->gnrquizlink        = '<a href="' . $a->gnrquizurl . '">' . format_string($gnrquiz->name) . '</a>';
    // Attempt info.
    $a->submissiontime  = userdate($attempt->timefinish);
    $a->timetaken       = format_time($attempt->timefinish - $attempt->timestart);
    $a->gnrquizreviewurl   = $CFG->wwwroot . '/mod/gnrquiz/review.php?attempt=' . $attempt->id;
    $a->gnrquizreviewlink  = '<a href="' . $a->gnrquizreviewurl . '">' .
            format_string($gnrquiz->name) . ' review</a>';
    // Student who sat the gnrquiz info.
    $a->studentidnumber = $submitter->idnumber;
    $a->studentname     = fullname($submitter);
    $a->studentusername = $submitter->username;

    $allok = true;

    // Send notifications if required.
    if (!empty($userstonotify)) {
        foreach ($userstonotify as $recipient) {
            $allok = $allok && gnrquiz_send_notification($recipient, $submitter, $a);
        }
    }

    // Send confirmation if required. We send the student confirmation last, so
    // that if message sending is being intermittently buggy, which means we send
    // some but not all messages, and then try again later, then teachers may get
    // duplicate messages, but the student will always get exactly one.
    if ($sendconfirm) {
        $allok = $allok && gnrquiz_send_confirmation($submitter, $a);
    }

    return $allok;
}

/**
 * Send the notification message when a gnrquiz attempt becomes overdue.
 *
 * @param gnrquiz_attempt $attemptobj all the data about the gnrquiz attempt.
 */
function gnrquiz_send_overdue_message($attemptobj) {
    global $CFG, $DB;

    $submitter = $DB->get_record('user', array('id' => $attemptobj->get_userid()), '*', MUST_EXIST);

    if (!$attemptobj->has_capability('mod/gnrquiz:emailwarnoverdue', $submitter->id, false)) {
        return; // Message not required.
    }

    if (!$attemptobj->has_response_to_at_least_one_graded_question()) {
        return; // Message not required.
    }

    // Prepare lots of useful information that admins might want to include in
    // the email message.
    $gnrquizname = format_string($attemptobj->get_gnrquiz_name());

    $deadlines = array();
    if ($attemptobj->get_gnrquiz()->timelimit) {
        $deadlines[] = $attemptobj->get_attempt()->timestart + $attemptobj->get_gnrquiz()->timelimit;
    }
    if ($attemptobj->get_gnrquiz()->timeclose) {
        $deadlines[] = $attemptobj->get_gnrquiz()->timeclose;
    }
    $duedate = min($deadlines);
    $graceend = $duedate + $attemptobj->get_gnrquiz()->graceperiod;

    $a = new stdClass();
    // Course info.
    $a->coursename         = format_string($attemptobj->get_course()->fullname);
    $a->courseshortname    = format_string($attemptobj->get_course()->shortname);
    // Quiz info.
    $a->gnrquizname           = $gnrquizname;
    $a->gnrquizurl            = $attemptobj->view_url();
    $a->gnrquizlink           = '<a href="' . $a->gnrquizurl . '">' . $gnrquizname . '</a>';
    // Attempt info.
    $a->attemptduedate     = userdate($duedate);
    $a->attemptgraceend    = userdate($graceend);
    $a->attemptsummaryurl  = $attemptobj->summary_url()->out(false);
    $a->attemptsummarylink = '<a href="' . $a->attemptsummaryurl . '">' . $gnrquizname . ' review</a>';
    // Student's info.
    $a->studentidnumber    = $submitter->idnumber;
    $a->studentname        = fullname($submitter);
    $a->studentusername    = $submitter->username;

    // Prepare the message.
    $eventdata = new stdClass();
    $eventdata->component         = 'mod_gnrquiz';
    $eventdata->name              = 'attempt_overdue';
    $eventdata->notification      = 1;

    $eventdata->userfrom          = core_user::get_noreply_user();
    $eventdata->userto            = $submitter;
    $eventdata->subject           = get_string('emailoverduesubject', 'gnrquiz', $a);
    $eventdata->fullmessage       = get_string('emailoverduebody', 'gnrquiz', $a);
    $eventdata->fullmessageformat = FORMAT_PLAIN;
    $eventdata->fullmessagehtml   = '';

    $eventdata->smallmessage      = get_string('emailoverduesmall', 'gnrquiz', $a);
    $eventdata->contexturl        = $a->gnrquizurl;
    $eventdata->contexturlname    = $a->gnrquizname;

    // Send the message.
    return message_send($eventdata);
}

/**
 * Handle the gnrquiz_attempt_submitted event.
 *
 * This sends the confirmation and notification messages, if required.
 *
 * @param object $event the event object.
 */
function gnrquiz_attempt_submitted_handler($event) {
    global $DB;

    $course  = $DB->get_record('course', array('id' => $event->courseid));
    $attempt = $event->get_record_snapshot('gnrquiz_attempts', $event->objectid);
    $gnrquiz    = $event->get_record_snapshot('gnrquiz', $attempt->gnrquiz);
    $cm      = get_coursemodule_from_id('gnrquiz', $event->get_context()->instanceid, $event->courseid);

    if (!($course && $gnrquiz && $cm && $attempt)) {
        // Something has been deleted since the event was raised. Therefore, the
        // event is no longer relevant.
        return true;
    }

    // Update completion state.
    $completion = new completion_info($course);
    if ($completion->is_enabled($cm) && ($gnrquiz->completionattemptsexhausted || $gnrquiz->completionpass)) {
        $completion->update_state($cm, COMPLETION_COMPLETE, $event->userid);
    }
    return gnrquiz_send_notification_messages($course, $gnrquiz, $attempt,
            context_module::instance($cm->id), $cm);
}

/**
 * Handle groups_member_added event
 *
 * @param object $event the event object.
 * @deprecated since 2.6, see {@link \mod_gnrquiz\group_observers::group_member_added()}.
 */
function gnrquiz_groups_member_added_handler($event) {
    debugging('gnrquiz_groups_member_added_handler() is deprecated, please use ' .
        '\mod_gnrquiz\group_observers::group_member_added() instead.', DEBUG_DEVELOPER);
    gnrquiz_update_open_attempts(array('userid'=>$event->userid, 'groupid'=>$event->groupid));
}

/**
 * Handle groups_member_removed event
 *
 * @param object $event the event object.
 * @deprecated since 2.6, see {@link \mod_gnrquiz\group_observers::group_member_removed()}.
 */
function gnrquiz_groups_member_removed_handler($event) {
    debugging('gnrquiz_groups_member_removed_handler() is deprecated, please use ' .
        '\mod_gnrquiz\group_observers::group_member_removed() instead.', DEBUG_DEVELOPER);
    gnrquiz_update_open_attempts(array('userid'=>$event->userid, 'groupid'=>$event->groupid));
}

/**
 * Handle groups_group_deleted event
 *
 * @param object $event the event object.
 * @deprecated since 2.6, see {@link \mod_gnrquiz\group_observers::group_deleted()}.
 */
function gnrquiz_groups_group_deleted_handler($event) {
    global $DB;
    debugging('gnrquiz_groups_group_deleted_handler() is deprecated, please use ' .
        '\mod_gnrquiz\group_observers::group_deleted() instead.', DEBUG_DEVELOPER);
    gnrquiz_process_group_deleted_in_course($event->courseid);
}

/**
 * Logic to happen when a/some group(s) has/have been deleted in a course.
 *
 * @param int $courseid The course ID.
 * @return void
 */
function gnrquiz_process_group_deleted_in_course($courseid) {
    global $DB;

    // It would be nice if we got the groupid that was deleted.
    // Instead, we just update all gnrquizzes with orphaned group overrides.
    $sql = "SELECT o.id, o.gnrquiz
              FROM {gnrquiz_overrides} o
              JOIN {gnrquiz} gnrquiz ON gnrquiz.id = o.gnrquiz
         LEFT JOIN {groups} grp ON grp.id = o.groupid
             WHERE gnrquiz.course = :courseid
               AND o.groupid IS NOT NULL
               AND grp.id IS NULL";
    $params = array('courseid' => $courseid);
    $records = $DB->get_records_sql_menu($sql, $params);
    if (!$records) {
        return; // Nothing to do.
    }
    $DB->delete_records_list('gnrquiz_overrides', 'id', array_keys($records));
    gnrquiz_update_open_attempts(array('gnrquizid' => array_unique(array_values($records))));
}

/**
 * Handle groups_members_removed event
 *
 * @param object $event the event object.
 * @deprecated since 2.6, see {@link \mod_gnrquiz\group_observers::group_member_removed()}.
 */
function gnrquiz_groups_members_removed_handler($event) {
    debugging('gnrquiz_groups_members_removed_handler() is deprecated, please use ' .
        '\mod_gnrquiz\group_observers::group_member_removed() instead.', DEBUG_DEVELOPER);
    if ($event->userid == 0) {
        gnrquiz_update_open_attempts(array('courseid'=>$event->courseid));
    } else {
        gnrquiz_update_open_attempts(array('courseid'=>$event->courseid, 'userid'=>$event->userid));
    }
}

/**
 * Get the information about the standard gnrquiz JavaScript module.
 * @return array a standard jsmodule structure.
 */
function gnrquiz_get_js_module() {
    global $PAGE;

    return array(
        'name' => 'mod_gnrquiz',
        'fullpath' => '/mod/gnrquiz/module.js',
        'requires' => array('base', 'dom', 'event-delegate', 'event-key',
                'core_question_engine', 'moodle-core-formchangechecker'),
        'strings' => array(
            array('cancel', 'moodle'),
            array('flagged', 'question'),
            array('functiondisabledbysecuremode', 'gnrquiz'),
            array('startattempt', 'gnrquiz'),
            array('timesup', 'gnrquiz'),
            array('changesmadereallygoaway', 'moodle'),
        ),
    );
}


/**
 * An extension of question_display_options that includes the extra options used
 * by the gnrquiz.
 *
 * @copyright  2010 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_gnrquiz_display_options extends question_display_options {
    /**#@+
     * @var integer bits used to indicate various times in relation to a
     * gnrquiz attempt.
     */
    const DURING =            0x10000;
    const IMMEDIATELY_AFTER = 0x01000;
    const LATER_WHILE_OPEN =  0x00100;
    const AFTER_CLOSE =       0x00010;
    /**#@-*/

    /**
     * @var boolean if this is false, then the student is not allowed to review
     * anything about the attempt.
     */
    public $attempt = true;

    /**
     * @var boolean if this is false, then the student is not allowed to review
     * anything about the attempt.
     */
    public $overallfeedback = self::VISIBLE;

    /**
     * Set up the various options from the gnrquiz settings, and a time constant.
     * @param object $gnrquiz the gnrquiz settings.
     * @param int $one of the {@link DURING}, {@link IMMEDIATELY_AFTER},
     * {@link LATER_WHILE_OPEN} or {@link AFTER_CLOSE} constants.
     * @return mod_gnrquiz_display_options set up appropriately.
     */
    public static function make_from_gnrquiz($gnrquiz, $when) {
        $options = new self();

        $options->attempt = self::extract($gnrquiz->reviewattempt, $when, true, false);
        $options->correctness = self::extract($gnrquiz->reviewcorrectness, $when);
        $options->marks = self::extract($gnrquiz->reviewmarks, $when,
                self::MARK_AND_MAX, self::MAX_ONLY);
        $options->feedback = self::extract($gnrquiz->reviewspecificfeedback, $when);
        $options->generalfeedback = self::extract($gnrquiz->reviewgeneralfeedback, $when);
        $options->rightanswer = self::extract($gnrquiz->reviewrightanswer, $when);
        $options->overallfeedback = self::extract($gnrquiz->reviewoverallfeedback, $when);

        $options->numpartscorrect = $options->feedback;
        $options->manualcomment = $options->feedback;

        if ($gnrquiz->questiondecimalpoints != -1) {
            $options->markdp = $gnrquiz->questiondecimalpoints;
        } else {
            $options->markdp = $gnrquiz->decimalpoints;
        }

        return $options;
    }

    protected static function extract($bitmask, $bit,
            $whenset = self::VISIBLE, $whennotset = self::HIDDEN) {
        if ($bitmask & $bit) {
            return $whenset;
        } else {
            return $whennotset;
        }
    }
}


/**
 * A {@link qubaid_condition} for finding all the question usages belonging to
 * a particular gnrquiz.
 *
 * @copyright  2010 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qubaids_for_gnrquiz extends qubaid_join {
    public function __construct($gnrquizid, $includepreviews = true, $onlyfinished = false) {
        $where = 'gnrquiza.gnrquiz = :gnrquizagnrquiz';
        $params = array('gnrquizagnrquiz' => $gnrquizid);

        if (!$includepreviews) {
            $where .= ' AND preview = 0';
        }

        if ($onlyfinished) {
            $where .= ' AND state == :statefinished';
            $params['statefinished'] = gnrquiz_attempt::FINISHED;
        }

        parent::__construct('{gnrquiz_attempts} gnrquiza', 'gnrquiza.uniqueid', $where, $params);
    }
}

/**
 * Creates a textual representation of a question for display.
 *
 * @param object $question A question object from the database questions table
 * @param bool $showicon If true, show the question's icon with the question. False by default.
 * @param bool $showquestiontext If true (default), show question text after question name.
 *       If false, show only question name.
 * @return string
 */
function gnrquiz_question_tostring($question, $showicon = false, $showquestiontext = true) {
    $result = '';

    $name = shorten_text(format_string($question->name), 200);
    if ($showicon) {
        $name .= print_question_icon($question) . ' ' . $name;
    }
    $result .= html_writer::span($name, 'questionname');

    if ($showquestiontext) {
        $questiontext = question_utils::to_plain_text($question->questiontext,
                $question->questiontextformat, array('noclean' => true, 'para' => false));
        $questiontext = shorten_text($questiontext, 200);
        if ($questiontext) {
            $result .= ' ' . html_writer::span(s($questiontext), 'questiontext');
        }
    }

    return $result;
}

/**
 * Verify that the question exists, and the user has permission to use it.
 * Does not return. Throws an exception if the question cannot be used.
 * @param int $questionid The id of the question.
 */
function gnrquiz_require_question_use($questionid) {
    global $DB;
    $question = $DB->get_record('question', array('id' => $questionid), '*', MUST_EXIST);
    question_require_capability_on($question, 'use');
}

/**
 * Verify that the question exists, and the user has permission to use it.
 * @param object $gnrquiz the gnrquiz settings.
 * @param int $slot which question in the gnrquiz to test.
 * @return bool whether the user can use this question.
 */
function gnrquiz_has_question_use($gnrquiz, $slot) {
    global $DB;
    $question = $DB->get_record_sql("
            SELECT q.*
              FROM {gnrquiz_slots} slot
              JOIN {question} q ON q.id = slot.questionid
             WHERE slot.gnrquizid = ? AND slot.slot = ?", array($gnrquiz->id, $slot));
    if (!$question) {
        return false;
    }
    return question_has_capability_on($question, 'use');
}

/**
 * Add a question to a gnrquiz
 *
 * Adds a question to a gnrquiz by updating $gnrquiz as well as the
 * gnrquiz and gnrquiz_slots tables. It also adds a page break if required.
 * @param int $questionid The id of the question to be added
 * @param object $gnrquiz The extended gnrquiz object as used by edit.php
 *      This is updated by this function
 * @param int $page Which page in gnrquiz to add the question on. If 0 (default),
 *      add at the end
 * @param float $maxmark The maximum mark to set for this question. (Optional,
 *      defaults to question.defaultmark.
 * @return bool false if the question was already in the gnrquiz
 */
function gnrquiz_add_gnrquiz_question($questionid, $gnrquiz, $page = 0, $maxmark = null) {
    global $DB;
    $slots = $DB->get_records('gnrquiz_slots', array('gnrquizid' => $gnrquiz->id),
            'slot', 'questionid, slot, page, id');
    if (array_key_exists($questionid, $slots)) {
        return false;
    }

    $trans = $DB->start_delegated_transaction();

    $maxpage = 1;
    $numonlastpage = 0;
    foreach ($slots as $slot) {
        if ($slot->page > $maxpage) {
            $maxpage = $slot->page;
            $numonlastpage = 1;
        } else {
            $numonlastpage += 1;
        }
    }

    // Add the new question instance.
    $slot = new stdClass();
    $slot->gnrquizid = $gnrquiz->id;
    $slot->questionid = $questionid;

    if ($maxmark !== null) {
        $slot->maxmark = $maxmark;
    } else {
        $slot->maxmark = $DB->get_field('question', 'defaultmark', array('id' => $questionid));
    }

    if (is_int($page) && $page >= 1) {
        // Adding on a given page.
        $lastslotbefore = 0;
        foreach (array_reverse($slots) as $otherslot) {
            if ($otherslot->page > $page) {
                $DB->set_field('gnrquiz_slots', 'slot', $otherslot->slot + 1, array('id' => $otherslot->id));
            } else {
                $lastslotbefore = $otherslot->slot;
                break;
            }
        }
        $slot->slot = $lastslotbefore + 1;
        $slot->page = min($page, $maxpage + 1);

        $DB->execute("
                UPDATE {gnrquiz_sections}
                   SET firstslot = firstslot + 1
                 WHERE gnrquizid = ?
                   AND firstslot > ?
                ", array($gnrquiz->id, max($lastslotbefore, 1)));

    } else {
        $lastslot = end($slots);
        if ($lastslot) {
            $slot->slot = $lastslot->slot + 1;
        } else {
            $slot->slot = 1;
        }
        if ($gnrquiz->questionsperpage && $numonlastpage >= $gnrquiz->questionsperpage) {
            $slot->page = $maxpage + 1;
        } else {
            $slot->page = $maxpage;
        }
    }

    $DB->insert_record('gnrquiz_slots', $slot);
    $trans->allow_commit();
}

/**
 * Add a random question to the gnrquiz at a given point.
 * @param object $gnrquiz the gnrquiz settings.
 * @param int $addonpage the page on which to add the question.
 * @param int $categoryid the question category to add the question from.
 * @param int $number the number of random questions to add.
 * @param bool $includesubcategories whether to include questoins from subcategories.
 */
function gnrquiz_add_random_questions($gnrquiz, $addonpage, $categoryid, $number,
        $includesubcategories) {
    global $DB;

    $category = $DB->get_record('question_categories', array('id' => $categoryid));
    if (!$category) {
        print_error('invalidcategoryid', 'error');
    }

    $catcontext = context::instance_by_id($category->contextid);
    require_capability('moodle/question:useall', $catcontext);

    // Find existing random questions in this category that are
    // not used by any gnrquiz.
    if ($existingquestions = $DB->get_records_sql(
            "SELECT q.id, q.qtype FROM {question} q
            WHERE qtype = 'random'
                AND category = ?
                AND " . $DB->sql_compare_text('questiontext') . " = ?
                AND NOT EXISTS (
                        SELECT *
                          FROM {gnrquiz_slots}
                         WHERE questionid = q.id)
            ORDER BY id", array($category->id, ($includesubcategories ? '1' : '0')))) {
            // Take as many of these as needed.
        while (($existingquestion = array_shift($existingquestions)) && $number > 0) {
            gnrquiz_add_gnrquiz_question($existingquestion->id, $gnrquiz, $addonpage);
            $number -= 1;
        }
    }

    if ($number <= 0) {
        return;
    }

    // More random questions are needed, create them.
    for ($i = 0; $i < $number; $i += 1) {
        $form = new stdClass();
        $form->questiontext = array('text' => ($includesubcategories ? '1' : '0'), 'format' => 0);
        $form->category = $category->id . ',' . $category->contextid;
        $form->defaultmark = 1;
        $form->hidden = 1;
        $form->stamp = make_unique_id_code(); // Set the unique code (not to be changed).
        $question = new stdClass();
        $question->qtype = 'random';
        $question = question_bank::get_qtype('random')->save_question($question, $form);
        if (!isset($question->id)) {
            print_error('cannotinsertrandomquestion', 'gnrquiz');
        }
        gnrquiz_add_gnrquiz_question($question->id, $gnrquiz, $addonpage);
    }
}

/**
 * Mark the activity completed (if required) and trigger the course_module_viewed event.
 *
 * @param  stdClass $gnrquiz       gnrquiz object
 * @param  stdClass $course     course object
 * @param  stdClass $cm         course module object
 * @param  stdClass $context    context object
 * @since Moodle 3.1
 */
function gnrquiz_view($gnrquiz, $course, $cm, $context) {

    $params = array(
        'objectid' => $gnrquiz->id,
        'context' => $context
    );

    $event = \mod_gnrquiz\event\course_module_viewed::create($params);
    $event->add_record_snapshot('gnrquiz', $gnrquiz);
    $event->trigger();

    // Completion.
    $completion = new completion_info($course);
    $completion->set_module_viewed($cm);
}

/**
 * Validate permissions for creating a new attempt and start a new preview attempt if required.
 *
 * @param  gnrquiz $gnrquizobj gnrquiz object
 * @param  gnrquiz_access_manager $accessmanager gnrquiz access manager
 * @param  bool $forcenew whether was required to start a new preview attempt
 * @param  int $page page to jump to in the attempt
 * @param  bool $redirect whether to redirect or throw exceptions (for web or ws usage)
 * @return array an array containing the attempt information, access error messages and the page to jump to in the attempt
 * @throws moodle_gnrquiz_exception
 * @since Moodle 3.1
 */
function gnrquiz_validate_new_attempt(gnrquiz $gnrquizobj, gnrquiz_access_manager $accessmanager, $forcenew, $page, $redirect) {
    global $DB, $USER;
    $timenow = time();

    if ($gnrquizobj->is_preview_user() && $forcenew) {
        $accessmanager->current_attempt_finished();
    }

    // Check capabilities.
    if (!$gnrquizobj->is_preview_user()) {
        $gnrquizobj->require_capability('mod/gnrquiz:attempt');
    }

    // Check to see if a new preview was requested.
    if ($gnrquizobj->is_preview_user() && $forcenew) {
        // To force the creation of a new preview, we mark the current attempt (if any)
        // as finished. It will then automatically be deleted below.
        $DB->set_field('gnrquiz_attempts', 'state', gnrquiz_attempt::FINISHED,
                array('gnrquiz' => $gnrquizobj->get_gnrquizid(), 'userid' => $USER->id));
    }

    // Look for an existing attempt.
    $attempts = gnrquiz_get_user_attempts($gnrquizobj->get_gnrquizid(), $USER->id, 'all', true);
    $lastattempt = end($attempts);

    $attemptnumber = null;
    // If an in-progress attempt exists, check password then redirect to it.
    if ($lastattempt && ($lastattempt->state == gnrquiz_attempt::IN_PROGRESS ||
            $lastattempt->state == gnrquiz_attempt::OVERDUE)) {
        $currentattemptid = $lastattempt->id;
        $messages = $accessmanager->prevent_access();

        // If the attempt is now overdue, deal with that.
        $gnrquizobj->create_attempt_object($lastattempt)->handle_if_time_expired($timenow, true);

        // And, if the attempt is now no longer in progress, redirect to the appropriate place.
        if ($lastattempt->state == gnrquiz_attempt::ABANDONED || $lastattempt->state == gnrquiz_attempt::FINISHED) {
            if ($redirect) {
                redirect($gnrquizobj->review_url($lastattempt->id));
            } else {
                throw new moodle_gnrquiz_exception($gnrquizobj, 'attemptalreadyclosed');
            }
        }

        // If the page number was not explicitly in the URL, go to the current page.
        if ($page == -1) {
            $page = $lastattempt->currentpage;
        }

    } else {
        while ($lastattempt && $lastattempt->preview) {
            $lastattempt = array_pop($attempts);
        }

        // Get number for the next or unfinished attempt.
        if ($lastattempt) {
            $attemptnumber = $lastattempt->attempt + 1;
        } else {
            $lastattempt = false;
            $attemptnumber = 1;
        }
        $currentattemptid = null;

        $messages = $accessmanager->prevent_access() +
            $accessmanager->prevent_new_attempt(count($attempts), $lastattempt);

        if ($page == -1) {
            $page = 0;
        }
    }
    return array($currentattemptid, $attemptnumber, $lastattempt, $messages, $page);
}

/**
 * Prepare and start a new attempt deleting the previous preview attempts.
 *
 * @param  gnrquiz $gnrquizobj gnrquiz object
 * @param  int $attemptnumber the attempt number
 * @param  object $lastattempt last attempt object
 * @return object the new attempt
 * @since  Moodle 3.1
 */
function gnrquiz_prepare_and_start_new_attempt(gnrquiz $gnrquizobj, $attemptnumber, $lastattempt) {
    global $DB, $USER;

    // Delete any previous preview attempts belonging to this user.
    gnrquiz_delete_previews($gnrquizobj->get_gnrquiz(), $USER->id);

    $quba = question_engine::make_questions_usage_by_activity('mod_gnrquiz', $gnrquizobj->get_context());
    $quba->set_preferred_behaviour($gnrquizobj->get_gnrquiz()->preferredbehaviour);

    // Create the new attempt and initialize the question sessions
    $timenow = time(); // Update time now, in case the server is running really slowly.
    $attempt = gnrquiz_create_attempt($gnrquizobj, $attemptnumber, $lastattempt, $timenow, $gnrquizobj->is_preview_user());

    if (!($gnrquizobj->get_gnrquiz()->attemptonlast && $lastattempt)) {
        $attempt = gnrquiz_start_new_attempt($gnrquizobj, $quba, $attempt, $attemptnumber, $timenow);
    } else {
        $attempt = gnrquiz_start_attempt_built_on_last($quba, $attempt, $lastattempt);
    }

    $transaction = $DB->start_delegated_transaction();

    $attempt = gnrquiz_attempt_save_started($gnrquizobj, $quba, $attempt);

    $transaction->allow_commit();

    return $attempt;
}
