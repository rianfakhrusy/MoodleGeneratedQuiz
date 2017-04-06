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
 * Base class for the settings form for {@link gnrquiz_attempts_report}s.
 *
 * @package   mod_gnrquiz
 * @copyright 2012 The Open University
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');


/**
 * Base class for the settings form for {@link gnrquiz_attempts_report}s.
 *
 * @copyright 2012 The Open University
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class mod_gnrquiz_attempts_report_form extends moodleform {

    protected function definition() {
        $mform = $this->_form;

        $mform->addElement('header', 'preferencespage',
                get_string('reportwhattoinclude', 'gnrquiz'));

        $this->standard_attempt_fields($mform);
        $this->other_attempt_fields($mform);

        $mform->addElement('header', 'preferencesuser',
                get_string('reportdisplayoptions', 'gnrquiz'));

        $this->standard_preference_fields($mform);
        $this->other_preference_fields($mform);

        $mform->addElement('submit', 'submitbutton',
                get_string('showreport', 'gnrquiz'));
    }

    protected function standard_attempt_fields(MoodleQuickForm $mform) {

        $mform->addElement('select', 'attempts', get_string('reportattemptsfrom', 'gnrquiz'), array(
                    gnrquiz_attempts_report::ENROLLED_WITH    => get_string('reportuserswith', 'gnrquiz'),
                    gnrquiz_attempts_report::ENROLLED_WITHOUT => get_string('reportuserswithout', 'gnrquiz'),
                    gnrquiz_attempts_report::ENROLLED_ALL     => get_string('reportuserswithorwithout', 'gnrquiz'),
                    gnrquiz_attempts_report::ALL_WITH        => get_string('reportusersall', 'gnrquiz'),
                 ));

        $stategroup = array(
            $mform->createElement('advcheckbox', 'stateinprogress', '',
                    get_string('stateinprogress', 'gnrquiz')),
            $mform->createElement('advcheckbox', 'stateoverdue', '',
                    get_string('stateoverdue', 'gnrquiz')),
            $mform->createElement('advcheckbox', 'statefinished', '',
                    get_string('statefinished', 'gnrquiz')),
            $mform->createElement('advcheckbox', 'stateabandoned', '',
                    get_string('stateabandoned', 'gnrquiz')),
        );
        $mform->addGroup($stategroup, 'stateoptions',
                get_string('reportattemptsthatare', 'gnrquiz'), array(' '), false);
        $mform->setDefault('stateinprogress', 1);
        $mform->setDefault('stateoverdue',    1);
        $mform->setDefault('statefinished',   1);
        $mform->setDefault('stateabandoned',  1);
        $mform->disabledIf('stateinprogress', 'attempts', 'eq', gnrquiz_attempts_report::ENROLLED_WITHOUT);
        $mform->disabledIf('stateoverdue',    'attempts', 'eq', gnrquiz_attempts_report::ENROLLED_WITHOUT);
        $mform->disabledIf('statefinished',   'attempts', 'eq', gnrquiz_attempts_report::ENROLLED_WITHOUT);
        $mform->disabledIf('stateabandoned',  'attempts', 'eq', gnrquiz_attempts_report::ENROLLED_WITHOUT);

        if (gnrquiz_report_can_filter_only_graded($this->_customdata['gnrquiz'])) {
            $gm = html_writer::tag('span',
                    gnrquiz_get_grading_option_name($this->_customdata['gnrquiz']->grademethod),
                    array('class' => 'highlight'));
            $mform->addElement('advcheckbox', 'onlygraded', '',
                    get_string('reportshowonlyfinished', 'gnrquiz', $gm));
            $mform->disabledIf('onlygraded', 'attempts', 'eq', gnrquiz_attempts_report::ENROLLED_WITHOUT);
            $mform->disabledIf('onlygraded', 'statefinished', 'notchecked');
        }
    }

    protected function other_attempt_fields(MoodleQuickForm $mform) {
    }

    protected function standard_preference_fields(MoodleQuickForm $mform) {
        $mform->addElement('text', 'pagesize', get_string('pagesize', 'gnrquiz'));
        $mform->setType('pagesize', PARAM_INT);
    }

    protected function other_preference_fields(MoodleQuickForm $mform) {
    }

    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        if ($data['attempts'] != gnrquiz_attempts_report::ENROLLED_WITHOUT && !(
                $data['stateinprogress'] || $data['stateoverdue'] || $data['statefinished'] || $data['stateabandoned'])) {
            $errors['stateoptions'] = get_string('reportmustselectstate', 'gnrquiz');
        }

        return $errors;
    }
}
