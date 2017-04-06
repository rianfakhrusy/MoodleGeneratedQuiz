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
 * Tests for the gnrquiz overview report.
 *
 * @package   gnrquiz_overview
 * @copyright 2014 The Open University
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/gnrquiz/locallib.php');
require_once($CFG->dirroot . '/mod/gnrquiz/report/reportlib.php');
require_once($CFG->dirroot . '/mod/gnrquiz/report/default.php');
require_once($CFG->dirroot . '/mod/gnrquiz/report/overview/report.php');


/**
 * Tests for the gnrquiz overview report.
 *
 * @copyright  2014 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class gnrquiz_overview_report_testcase extends advanced_testcase {

    public function test_report_sql() {
        global $DB, $SITE;
        $this->resetAfterTest(true);

        $generator = $this->getDataGenerator();
        $gnrquizgenerator = $generator->get_plugin_generator('mod_gnrquiz');
        $gnrquiz = $gnrquizgenerator->create_instance(array('course' => $SITE->id,
                'grademethod' => GNRQUIZ_GRADEHIGHEST, 'grade' => 100.0, 'sumgrades' => 10.0,
                'attempts' => 10));

        $student1 = $generator->create_user();
        $student2 = $generator->create_user();
        $student3 = $generator->create_user();

        $gnrquizid = 123;
        $timestamp = 1234567890;

        // The test data.
        $fields = array('gnrquiz', 'userid', 'attempt', 'sumgrades', 'state');
        $attempts = array(
            array($gnrquiz->id, $student1->id, 1, 0.0,  gnrquiz_attempt::FINISHED),
            array($gnrquiz->id, $student1->id, 2, 5.0,  gnrquiz_attempt::FINISHED),
            array($gnrquiz->id, $student1->id, 3, 8.0,  gnrquiz_attempt::FINISHED),
            array($gnrquiz->id, $student1->id, 4, null, gnrquiz_attempt::ABANDONED),
            array($gnrquiz->id, $student1->id, 5, null, gnrquiz_attempt::IN_PROGRESS),
            array($gnrquiz->id, $student2->id, 1, null, gnrquiz_attempt::ABANDONED),
            array($gnrquiz->id, $student2->id, 2, null, gnrquiz_attempt::ABANDONED),
            array($gnrquiz->id, $student2->id, 3, 7.0,  gnrquiz_attempt::FINISHED),
            array($gnrquiz->id, $student2->id, 4, null, gnrquiz_attempt::ABANDONED),
            array($gnrquiz->id, $student2->id, 5, null, gnrquiz_attempt::ABANDONED),
        );

        // Load it in to gnrquiz attempts table.
        $uniqueid = 1;
        foreach ($attempts as $attempt) {
            $data = array_combine($fields, $attempt);
            $data['timestart'] = $timestamp + 3600 * $data['attempt'];
            $data['timemodifed'] = $data['timestart'];
            if ($data['state'] == gnrquiz_attempt::FINISHED) {
                $data['timefinish'] = $data['timestart'] + 600;
                $data['timemodifed'] = $data['timefinish'];
            }
            $data['layout'] = ''; // Not used, but cannot be null.
            $data['uniqueid'] = $uniqueid++;
            $data['preview'] = 0;
            $DB->insert_record('gnrquiz_attempts', $data);
        }

        // Actually getting the SQL to run is quit hard. Do a minimal set up of
        // some objects.
        $context = context_module::instance($gnrquiz->cmid);
        $cm = get_coursemodule_from_id('gnrquiz', $gnrquiz->cmid);
        $qmsubselect = gnrquiz_report_qm_filter_select($gnrquiz);
        $reportstudents = array($student1->id, $student2->id, $student3->id);

        // Set the options.
        $reportoptions = new gnrquiz_overview_options('overview', $gnrquiz, $cm, null);
        $reportoptions->attempts = gnrquiz_attempts_report::ENROLLED_ALL;
        $reportoptions->onlygraded = true;
        $reportoptions->states = array(gnrquiz_attempt::IN_PROGRESS, gnrquiz_attempt::OVERDUE, gnrquiz_attempt::FINISHED);

        // Now do a minimal set-up of the table class.
        $table = new gnrquiz_overview_table($gnrquiz, $context, $qmsubselect, $reportoptions,
                array(), $reportstudents, array(1), null);
        $table->define_columns(array('attempt'));
        $table->sortable(true, 'uniqueid');
        $table->define_baseurl(new moodle_url('/mod/gnrquiz/report.php'));
        $table->setup();

        // Run the query.
        list($fields, $from, $where, $params) = $table->base_sql($reportstudents);
        $table->set_sql($fields, $from, $where, $params);
        $table->query_db(30, false);

        // Verify what was returned: Student 1's best and in progress attempts.
        // Stuent 2's finshed attempt, and Student 3 with no attempt.
        // The array key is {student id}#{attempt number}.
        $this->assertEquals(4, count($table->rawdata));
        $this->assertArrayHasKey($student1->id . '#3', $table->rawdata);
        $this->assertEquals(1, $table->rawdata[$student1->id . '#3']->gradedattempt);
        $this->assertArrayHasKey($student1->id . '#3', $table->rawdata);
        $this->assertEquals(0, $table->rawdata[$student1->id . '#5']->gradedattempt);
        $this->assertArrayHasKey($student2->id . '#3', $table->rawdata);
        $this->assertEquals(1, $table->rawdata[$student2->id . '#3']->gradedattempt);
        $this->assertArrayHasKey($student3->id . '#0', $table->rawdata);
        $this->assertEquals(0, $table->rawdata[$student3->id . '#0']->gradedattempt);
    }
}
