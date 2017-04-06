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
 * Unit tests for (some of) mod/gnrquiz/report/reportlib.php
 *
 * @package   mod_gnrquiz
 * @category  phpunit
 * @copyright 2008 Jamie Pratt me@jamiep.org
 * @license   http://www.gnu.org/copyleft/gpl.html GNU Public License
 */


defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/gnrquiz/report/reportlib.php');


/**
 * This class contains the test cases for the functions in reportlib.php.
 *
 * @copyright 2008 Jamie Pratt me@jamiep.org
 * @license   http://www.gnu.org/copyleft/gpl.html GNU Public License
 */
class mod_gnrquiz_reportlib_testcase extends advanced_testcase {
    public function test_gnrquiz_report_index_by_keys() {
        $datum = array();
        $object = new stdClass();
        $object->qid = 3;
        $object->aid = 101;
        $object->response = '';
        $object->grade = 3;
        $datum[] = $object;

        $indexed = gnrquiz_report_index_by_keys($datum, array('aid', 'qid'));

        $this->assertEquals($indexed[101][3]->qid, 3);
        $this->assertEquals($indexed[101][3]->aid, 101);
        $this->assertEquals($indexed[101][3]->response, '');
        $this->assertEquals($indexed[101][3]->grade, 3);

        $indexed = gnrquiz_report_index_by_keys($datum, array('aid', 'qid'), false);

        $this->assertEquals($indexed[101][3][0]->qid, 3);
        $this->assertEquals($indexed[101][3][0]->aid, 101);
        $this->assertEquals($indexed[101][3][0]->response, '');
        $this->assertEquals($indexed[101][3][0]->grade, 3);
    }

    public function test_gnrquiz_report_scale_summarks_as_percentage() {
        $gnrquiz = new stdClass();
        $gnrquiz->sumgrades = 10;
        $gnrquiz->decimalpoints = 2;

        $this->assertEquals('12.34567%',
            gnrquiz_report_scale_summarks_as_percentage(1.234567, $gnrquiz, false));
        $this->assertEquals('12.35%',
            gnrquiz_report_scale_summarks_as_percentage(1.234567, $gnrquiz, true));
        $this->assertEquals('-',
            gnrquiz_report_scale_summarks_as_percentage('-', $gnrquiz, true));
    }

    public function test_gnrquiz_report_qm_filter_select_only_one_attempt_allowed() {
        $gnrquiz = new stdClass();
        $gnrquiz->attempts = 1;
        $this->assertSame('', gnrquiz_report_qm_filter_select($gnrquiz));
    }

    public function test_gnrquiz_report_qm_filter_select_average() {
        $gnrquiz = new stdClass();
        $gnrquiz->attempts = 10;
        $gnrquiz->grademethod = GNRQUIZ_GRADEAVERAGE;
        $this->assertSame('', gnrquiz_report_qm_filter_select($gnrquiz));
    }

    public function test_gnrquiz_report_qm_filter_select_first_last_best() {
        global $DB;
        $this->resetAfterTest();

        $fakeattempt = new stdClass();
        $fakeattempt->userid = 123;
        $fakeattempt->gnrquiz = 456;
        $fakeattempt->layout = '1,2,0,3,4,0,5';
        $fakeattempt->state = gnrquiz_attempt::FINISHED;

        // We intentionally insert these in a funny order, to test the SQL better.
        // The test data is:
        // id | gnrquizid | user | attempt | sumgrades | state
        // ---------------------------------------------------
        // 4  | 456    | 123  | 1       | 30        | finished
        // 2  | 456    | 123  | 2       | 50        | finished
        // 1  | 456    | 123  | 3       | 50        | finished
        // 3  | 456    | 123  | 4       | null      | inprogress
        // 5  | 456    | 1    | 1       | 100       | finished
        // layout is only given because it has a not-null constraint.
        // uniqueid values are meaningless, but that column has a unique constraint.

        $fakeattempt->attempt = 3;
        $fakeattempt->sumgrades = 50;
        $fakeattempt->uniqueid = 13;
        $DB->insert_record('gnrquiz_attempts', $fakeattempt);

        $fakeattempt->attempt = 2;
        $fakeattempt->sumgrades = 50;
        $fakeattempt->uniqueid = 26;
        $DB->insert_record('gnrquiz_attempts', $fakeattempt);

        $fakeattempt->attempt = 4;
        $fakeattempt->sumgrades = null;
        $fakeattempt->uniqueid = 39;
        $fakeattempt->state = gnrquiz_attempt::IN_PROGRESS;
        $DB->insert_record('gnrquiz_attempts', $fakeattempt);

        $fakeattempt->attempt = 1;
        $fakeattempt->sumgrades = 30;
        $fakeattempt->uniqueid = 52;
        $fakeattempt->state = gnrquiz_attempt::FINISHED;
        $DB->insert_record('gnrquiz_attempts', $fakeattempt);

        $fakeattempt->attempt = 1;
        $fakeattempt->userid = 1;
        $fakeattempt->sumgrades = 100;
        $fakeattempt->uniqueid = 65;
        $DB->insert_record('gnrquiz_attempts', $fakeattempt);

        $gnrquiz = new stdClass();
        $gnrquiz->attempts = 10;

        $gnrquiz->grademethod = GNRQUIZ_ATTEMPTFIRST;
        $firstattempt = $DB->get_records_sql("
                SELECT * FROM {gnrquiz_attempts} gnrquiza WHERE userid = ? AND gnrquiz = ? AND "
                        . gnrquiz_report_qm_filter_select($gnrquiz), array(123, 456));
        $this->assertEquals(1, count($firstattempt));
        $firstattempt = reset($firstattempt);
        $this->assertEquals(1, $firstattempt->attempt);

        $gnrquiz->grademethod = GNRQUIZ_ATTEMPTLAST;
        $lastattempt = $DB->get_records_sql("
                SELECT * FROM {gnrquiz_attempts} gnrquiza WHERE userid = ? AND gnrquiz = ? AND "
                . gnrquiz_report_qm_filter_select($gnrquiz), array(123, 456));
        $this->assertEquals(1, count($lastattempt));
        $lastattempt = reset($lastattempt);
        $this->assertEquals(3, $lastattempt->attempt);

        $gnrquiz->attempts = 0;
        $gnrquiz->grademethod = GNRQUIZ_GRADEHIGHEST;
        $bestattempt = $DB->get_records_sql("
                SELECT * FROM {gnrquiz_attempts} qa_alias WHERE userid = ? AND gnrquiz = ? AND "
                . gnrquiz_report_qm_filter_select($gnrquiz, 'qa_alias'), array(123, 456));
        $this->assertEquals(1, count($bestattempt));
        $bestattempt = reset($bestattempt);
        $this->assertEquals(2, $bestattempt->attempt);
    }
}
