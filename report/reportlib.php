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
 * Helper functions for the gnrquiz reports.
 *
 * @package   mod_gnrquiz
 * @copyright 2008 Jamie Pratt
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/gnrquiz/lib.php');
require_once($CFG->libdir . '/filelib.php');

/**
 * Takes an array of objects and constructs a multidimensional array keyed by
 * the keys it finds on the object.
 * @param array $datum an array of objects with properties on the object
 * including the keys passed as the next param.
 * @param array $keys Array of strings with the names of the properties on the
 * objects in datum that you want to index the multidimensional array by.
 * @param bool $keysunique If there is not only one object for each
 * combination of keys you are using you should set $keysunique to true.
 * Otherwise all the object will be added to a zero based array. So the array
 * returned will have count($keys) + 1 indexs.
 * @return array multidimensional array properly indexed.
 */
function gnrquiz_report_index_by_keys($datum, $keys, $keysunique = true) {
    if (!$datum) {
        return array();
    }
    $key = array_shift($keys);
    $datumkeyed = array();
    foreach ($datum as $data) {
        if ($keys || !$keysunique) {
            $datumkeyed[$data->{$key}][]= $data;
        } else {
            $datumkeyed[$data->{$key}]= $data;
        }
    }
    if ($keys) {
        foreach ($datumkeyed as $datakey => $datakeyed) {
            $datumkeyed[$datakey] = gnrquiz_report_index_by_keys($datakeyed, $keys, $keysunique);
        }
    }
    return $datumkeyed;
}

function gnrquiz_report_unindex($datum) {
    if (!$datum) {
        return $datum;
    }
    $datumunkeyed = array();
    foreach ($datum as $value) {
        if (is_array($value)) {
            $datumunkeyed = array_merge($datumunkeyed, gnrquiz_report_unindex($value));
        } else {
            $datumunkeyed[] = $value;
        }
    }
    return $datumunkeyed;
}

/**
 * Are there any questions in this gnrquiz?
 * @param int $gnrquizid the gnrquiz id.
 */
function gnrquiz_has_questions($gnrquizid) {
    global $DB;
    return $DB->record_exists('gnrquiz_slots', array('gnrquizid' => $gnrquizid));
}

/**
 * Get the slots of real questions (not descriptions) in this gnrquiz, in order.
 * @param object $gnrquiz the gnrquiz.
 * @return array of slot => $question object with fields
 *      ->slot, ->id, ->maxmark, ->number, ->length.
 */
function gnrquiz_report_get_significant_questions($gnrquiz) {
    global $DB;

    $qsbyslot = $DB->get_records_sql("
            SELECT slot.slot,
                   q.id,
                   q.length,
                   slot.maxmark

              FROM {question} q
              JOIN {gnrquiz_slots} slot ON slot.questionid = q.id

             WHERE slot.gnrquizid = ?
               AND q.length > 0

          ORDER BY slot.slot", array($gnrquiz->id));

    $number = 1;
    foreach ($qsbyslot as $question) {
        $question->number = $number;
        $number += $question->length;
    }

    return $qsbyslot;
}

/**
 * @param object $gnrquiz the gnrquiz settings.
 * @return bool whether, for this gnrquiz, it is possible to filter attempts to show
 *      only those that gave the final grade.
 */
function gnrquiz_report_can_filter_only_graded($gnrquiz) {
    return $gnrquiz->attempts != 1 && $gnrquiz->grademethod != GNRQUIZ_GRADEAVERAGE;
}

/**
 * This is a wrapper for {@link gnrquiz_report_grade_method_sql} that takes the whole gnrquiz object instead of just the grading method
 * as a param. See definition for {@link gnrquiz_report_grade_method_sql} below.
 *
 * @param object $gnrquiz
 * @param string $gnrquizattemptsalias sql alias for 'gnrquiz_attempts' table
 * @return string sql to test if this is an attempt that will contribute towards the grade of the user
 */
function gnrquiz_report_qm_filter_select($gnrquiz, $gnrquizattemptsalias = 'gnrquiza') {
    if ($gnrquiz->attempts == 1) {
        // This gnrquiz only allows one attempt.
        return '';
    }
    return gnrquiz_report_grade_method_sql($gnrquiz->grademethod, $gnrquizattemptsalias);
}

/**
 * Given a gnrquiz grading method return sql to test if this is an
 * attempt that will be contribute towards the grade of the user. Or return an
 * empty string if the grading method is GNRQUIZ_GRADEAVERAGE and thus all attempts
 * contribute to final grade.
 *
 * @param string $grademethod gnrquiz grading method.
 * @param string $gnrquizattemptsalias sql alias for 'gnrquiz_attempts' table
 * @return string sql to test if this is an attempt that will contribute towards the graded of the user
 */
function gnrquiz_report_grade_method_sql($grademethod, $gnrquizattemptsalias = 'gnrquiza') {
    switch ($grademethod) {
        case GNRQUIZ_GRADEHIGHEST :
            return "($gnrquizattemptsalias.state = 'finished' AND NOT EXISTS (
                           SELECT 1 FROM {gnrquiz_attempts} qa2
                            WHERE qa2.gnrquiz = $gnrquizattemptsalias.gnrquiz AND
                                qa2.userid = $gnrquizattemptsalias.userid AND
                                 qa2.state = 'finished' AND (
                COALESCE(qa2.sumgrades, 0) > COALESCE($gnrquizattemptsalias.sumgrades, 0) OR
               (COALESCE(qa2.sumgrades, 0) = COALESCE($gnrquizattemptsalias.sumgrades, 0) AND qa2.attempt < $gnrquizattemptsalias.attempt)
                                )))";

        case GNRQUIZ_GRADEAVERAGE :
            return '';

        case GNRQUIZ_ATTEMPTFIRST :
            return "($gnrquizattemptsalias.state = 'finished' AND NOT EXISTS (
                           SELECT 1 FROM {gnrquiz_attempts} qa2
                            WHERE qa2.gnrquiz = $gnrquizattemptsalias.gnrquiz AND
                                qa2.userid = $gnrquizattemptsalias.userid AND
                                 qa2.state = 'finished' AND
                               qa2.attempt < $gnrquizattemptsalias.attempt))";

        case GNRQUIZ_ATTEMPTLAST :
            return "($gnrquizattemptsalias.state = 'finished' AND NOT EXISTS (
                           SELECT 1 FROM {gnrquiz_attempts} qa2
                            WHERE qa2.gnrquiz = $gnrquizattemptsalias.gnrquiz AND
                                qa2.userid = $gnrquizattemptsalias.userid AND
                                 qa2.state = 'finished' AND
                               qa2.attempt > $gnrquizattemptsalias.attempt))";
    }
}

/**
 * Get the number of students whose score was in a particular band for this gnrquiz.
 * @param number $bandwidth the width of each band.
 * @param int $bands the number of bands
 * @param int $gnrquizid the gnrquiz id.
 * @param array $userids list of user ids.
 * @return array band number => number of users with scores in that band.
 */
function gnrquiz_report_grade_bands($bandwidth, $bands, $gnrquizid, $userids = array()) {
    global $DB;
    if (!is_int($bands)) {
        debugging('$bands passed to gnrquiz_report_grade_bands must be an integer. (' .
                gettype($bands) . ' passed.)', DEBUG_DEVELOPER);
        $bands = (int) $bands;
    }

    if ($userids) {
        list($usql, $params) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'u');
        $usql = "qg.userid $usql AND";
    } else {
        $usql = '';
        $params = array();
    }
    $sql = "
SELECT band, COUNT(1)

FROM (
    SELECT FLOOR(qg.grade / :bandwidth) AS band
      FROM {gnrquiz_grades} qg
     WHERE $usql qg.gnrquiz = :gnrquizid
) subquery

GROUP BY
    band

ORDER BY
    band";

    $params['gnrquizid'] = $gnrquizid;
    $params['bandwidth'] = $bandwidth;

    $data = $DB->get_records_sql_menu($sql, $params);

    // We need to create array elements with values 0 at indexes where there is no element.
    $data = $data + array_fill(0, $bands + 1, 0);
    ksort($data);

    // Place the maximum (perfect grade) into the last band i.e. make last
    // band for example 9 <= g <=10 (where 10 is the perfect grade) rather than
    // just 9 <= g <10.
    $data[$bands - 1] += $data[$bands];
    unset($data[$bands]);

    return $data;
}

function gnrquiz_report_highlighting_grading_method($gnrquiz, $qmsubselect, $qmfilter) {
    if ($gnrquiz->attempts == 1) {
        return '<p>' . get_string('onlyoneattemptallowed', 'gnrquiz_overview') . '</p>';

    } else if (!$qmsubselect) {
        return '<p>' . get_string('allattemptscontributetograde', 'gnrquiz_overview') . '</p>';

    } else if ($qmfilter) {
        return '<p>' . get_string('showinggraded', 'gnrquiz_overview') . '</p>';

    } else {
        return '<p>' . get_string('showinggradedandungraded', 'gnrquiz_overview',
                '<span class="gradedattempt">' . gnrquiz_get_grading_option_name($gnrquiz->grademethod) .
                '</span>') . '</p>';
    }
}

/**
 * Get the feedback text for a grade on this gnrquiz. The feedback is
 * processed ready for display.
 *
 * @param float $grade a grade on this gnrquiz.
 * @param int $gnrquizid the id of the gnrquiz object.
 * @return string the comment that corresponds to this grade (empty string if there is not one.
 */
function gnrquiz_report_feedback_for_grade($grade, $gnrquizid, $context) {
    global $DB;

    static $feedbackcache = array();

    if (!isset($feedbackcache[$gnrquizid])) {
        $feedbackcache[$gnrquizid] = $DB->get_records('gnrquiz_feedback', array('gnrquizid' => $gnrquizid));
    }

    // With CBM etc, it is possible to get -ve grades, which would then not match
    // any feedback. Therefore, we replace -ve grades with 0.
    $grade = max($grade, 0);

    $feedbacks = $feedbackcache[$gnrquizid];
    $feedbackid = 0;
    $feedbacktext = '';
    $feedbacktextformat = FORMAT_MOODLE;
    foreach ($feedbacks as $feedback) {
        if ($feedback->mingrade <= $grade && $grade < $feedback->maxgrade) {
            $feedbackid = $feedback->id;
            $feedbacktext = $feedback->feedbacktext;
            $feedbacktextformat = $feedback->feedbacktextformat;
            break;
        }
    }

    // Clean the text, ready for display.
    $formatoptions = new stdClass();
    $formatoptions->noclean = true;
    $feedbacktext = file_rewrite_pluginfile_urls($feedbacktext, 'pluginfile.php',
            $context->id, 'mod_gnrquiz', 'feedback', $feedbackid);
    $feedbacktext = format_text($feedbacktext, $feedbacktextformat, $formatoptions);

    return $feedbacktext;
}

/**
 * Format a number as a percentage out of $gnrquiz->sumgrades
 * @param number $rawgrade the mark to format.
 * @param object $gnrquiz the gnrquiz settings
 * @param bool $round whether to round the results ot $gnrquiz->decimalpoints.
 */
function gnrquiz_report_scale_summarks_as_percentage($rawmark, $gnrquiz, $round = true) {
    if ($gnrquiz->sumgrades == 0) {
        return '';
    }
    if (!is_numeric($rawmark)) {
        return $rawmark;
    }

    $mark = $rawmark * 100 / $gnrquiz->sumgrades;
    if ($round) {
        $mark = gnrquiz_format_grade($gnrquiz, $mark);
    }
    return $mark . '%';
}

/**
 * Returns an array of reports to which the current user has access to.
 * @return array reports are ordered as they should be for display in tabs.
 */
function gnrquiz_report_list($context) {
    global $DB;
    static $reportlist = null;
    if (!is_null($reportlist)) {
        return $reportlist;
    }

    $reports = $DB->get_records('gnrquiz_reports', null, 'displayorder DESC', 'name, capability');
    $reportdirs = core_component::get_plugin_list('gnrquiz');

    // Order the reports tab in descending order of displayorder.
    $reportcaps = array();
    foreach ($reports as $key => $report) {
        if (array_key_exists($report->name, $reportdirs)) {
            $reportcaps[$report->name] = $report->capability;
        }
    }

    // Add any other reports, which are on disc but not in the DB, on the end.
    foreach ($reportdirs as $reportname => $notused) {
        if (!isset($reportcaps[$reportname])) {
            $reportcaps[$reportname] = null;
        }
    }
    $reportlist = array();
    foreach ($reportcaps as $name => $capability) {
        if (empty($capability)) {
            $capability = 'mod/gnrquiz:viewreports';
        }
        if (has_capability($capability, $context)) {
            $reportlist[] = $name;
        }
    }
    return $reportlist;
}

/**
 * Create a filename for use when downloading data from a gnrquiz report. It is
 * expected that this will be passed to flexible_table::is_downloading, which
 * cleans the filename of bad characters and adds the file extension.
 * @param string $report the type of report.
 * @param string $courseshortname the course shortname.
 * @param string $gnrquizname the gnrquiz name.
 * @return string the filename.
 */
function gnrquiz_report_download_filename($report, $courseshortname, $gnrquizname) {
    return $courseshortname . '-' . format_string($gnrquizname, true) . '-' . $report;
}

/**
 * Get the default report for the current user.
 * @param object $context the gnrquiz context.
 */
function gnrquiz_report_default_report($context) {
    $reports = gnrquiz_report_list($context);
    return reset($reports);
}

/**
 * Generate a message saying that this gnrquiz has no questions, with a button to
 * go to the edit page, if the user has the right capability.
 * @param object $gnrquiz the gnrquiz settings.
 * @param object $cm the course_module object.
 * @param object $context the gnrquiz context.
 * @return string HTML to output.
 */
function gnrquiz_no_questions_message($gnrquiz, $cm, $context) {
    global $OUTPUT;

    $output = '';
    $output .= $OUTPUT->notification(get_string('noquestions', 'gnrquiz'));
    if (has_capability('mod/gnrquiz:manage', $context)) {
        $output .= $OUTPUT->single_button(new moodle_url('/mod/gnrquiz/edit.php',
        array('cmid' => $cm->id)), get_string('editgnrquiz', 'gnrquiz'), 'get');
    }

    return $output;
}

/**
 * Should the grades be displayed in this report. That depends on the gnrquiz
 * display options, and whether the gnrquiz is graded.
 * @param object $gnrquiz the gnrquiz settings.
 * @param context $context the gnrquiz context.
 * @return bool
 */
function gnrquiz_report_should_show_grades($gnrquiz, context $context) {
    if ($gnrquiz->timeclose && time() > $gnrquiz->timeclose) {
        $when = mod_gnrquiz_display_options::AFTER_CLOSE;
    } else {
        $when = mod_gnrquiz_display_options::LATER_WHILE_OPEN;
    }
    $reviewoptions = mod_gnrquiz_display_options::make_from_gnrquiz($gnrquiz, $when);

    return gnrquiz_has_grades($gnrquiz) &&
            ($reviewoptions->marks >= question_display_options::MARK_AND_MAX ||
            has_capability('moodle/grade:viewhidden', $context));
}
