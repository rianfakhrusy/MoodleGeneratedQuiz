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
 * Quiz events tests.
 *
 * @package   mod_gnrquiz
 * @category  test
 * @copyright 2013 Adrian Greeve
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/gnrquiz/attemptlib.php');

/**
 * Unit tests for gnrquiz events.
 *
 * @copyright  2013 Adrian Greeve
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_gnrquiz_structure_testcase extends advanced_testcase {

    /**
     * Create a course with an empty gnrquiz.
     * @return array with three elements gnrquiz, cm and course.
     */
    protected function prepare_gnrquiz_data() {

        $this->resetAfterTest(true);

        // Create a course.
        $course = $this->getDataGenerator()->create_course();

        // Make a gnrquiz.
        $gnrquizgenerator = $this->getDataGenerator()->get_plugin_generator('mod_gnrquiz');

        $gnrquiz = $gnrquizgenerator->create_instance(array('course' => $course->id, 'questionsperpage' => 0,
            'grade' => 100.0, 'sumgrades' => 2, 'preferredbehaviour' => 'immediatefeedback'));

        $cm = get_coursemodule_from_instance('gnrquiz', $gnrquiz->id, $course->id);

        return array($gnrquiz, $cm, $course);
    }

    /**
     * Creat a test gnrquiz.
     *
     * $layout looks like this:
     * $layout = array(
     *     'Heading 1'
     *     array('TF1', 1, 'truefalse'),
     *     'Heading 2*'
     *     array('TF2', 2, 'truefalse'),
     * );
     * That is, either a string, which represents a section heading,
     * or an array that represents a question.
     *
     * If the section heading ends with *, that section is shuffled.
     *
     * The elements in the question array are name, page number, and question type.
     *
     * @param array $layout as above.
     * @return gnrquiz the created gnrquiz.
     */
    protected function create_test_gnrquiz($layout) {
        list($gnrquiz, $cm, $course) = $this->prepare_gnrquiz_data();
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $cat = $questiongenerator->create_question_category();

        $headings = array();
        $slot = 1;
        $lastpage = 0;
        foreach ($layout as $item) {
            if (is_string($item)) {
                if (isset($headings[$lastpage + 1])) {
                    throw new coding_exception('Sections cannot be empty.');
                }
                $headings[$lastpage + 1] = $item;

            } else {
                list($name, $page, $qtype) = $item;
                if ($page < 1 || !($page == $lastpage + 1 ||
                        (!isset($headings[$lastpage + 1]) && $page == $lastpage))) {
                    throw new coding_exception('Page numbers wrong.');
                }
                $q = $questiongenerator->create_question($qtype, null,
                        array('name' => $name, 'category' => $cat->id));

                gnrquiz_add_gnrquiz_question($q->id, $gnrquiz, $page);
                $lastpage = $page;
            }
        }

        $gnrquizobj = new gnrquiz($gnrquiz, $cm, $course);
        $structure = \mod_gnrquiz\structure::create_for_gnrquiz($gnrquizobj);
        if (isset($headings[1])) {
            list($heading, $shuffle) = $this->parse_section_name($headings[1]);
            $sections = $structure->get_sections();
            $firstsection = reset($sections);
            $structure->set_section_heading($firstsection->id, $heading);
            $structure->set_section_shuffle($firstsection->id, $shuffle);
            unset($headings[1]);
        }

        foreach ($headings as $startpage => $heading) {
            list($heading, $shuffle) = $this->parse_section_name($heading);
            $id = $structure->add_section_heading($startpage, $heading);
            $structure->set_section_shuffle($id, $shuffle);
        }

        return $gnrquizobj;
    }

    /**
     * Verify that the given layout matches that expected.
     * @param array $expectedlayout as for $layout in {@link create_test_gnrquiz()}.
     * @param \mod_gnrquiz\structure $structure the structure to test.
     */
    protected function assert_gnrquiz_layout($expectedlayout, \mod_gnrquiz\structure $structure) {
        $sections = $structure->get_sections();

        $slot = 1;
        foreach ($expectedlayout as $item) {
            if (is_string($item)) {
                list($heading, $shuffle) = $this->parse_section_name($item);
                $section = array_shift($sections);

                if ($slot > 1 && $section->heading == '' && $section->firstslot == 1) {
                    // The array $expectedlayout did not contain default first gnrquiz section, so skip over it.
                    $section = array_shift($sections);
                }

                $this->assertEquals($slot, $section->firstslot);
                $this->assertEquals($heading, $section->heading);
                $this->assertEquals($shuffle, $section->shufflequestions);

            } else {
                list($name, $page, $qtype) = $item;
                $question = $structure->get_question_in_slot($slot);
                $this->assertEquals($name,  $question->name);
                $this->assertEquals($slot,  $question->slot,  'Slot number wrong for question ' . $name);
                $this->assertEquals($qtype, $question->qtype, 'Question type wrong for question ' . $name);
                $this->assertEquals($page,  $question->page,  'Page number wrong for question ' . $name);

                $slot += 1;
            }
        }

        if ($slot - 1 != count($structure->get_slots())) {
            $this->fail('The gnrquiz contains more slots than expected.');
        }

        if (!empty($sections)) {
            $section = array_shift($sections);
            if ($section->heading != '' || $section->firstslot != 1) {
                $this->fail('Unexpected section (' . $section->heading .') found in the gnrquiz.');
            }
        }
    }

    /**
     * Parse the section name, optionally followed by a * to mean shuffle, as
     * used by create_test_gnrquiz as assert_gnrquiz_layout.
     * @param string $heading the heading.
     * @return array with two elements, the heading and the shuffle setting.
     */
    protected function parse_section_name($heading) {
        if (substr($heading, -1) == '*') {
            return array(substr($heading, 0, -1), 1);
        } else {
            return array($heading, 0);
        }
    }

    public function test_get_gnrquiz_slots() {
        $gnrquizobj = $this->create_test_gnrquiz(array(
                array('TF1', 1, 'truefalse'),
                array('TF2', 1, 'truefalse'),
            ));
        $structure = \mod_gnrquiz\structure::create_for_gnrquiz($gnrquizobj);

        // Are the correct slots returned?
        $slots = $structure->get_slots();
        $this->assertCount(2, $structure->get_slots());
    }

    public function test_gnrquiz_has_one_section_by_default() {
        $gnrquizobj = $this->create_test_gnrquiz(array(
                array('TF1', 1, 'truefalse'),
            ));
        $structure = \mod_gnrquiz\structure::create_for_gnrquiz($gnrquizobj);

        $sections = $structure->get_sections();
        $this->assertCount(1, $sections);

        $section = array_shift($sections);
        $this->assertEquals(1, $section->firstslot);
        $this->assertEquals('', $section->heading);
        $this->assertEquals(0, $section->shufflequestions);
    }

    public function test_get_sections() {
        $gnrquizobj = $this->create_test_gnrquiz(array(
                'Heading 1*',
                array('TF1', 1, 'truefalse'),
                'Heading 2*',
                array('TF2', 2, 'truefalse'),
        ));
        $structure = \mod_gnrquiz\structure::create_for_gnrquiz($gnrquizobj);

        $sections = $structure->get_sections();
        $this->assertCount(2, $sections);

        $section = array_shift($sections);
        $this->assertEquals(1, $section->firstslot);
        $this->assertEquals('Heading 1', $section->heading);
        $this->assertEquals(1, $section->shufflequestions);

        $section = array_shift($sections);
        $this->assertEquals(2, $section->firstslot);
        $this->assertEquals('Heading 2', $section->heading);
        $this->assertEquals(1, $section->shufflequestions);
    }

    public function test_remove_section_heading() {
        $gnrquizobj = $this->create_test_gnrquiz(array(
                'Heading 1',
                array('TF1', 1, 'truefalse'),
                'Heading 2',
                array('TF2', 2, 'truefalse'),
            ));
        $structure = \mod_gnrquiz\structure::create_for_gnrquiz($gnrquizobj);

        $sections = $structure->get_sections();
        $section = end($sections);
        $structure->remove_section_heading($section->id);

        $structure = \mod_gnrquiz\structure::create_for_gnrquiz($gnrquizobj);
        $this->assert_gnrquiz_layout(array(
                'Heading 1',
                array('TF1', 1, 'truefalse'),
                array('TF2', 2, 'truefalse'),
            ), $structure);
    }

    public function test_cannot_remove_first_section() {
        $gnrquizobj = $this->create_test_gnrquiz(array(
                'Heading 1',
                array('TF1', 1, 'truefalse'),
        ));
        $structure = \mod_gnrquiz\structure::create_for_gnrquiz($gnrquizobj);

        $sections = $structure->get_sections();
        $section = reset($sections);

        $this->setExpectedException('coding_exception');
        $structure->remove_section_heading($section->id);
    }

    public function test_move_slot_to_the_same_place_does_nothing() {
        $gnrquizobj = $this->create_test_gnrquiz(array(
                array('TF1', 1, 'truefalse'),
                array('TF2', 1, 'truefalse'),
                array('TF3', 2, 'truefalse'),
            ));
        $structure = \mod_gnrquiz\structure::create_for_gnrquiz($gnrquizobj);

        $idtomove = $structure->get_question_in_slot(2)->slotid;
        $idmoveafter = $structure->get_question_in_slot(1)->slotid;
        $structure->move_slot($idtomove, $idmoveafter, '1');

        $structure = \mod_gnrquiz\structure::create_for_gnrquiz($gnrquizobj);
        $this->assert_gnrquiz_layout(array(
                array('TF1', 1, 'truefalse'),
                array('TF2', 1, 'truefalse'),
                array('TF3', 2, 'truefalse'),
            ), $structure);
    }

    public function test_move_slot_end_of_one_page_to_start_of_next() {
        $gnrquizobj = $this->create_test_gnrquiz(array(
                array('TF1', 1, 'truefalse'),
                array('TF2', 1, 'truefalse'),
                array('TF3', 2, 'truefalse'),
            ));
        $structure = \mod_gnrquiz\structure::create_for_gnrquiz($gnrquizobj);

        $idtomove = $structure->get_question_in_slot(2)->slotid;
        $idmoveafter = $structure->get_question_in_slot(2)->slotid;
        $structure->move_slot($idtomove, $idmoveafter, '2');

        $structure = \mod_gnrquiz\structure::create_for_gnrquiz($gnrquizobj);
        $this->assert_gnrquiz_layout(array(
                array('TF1', 1, 'truefalse'),
                array('TF2', 2, 'truefalse'),
                array('TF3', 2, 'truefalse'),
            ), $structure);
    }

    public function test_end_of_one_section_to_start_of_next() {
        $gnrquizobj = $this->create_test_gnrquiz(array(
                array('TF1', 1, 'truefalse'),
                array('TF2', 1, 'truefalse'),
                'Heading',
                array('TF3', 2, 'truefalse'),
            ));
        $structure = \mod_gnrquiz\structure::create_for_gnrquiz($gnrquizobj);

        $idtomove = $structure->get_question_in_slot(2)->slotid;
        $idmoveafter = $structure->get_question_in_slot(2)->slotid;
        $structure->move_slot($idtomove, $idmoveafter, '2');

        $structure = \mod_gnrquiz\structure::create_for_gnrquiz($gnrquizobj);
        $this->assert_gnrquiz_layout(array(
                array('TF1', 1, 'truefalse'),
                'Heading',
                array('TF2', 2, 'truefalse'),
                array('TF3', 2, 'truefalse'),
            ), $structure);
    }

    public function test_start_of_one_section_to_end_of_previous() {
        $gnrquizobj = $this->create_test_gnrquiz(array(
                array('TF1', 1, 'truefalse'),
                'Heading',
                array('TF2', 2, 'truefalse'),
                array('TF3', 2, 'truefalse'),
            ));
        $structure = \mod_gnrquiz\structure::create_for_gnrquiz($gnrquizobj);

        $idtomove = $structure->get_question_in_slot(2)->slotid;
        $idmoveafter = $structure->get_question_in_slot(1)->slotid;
        $structure->move_slot($idtomove, $idmoveafter, '1');

        $structure = \mod_gnrquiz\structure::create_for_gnrquiz($gnrquizobj);
        $this->assert_gnrquiz_layout(array(
                array('TF1', 1, 'truefalse'),
                array('TF2', 1, 'truefalse'),
                'Heading',
                array('TF3', 2, 'truefalse'),
            ), $structure);
    }
    public function test_move_slot_on_same_page() {
        $gnrquizobj = $this->create_test_gnrquiz(array(
                array('TF1', 1, 'truefalse'),
                array('TF2', 1, 'truefalse'),
                array('TF3', 1, 'truefalse'),
            ));
        $structure = \mod_gnrquiz\structure::create_for_gnrquiz($gnrquizobj);

        $idtomove = $structure->get_question_in_slot(2)->slotid;
        $idmoveafter = $structure->get_question_in_slot(3)->slotid;
        $structure->move_slot($idtomove, $idmoveafter, '1');

        $structure = \mod_gnrquiz\structure::create_for_gnrquiz($gnrquizobj);
        $this->assert_gnrquiz_layout(array(
                array('TF1', 1, 'truefalse'),
                array('TF3', 1, 'truefalse'),
                array('TF2', 1, 'truefalse'),
        ), $structure);
    }

    public function test_move_slot_up_onto_previous_page() {
        $gnrquizobj = $this->create_test_gnrquiz(array(
                array('TF1', 1, 'truefalse'),
                array('TF2', 2, 'truefalse'),
                array('TF3', 2, 'truefalse'),
        ));
        $structure = \mod_gnrquiz\structure::create_for_gnrquiz($gnrquizobj);

        $idtomove = $structure->get_question_in_slot(3)->slotid;
        $idmoveafter = $structure->get_question_in_slot(1)->slotid;
        $structure->move_slot($idtomove, $idmoveafter, '1');

        $structure = \mod_gnrquiz\structure::create_for_gnrquiz($gnrquizobj);
        $this->assert_gnrquiz_layout(array(
                array('TF1', 1, 'truefalse'),
                array('TF3', 1, 'truefalse'),
                array('TF2', 2, 'truefalse'),
        ), $structure);
    }

    public function test_move_slot_emptying_a_page_renumbers_pages() {
        $gnrquizobj = $this->create_test_gnrquiz(array(
                array('TF1', 1, 'truefalse'),
                array('TF2', 2, 'truefalse'),
                array('TF3', 3, 'truefalse'),
        ));
        $structure = \mod_gnrquiz\structure::create_for_gnrquiz($gnrquizobj);

        $idtomove = $structure->get_question_in_slot(2)->slotid;
        $idmoveafter = $structure->get_question_in_slot(3)->slotid;
        $structure->move_slot($idtomove, $idmoveafter, '3');

        $structure = \mod_gnrquiz\structure::create_for_gnrquiz($gnrquizobj);
        $this->assert_gnrquiz_layout(array(
                array('TF1', 1, 'truefalse'),
                array('TF3', 2, 'truefalse'),
                array('TF2', 2, 'truefalse'),
        ), $structure);
    }

    public function test_move_slot_too_small_page_number_detected() {
        $gnrquizobj = $this->create_test_gnrquiz(array(
                array('TF1', 1, 'truefalse'),
                array('TF2', 2, 'truefalse'),
                array('TF3', 3, 'truefalse'),
        ));
        $structure = \mod_gnrquiz\structure::create_for_gnrquiz($gnrquizobj);

        $idtomove = $structure->get_question_in_slot(3)->slotid;
        $idmoveafter = $structure->get_question_in_slot(2)->slotid;
        $this->setExpectedException('coding_exception');
        $structure->move_slot($idtomove, $idmoveafter, '1');
    }

    public function test_move_slot_too_large_page_number_detected() {
        $gnrquizobj = $this->create_test_gnrquiz(array(
                array('TF1', 1, 'truefalse'),
                array('TF2', 2, 'truefalse'),
                array('TF3', 3, 'truefalse'),
        ));
        $structure = \mod_gnrquiz\structure::create_for_gnrquiz($gnrquizobj);

        $idtomove = $structure->get_question_in_slot(1)->slotid;
        $idmoveafter = $structure->get_question_in_slot(2)->slotid;
        $this->setExpectedException('coding_exception');
        $structure->move_slot($idtomove, $idmoveafter, '4');
    }

    public function test_move_slot_within_section() {
        $gnrquizobj = $this->create_test_gnrquiz(array(
                'Heading 1',
                array('TF1', 1, 'truefalse'),
                array('TF2', 1, 'truefalse'),
                'Heading 2',
                array('TF3', 2, 'truefalse'),
            ));
        $structure = \mod_gnrquiz\structure::create_for_gnrquiz($gnrquizobj);

        $idtomove = $structure->get_question_in_slot(1)->slotid;
        $idmoveafter = $structure->get_question_in_slot(2)->slotid;
        $structure->move_slot($idtomove, $idmoveafter, '1');

        $structure = \mod_gnrquiz\structure::create_for_gnrquiz($gnrquizobj);
        $this->assert_gnrquiz_layout(array(
                'Heading 1',
                array('TF2', 1, 'truefalse'),
                array('TF1', 1, 'truefalse'),
                'Heading 2',
                array('TF3', 2, 'truefalse'),
            ), $structure);
    }

    public function test_move_slot_to_new_section() {
        $gnrquizobj = $this->create_test_gnrquiz(array(
                'Heading 1',
                array('TF1', 1, 'truefalse'),
                array('TF2', 1, 'truefalse'),
                'Heading 2',
                array('TF3', 2, 'truefalse'),
            ));
        $structure = \mod_gnrquiz\structure::create_for_gnrquiz($gnrquizobj);

        $idtomove = $structure->get_question_in_slot(2)->slotid;
        $idmoveafter = $structure->get_question_in_slot(3)->slotid;
        $structure->move_slot($idtomove, $idmoveafter, '2');

        $structure = \mod_gnrquiz\structure::create_for_gnrquiz($gnrquizobj);
        $this->assert_gnrquiz_layout(array(
                'Heading 1',
                array('TF1', 1, 'truefalse'),
                'Heading 2',
                array('TF3', 2, 'truefalse'),
                array('TF2', 2, 'truefalse'),
            ), $structure);
    }

    public function test_move_slot_to_start() {
        $gnrquizobj = $this->create_test_gnrquiz(array(
                'Heading 1',
                array('TF1', 1, 'truefalse'),
                'Heading 2',
                array('TF2', 2, 'truefalse'),
                array('TF3', 2, 'truefalse'),
            ));
        $structure = \mod_gnrquiz\structure::create_for_gnrquiz($gnrquizobj);

        $idtomove = $structure->get_question_in_slot(3)->slotid;
        $structure->move_slot($idtomove, 0, '1');

        $structure = \mod_gnrquiz\structure::create_for_gnrquiz($gnrquizobj);
        $this->assert_gnrquiz_layout(array(
                'Heading 1',
                array('TF3', 1, 'truefalse'),
                array('TF1', 1, 'truefalse'),
                'Heading 2',
                array('TF2', 2, 'truefalse'),
            ), $structure);
    }

    public function test_move_slot_down_to_start_of_second_section() {
        $gnrquizobj = $this->create_test_gnrquiz(array(
                'Heading 1',
                array('TF1', 1, 'truefalse'),
                array('TF2', 1, 'truefalse'),
                'Heading 2',
                array('TF3', 2, 'truefalse'),
            ));
        $structure = \mod_gnrquiz\structure::create_for_gnrquiz($gnrquizobj);

        $idtomove = $structure->get_question_in_slot(2)->slotid;
        $idmoveafter = $structure->get_question_in_slot(2)->slotid;
        $structure->move_slot($idtomove, $idmoveafter, '2');

        $structure = \mod_gnrquiz\structure::create_for_gnrquiz($gnrquizobj);
        $this->assert_gnrquiz_layout(array(
                'Heading 1',
                array('TF1', 1, 'truefalse'),
                'Heading 2',
                array('TF2', 2, 'truefalse'),
                array('TF3', 2, 'truefalse'),
            ), $structure);
    }

    public function test_move_first_slot_down_to_start_of_page_2() {
        $gnrquizobj = $this->create_test_gnrquiz(array(
                'Heading 1',
                array('TF1', 1, 'truefalse'),
                array('TF2', 2, 'truefalse'),
            ));
        $structure = \mod_gnrquiz\structure::create_for_gnrquiz($gnrquizobj);

        $idtomove = $structure->get_question_in_slot(1)->slotid;
        $structure->move_slot($idtomove, 0, '2');

        $structure = \mod_gnrquiz\structure::create_for_gnrquiz($gnrquizobj);
        $this->assert_gnrquiz_layout(array(
                'Heading 1',
                array('TF1', 1, 'truefalse'),
                array('TF2', 1, 'truefalse'),
            ), $structure);
    }

    public function test_move_first_slot_to_same_place_on_page_1() {
        $gnrquizobj = $this->create_test_gnrquiz(array(
                'Heading 1',
                array('TF1', 1, 'truefalse'),
                array('TF2', 2, 'truefalse'),
            ));
        $structure = \mod_gnrquiz\structure::create_for_gnrquiz($gnrquizobj);

        $idtomove = $structure->get_question_in_slot(1)->slotid;
        $structure->move_slot($idtomove, 0, '1');

        $structure = \mod_gnrquiz\structure::create_for_gnrquiz($gnrquizobj);
        $this->assert_gnrquiz_layout(array(
                'Heading 1',
                array('TF1', 1, 'truefalse'),
                array('TF2', 2, 'truefalse'),
            ), $structure);
    }

    public function test_move_first_slot_to_before_page_1() {
        $gnrquizobj = $this->create_test_gnrquiz(array(
                'Heading 1',
                array('TF1', 1, 'truefalse'),
                array('TF2', 2, 'truefalse'),
            ));
        $structure = \mod_gnrquiz\structure::create_for_gnrquiz($gnrquizobj);

        $idtomove = $structure->get_question_in_slot(1)->slotid;
        $structure->move_slot($idtomove, 0, '');

        $structure = \mod_gnrquiz\structure::create_for_gnrquiz($gnrquizobj);
        $this->assert_gnrquiz_layout(array(
                'Heading 1',
                array('TF1', 1, 'truefalse'),
                array('TF2', 2, 'truefalse'),
            ), $structure);
    }

    public function test_move_slot_up_to_start_of_second_section() {
        $gnrquizobj = $this->create_test_gnrquiz(array(
                'Heading 1',
                array('TF1', 1, 'truefalse'),
                'Heading 2',
                array('TF2', 2, 'truefalse'),
                'Heading 3',
                array('TF3', 3, 'truefalse'),
                array('TF4', 3, 'truefalse'),
            ));
        $structure = \mod_gnrquiz\structure::create_for_gnrquiz($gnrquizobj);

        $idtomove = $structure->get_question_in_slot(3)->slotid;
        $idmoveafter = $structure->get_question_in_slot(1)->slotid;
        $structure->move_slot($idtomove, $idmoveafter, '2');

        $structure = \mod_gnrquiz\structure::create_for_gnrquiz($gnrquizobj);
        $this->assert_gnrquiz_layout(array(
                'Heading 1',
                array('TF1', 1, 'truefalse'),
                'Heading 2',
                array('TF3', 2, 'truefalse'),
                array('TF2', 2, 'truefalse'),
                'Heading 3',
                array('TF4', 3, 'truefalse'),
            ), $structure);
    }

    public function test_gnrquiz_remove_slot() {
        $gnrquizobj = $this->create_test_gnrquiz(array(
                array('TF1', 1, 'truefalse'),
                array('TF2', 1, 'truefalse'),
                'Heading 2',
                array('TF3', 2, 'truefalse'),
            ));
        $structure = \mod_gnrquiz\structure::create_for_gnrquiz($gnrquizobj);

        $structure->remove_slot(2);

        $structure = \mod_gnrquiz\structure::create_for_gnrquiz($gnrquizobj);
        $this->assert_gnrquiz_layout(array(
                array('TF1', 1, 'truefalse'),
                'Heading 2',
                array('TF3', 2, 'truefalse'),
            ), $structure);
    }

    public function test_gnrquiz_removing_a_random_question_deletes_the_question() {
        global $DB;

        $this->resetAfterTest(true);
        $this->setAdminUser();

        $gnrquizobj = $this->create_test_gnrquiz(array(
                array('TF1', 1, 'truefalse'),
            ));

        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $cat = $questiongenerator->create_question_category();
        gnrquiz_add_random_questions($gnrquizobj->get_gnrquiz(), 1, $cat->id, 1, false);
        $structure = \mod_gnrquiz\structure::create_for_gnrquiz($gnrquizobj);
        $randomq = $DB->get_record('question', array('qtype' => 'random'));

        $structure->remove_slot(2);

        $structure = \mod_gnrquiz\structure::create_for_gnrquiz($gnrquizobj);
        $this->assert_gnrquiz_layout(array(
                array('TF1', 1, 'truefalse'),
            ), $structure);
        $this->assertFalse($DB->record_exists('question', array('id' => $randomq->id)));
    }

    public function test_cannot_remove_last_slot_in_a_section() {
        $gnrquizobj = $this->create_test_gnrquiz(array(
                array('TF1', 1, 'truefalse'),
                array('TF2', 1, 'truefalse'),
                'Heading 2',
                array('TF3', 2, 'truefalse'),
            ));
        $structure = \mod_gnrquiz\structure::create_for_gnrquiz($gnrquizobj);

        $this->setExpectedException('coding_exception');
        $structure->remove_slot(3);
    }

    public function test_can_remove_last_question_in_a_gnrquiz() {
        $gnrquizobj = $this->create_test_gnrquiz(array(
                'Heading 1',
                array('TF1', 1, 'truefalse'),
            ));
        $structure = \mod_gnrquiz\structure::create_for_gnrquiz($gnrquizobj);

        $structure->remove_slot(1);

        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $cat = $questiongenerator->create_question_category();
        $q = $questiongenerator->create_question('truefalse', null,
                array('name' => 'TF2', 'category' => $cat->id));

        gnrquiz_add_gnrquiz_question($q->id, $gnrquizobj->get_gnrquiz(), 0);
        $structure = \mod_gnrquiz\structure::create_for_gnrquiz($gnrquizobj);

        $this->assert_gnrquiz_layout(array(
                'Heading 1',
                array('TF2', 1, 'truefalse'),
        ), $structure);
    }

    public function test_add_question_updates_headings() {
        $gnrquizobj = $this->create_test_gnrquiz(array(
                array('TF1', 1, 'truefalse'),
                'Heading 2',
                array('TF2', 2, 'truefalse'),
        ));
        $structure = \mod_gnrquiz\structure::create_for_gnrquiz($gnrquizobj);

        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $cat = $questiongenerator->create_question_category();
        $q = $questiongenerator->create_question('truefalse', null,
                array('name' => 'TF3', 'category' => $cat->id));

        gnrquiz_add_gnrquiz_question($q->id, $gnrquizobj->get_gnrquiz(), 1);

        $structure = \mod_gnrquiz\structure::create_for_gnrquiz($gnrquizobj);
        $this->assert_gnrquiz_layout(array(
                array('TF1', 1, 'truefalse'),
                array('TF3', 1, 'truefalse'),
                'Heading 2',
                array('TF2', 2, 'truefalse'),
        ), $structure);
    }

    public function test_add_question_at_end_does_not_update_headings() {
        $gnrquizobj = $this->create_test_gnrquiz(array(
                array('TF1', 1, 'truefalse'),
                'Heading 2',
                array('TF2', 2, 'truefalse'),
        ));
        $structure = \mod_gnrquiz\structure::create_for_gnrquiz($gnrquizobj);

        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $cat = $questiongenerator->create_question_category();
        $q = $questiongenerator->create_question('truefalse', null,
                array('name' => 'TF3', 'category' => $cat->id));

        gnrquiz_add_gnrquiz_question($q->id, $gnrquizobj->get_gnrquiz(), 0);

        $structure = \mod_gnrquiz\structure::create_for_gnrquiz($gnrquizobj);
        $this->assert_gnrquiz_layout(array(
                array('TF1', 1, 'truefalse'),
                'Heading 2',
                array('TF2', 2, 'truefalse'),
                array('TF3', 2, 'truefalse'),
        ), $structure);
    }

    public function test_remove_page_break() {
        $gnrquizobj = $this->create_test_gnrquiz(array(
                array('TF1', 1, 'truefalse'),
                array('TF2', 2, 'truefalse'),
            ));
        $structure = \mod_gnrquiz\structure::create_for_gnrquiz($gnrquizobj);

        $slotid = $structure->get_question_in_slot(2)->slotid;
        $slots = $structure->update_page_break($slotid, \mod_gnrquiz\repaginate::LINK);

        $structure = \mod_gnrquiz\structure::create_for_gnrquiz($gnrquizobj);
        $this->assert_gnrquiz_layout(array(
                array('TF1', 1, 'truefalse'),
                array('TF2', 1, 'truefalse'),
            ), $structure);
    }

    public function test_add_page_break() {
        $gnrquizobj = $this->create_test_gnrquiz(array(
                array('TF1', 1, 'truefalse'),
                array('TF2', 1, 'truefalse'),
        ));
        $structure = \mod_gnrquiz\structure::create_for_gnrquiz($gnrquizobj);

        $slotid = $structure->get_question_in_slot(2)->slotid;
        $slots = $structure->update_page_break($slotid, \mod_gnrquiz\repaginate::UNLINK);

        $structure = \mod_gnrquiz\structure::create_for_gnrquiz($gnrquizobj);
        $this->assert_gnrquiz_layout(array(
                array('TF1', 1, 'truefalse'),
                array('TF2', 2, 'truefalse'),
        ), $structure);
    }

    public function test_update_question_dependency() {
        $gnrquizobj = $this->create_test_gnrquiz(array(
                array('TF1', 1, 'truefalse'),
                array('TF2', 1, 'truefalse'),
        ));
        $structure = \mod_gnrquiz\structure::create_for_gnrquiz($gnrquizobj);

        // Test adding a dependency.
        $slotid = $structure->get_slot_id_for_slot(2);
        $structure->update_question_dependency($slotid, true);

        // Having called update page break, we need to reload $structure.
        $structure = \mod_gnrquiz\structure::create_for_gnrquiz($gnrquizobj);
        $this->assertEquals(1, $structure->is_question_dependent_on_previous_slot(2));

        // Test removing a dependency.
        $structure->update_question_dependency($slotid, false);

        // Having called update page break, we need to reload $structure.
        $structure = \mod_gnrquiz\structure::create_for_gnrquiz($gnrquizobj);
        $this->assertEquals(0, $structure->is_question_dependent_on_previous_slot(2));
    }
}
