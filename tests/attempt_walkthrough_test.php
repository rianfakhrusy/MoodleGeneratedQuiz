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
 * Quiz attempt walk through tests.
 *
 * @package    mod_gnrquiz
 * @category   phpunit
 * @copyright  2013 The Open University
 * @author     Jamie Pratt <me@jamiep.org>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/gnrquiz/locallib.php');

/**
 * Quiz attempt walk through.
 *
 * @package    mod_gnrquiz
 * @category   phpunit
 * @copyright  2013 The Open University
 * @author     Jamie Pratt <me@jamiep.org>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_gnrquiz_attempt_walkthrough_testcase extends advanced_testcase {

    /**
     * Create a gnrquiz with questions and walk through a gnrquiz attempt.
     */
    public function test_gnrquiz_attempt_walkthrough() {
        global $SITE;

        $this->resetAfterTest(true);

        // Make a gnrquiz.
        $gnrquizgenerator = $this->getDataGenerator()->get_plugin_generator('mod_gnrquiz');

        $gnrquiz = $gnrquizgenerator->create_instance(array('course'=>$SITE->id, 'questionsperpage' => 0, 'grade' => 100.0,
                                                      'sumgrades' => 2));

        // Create a couple of questions.
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');

        $cat = $questiongenerator->create_question_category();
        $saq = $questiongenerator->create_question('shortanswer', null, array('category' => $cat->id));
        $numq = $questiongenerator->create_question('numerical', null, array('category' => $cat->id));

        // Add them to the gnrquiz.
        gnrquiz_add_gnrquiz_question($saq->id, $gnrquiz);
        gnrquiz_add_gnrquiz_question($numq->id, $gnrquiz);

        // Make a user to do the gnrquiz.
        $user1 = $this->getDataGenerator()->create_user();

        $gnrquizobj = gnrquiz::create($gnrquiz->id, $user1->id);

        // Start the attempt.
        $quba = question_engine::make_questions_usage_by_activity('mod_gnrquiz', $gnrquizobj->get_context());
        $quba->set_preferred_behaviour($gnrquizobj->get_gnrquiz()->preferredbehaviour);

        $timenow = time();
        $attempt = gnrquiz_create_attempt($gnrquizobj, 1, false, $timenow, false, $user1->id);

        gnrquiz_start_new_attempt($gnrquizobj, $quba, $attempt, 1, $timenow);
        $this->assertEquals('1,2,0', $attempt->layout);

        gnrquiz_attempt_save_started($gnrquizobj, $quba, $attempt);

        // Process some responses from the student.
        $attemptobj = gnrquiz_attempt::create($attempt->id);
        $this->assertFalse($attemptobj->has_response_to_at_least_one_graded_question());

        $prefix1 = $quba->get_field_prefix(1);
        $prefix2 = $quba->get_field_prefix(2);

        $tosubmit = array(1 => array('answer' => 'frog'),
                          2 => array('answer' => '3.14'));

        $attemptobj->process_submitted_actions($timenow, false, $tosubmit);

        // Finish the attempt.
        $attemptobj = gnrquiz_attempt::create($attempt->id);
        $this->assertTrue($attemptobj->has_response_to_at_least_one_graded_question());
        $attemptobj->process_finish($timenow, false);

        // Re-load gnrquiz attempt data.
        $attemptobj = gnrquiz_attempt::create($attempt->id);

        // Check that results are stored as expected.
        $this->assertEquals(1, $attemptobj->get_attempt_number());
        $this->assertEquals(2, $attemptobj->get_sum_marks());
        $this->assertEquals(true, $attemptobj->is_finished());
        $this->assertEquals($timenow, $attemptobj->get_submitted_date());
        $this->assertEquals($user1->id, $attemptobj->get_userid());
        $this->assertTrue($attemptobj->has_response_to_at_least_one_graded_question());

        // Check gnrquiz grades.
        $grades = gnrquiz_get_user_grades($gnrquiz, $user1->id);
        $grade = array_shift($grades);
        $this->assertEquals(100.0, $grade->rawgrade);

        // Check grade book.
        $gradebookgrades = grade_get_grades($SITE->id, 'mod', 'gnrquiz', $gnrquiz->id, $user1->id);
        $gradebookitem = array_shift($gradebookgrades->items);
        $gradebookgrade = array_shift($gradebookitem->grades);
        $this->assertEquals(100, $gradebookgrade->grade);
    }

    /**
     * Create a gnrquiz with a random as well as other questions and walk through gnrquiz attempts.
     */
    public function test_gnrquiz_with_random_question_attempt_walkthrough() {
        global $SITE;

        $this->resetAfterTest(true);
        question_bank::get_qtype('random')->clear_caches_before_testing();

        $this->setAdminUser();

        // Make a gnrquiz.
        $gnrquizgenerator = $this->getDataGenerator()->get_plugin_generator('mod_gnrquiz');

        $gnrquiz = $gnrquizgenerator->create_instance(array('course' => $SITE->id, 'questionsperpage' => 2, 'grade' => 100.0,
                                                      'sumgrades' => 4));

        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');

        // Add two questions to question category.
        $cat = $questiongenerator->create_question_category();
        $saq = $questiongenerator->create_question('shortanswer', null, array('category' => $cat->id));
        $numq = $questiongenerator->create_question('numerical', null, array('category' => $cat->id));

        // Add random question to the gnrquiz.
        gnrquiz_add_random_questions($gnrquiz, 0, $cat->id, 1, false);

        // Make another category.
        $cat2 = $questiongenerator->create_question_category();
        $match = $questiongenerator->create_question('match', null, array('category' => $cat->id));

        gnrquiz_add_gnrquiz_question($match->id, $gnrquiz, 0);

        $multichoicemulti = $questiongenerator->create_question('multichoice', 'two_of_four', array('category' => $cat->id));

        gnrquiz_add_gnrquiz_question($multichoicemulti->id, $gnrquiz, 0);

        $multichoicesingle = $questiongenerator->create_question('multichoice', 'one_of_four', array('category' => $cat->id));

        gnrquiz_add_gnrquiz_question($multichoicesingle->id, $gnrquiz, 0);

        foreach (array($saq->id => 'frog', $numq->id => '3.14') as $randomqidtoselect => $randqanswer) {
            // Make a new user to do the gnrquiz each loop.
            $user1 = $this->getDataGenerator()->create_user();
            $this->setUser($user1);

            $gnrquizobj = gnrquiz::create($gnrquiz->id, $user1->id);

            // Start the attempt.
            $quba = question_engine::make_questions_usage_by_activity('mod_gnrquiz', $gnrquizobj->get_context());
            $quba->set_preferred_behaviour($gnrquizobj->get_gnrquiz()->preferredbehaviour);

            $timenow = time();
            $attempt = gnrquiz_create_attempt($gnrquizobj, 1, false, $timenow);

            gnrquiz_start_new_attempt($gnrquizobj, $quba, $attempt, 1, $timenow, array(1 => $randomqidtoselect));
            $this->assertEquals('1,2,0,3,4,0', $attempt->layout);

            gnrquiz_attempt_save_started($gnrquizobj, $quba, $attempt);

            // Process some responses from the student.
            $attemptobj = gnrquiz_attempt::create($attempt->id);
            $this->assertFalse($attemptobj->has_response_to_at_least_one_graded_question());

            $tosubmit = array();
            $selectedquestionid = $quba->get_question_attempt(1)->get_question()->id;
            $tosubmit[1] = array('answer' => $randqanswer);
            $tosubmit[2] = array(
                'frog' => 'amphibian',
                'cat'  => 'mammal',
                'newt' => 'amphibian');
            $tosubmit[3] = array('One' => '1', 'Two' => '0', 'Three' => '1', 'Four' => '0'); // First and third choice.
            $tosubmit[4] = array('answer' => 'One'); // The first choice.

            $attemptobj->process_submitted_actions($timenow, false, $tosubmit);

            // Finish the attempt.
            $attemptobj = gnrquiz_attempt::create($attempt->id);
            $this->assertTrue($attemptobj->has_response_to_at_least_one_graded_question());
            $attemptobj->process_finish($timenow, false);

            // Re-load gnrquiz attempt data.
            $attemptobj = gnrquiz_attempt::create($attempt->id);

            // Check that results are stored as expected.
            $this->assertEquals(1, $attemptobj->get_attempt_number());
            $this->assertEquals(4, $attemptobj->get_sum_marks());
            $this->assertEquals(true, $attemptobj->is_finished());
            $this->assertEquals($timenow, $attemptobj->get_submitted_date());
            $this->assertEquals($user1->id, $attemptobj->get_userid());
            $this->assertTrue($attemptobj->has_response_to_at_least_one_graded_question());

            // Check gnrquiz grades.
            $grades = gnrquiz_get_user_grades($gnrquiz, $user1->id);
            $grade = array_shift($grades);
            $this->assertEquals(100.0, $grade->rawgrade);

            // Check grade book.
            $gradebookgrades = grade_get_grades($SITE->id, 'mod', 'gnrquiz', $gnrquiz->id, $user1->id);
            $gradebookitem = array_shift($gradebookgrades->items);
            $gradebookgrade = array_shift($gradebookitem->grades);
            $this->assertEquals(100, $gradebookgrade->grade);
        }
    }


    public function get_correct_response_for_variants() {
        return array(array(1, 9.9), array(2, 8.5), array(5, 14.2), array(10, 6.8, true));
    }

    protected $gnrquizwithvariants = null;

    /**
     * Create a gnrquiz with a single question with variants and walk through gnrquiz attempts.
     *
     * @dataProvider get_correct_response_for_variants
     */
    public function test_gnrquiz_with_question_with_variants_attempt_walkthrough($variantno, $correctresponse, $done = false) {
        global $SITE;

        $this->resetAfterTest($done);

        $this->setAdminUser();

        if ($this->gnrquizwithvariants === null) {
            // Make a gnrquiz.
            $gnrquizgenerator = $this->getDataGenerator()->get_plugin_generator('mod_gnrquiz');

            $this->gnrquizwithvariants = $gnrquizgenerator->create_instance(array('course'=>$SITE->id,
                                                                            'questionsperpage' => 0,
                                                                            'grade' => 100.0,
                                                                            'sumgrades' => 1));

            $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');

            $cat = $questiongenerator->create_question_category();
            $calc = $questiongenerator->create_question('calculatedsimple', 'sumwithvariants', array('category' => $cat->id));
            gnrquiz_add_gnrquiz_question($calc->id, $this->gnrquizwithvariants, 0);
        }


        // Make a new user to do the gnrquiz.
        $user1 = $this->getDataGenerator()->create_user();
        $this->setUser($user1);
        $gnrquizobj = gnrquiz::create($this->gnrquizwithvariants->id, $user1->id);

        // Start the attempt.
        $quba = question_engine::make_questions_usage_by_activity('mod_gnrquiz', $gnrquizobj->get_context());
        $quba->set_preferred_behaviour($gnrquizobj->get_gnrquiz()->preferredbehaviour);

        $timenow = time();
        $attempt = gnrquiz_create_attempt($gnrquizobj, 1, false, $timenow);

        // Select variant.
        gnrquiz_start_new_attempt($gnrquizobj, $quba, $attempt, 1, $timenow, array(), array(1 => $variantno));
        $this->assertEquals('1,0', $attempt->layout);
        gnrquiz_attempt_save_started($gnrquizobj, $quba, $attempt);

        // Process some responses from the student.
        $attemptobj = gnrquiz_attempt::create($attempt->id);
        $this->assertFalse($attemptobj->has_response_to_at_least_one_graded_question());

        $tosubmit = array(1 => array('answer' => $correctresponse));
        $attemptobj->process_submitted_actions($timenow, false, $tosubmit);

        // Finish the attempt.
        $attemptobj = gnrquiz_attempt::create($attempt->id);
        $this->assertTrue($attemptobj->has_response_to_at_least_one_graded_question());

        $attemptobj->process_finish($timenow, false);

        // Re-load gnrquiz attempt data.
        $attemptobj = gnrquiz_attempt::create($attempt->id);

        // Check that results are stored as expected.
        $this->assertEquals(1, $attemptobj->get_attempt_number());
        $this->assertEquals(1, $attemptobj->get_sum_marks());
        $this->assertEquals(true, $attemptobj->is_finished());
        $this->assertEquals($timenow, $attemptobj->get_submitted_date());
        $this->assertEquals($user1->id, $attemptobj->get_userid());
        $this->assertTrue($attemptobj->has_response_to_at_least_one_graded_question());

        // Check gnrquiz grades.
        $grades = gnrquiz_get_user_grades($this->gnrquizwithvariants, $user1->id);
        $grade = array_shift($grades);
        $this->assertEquals(100.0, $grade->rawgrade);

        // Check grade book.
        $gradebookgrades = grade_get_grades($SITE->id, 'mod', 'gnrquiz', $this->gnrquizwithvariants->id, $user1->id);
        $gradebookitem = array_shift($gradebookgrades->items);
        $gradebookgrade = array_shift($gradebookitem->grades);
        $this->assertEquals(100, $gradebookgrade->grade);
    }
}
