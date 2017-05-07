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
 * Defines the gnrquiz module ettings form.
 *
 * @package    mod_gnrquiz
 * @copyright  2006 Jamie Pratt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');
require_once($CFG->dirroot . '/mod/gnrquiz/locallib.php');


/**
 * Settings form for the gnrquiz module.
 *
 * @copyright  2006 Jamie Pratt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_gnrquiz_mod_form extends moodleform_mod {
    /** @var array options to be used with date_time_selector fields in the gnrquiz. */
    public static $datefieldoptions = array('optional' => true, 'step' => 1);

    protected $_feedbacks;
    protected static $reviewfields = array(); // Initialised in the constructor.

    /** @var int the max number of attempts allowed in any user or group override on this gnrquiz. */
    protected $maxattemptsanyoverride = null;

    public function __construct($current, $section, $cm, $course) {
        self::$reviewfields = array(
            'attempt'          => array('theattempt', 'gnrquiz'),
            'correctness'      => array('whethercorrect', 'question'),
            'marks'            => array('marks', 'gnrquiz'),
            'specificfeedback' => array('specificfeedback', 'question'),
            'generalfeedback'  => array('generalfeedback', 'question'),
            'rightanswer'      => array('rightanswer', 'question'),
            'overallfeedback'  => array('reviewoverallfeedback', 'gnrquiz'),
        );
        parent::__construct($current, $section, $cm, $course);
    }

    protected function definition() {
        global $COURSE, $CFG, $DB, $PAGE;
        $gnrquizconfig = get_config('quiz');
        $mform = $this->_form;

        // -------------------------------------------------------------------------------
        $mform->addElement('header', 'general', get_string('general', 'form'));

        // Name.
        $mform->addElement('text', 'name', get_string('name'), array('size'=>'64'));
        if (!empty($CFG->formatstringstriptags)) {
            $mform->setType('name', PARAM_TEXT);
        } else {
            $mform->setType('name', PARAM_CLEANHTML);
        }
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        // Introduction.
        $this->standard_intro_elements(get_string('introduction', 'gnrquiz'));

        // Checkbox for selecting constraints.
        $mform->addElement('header', 'constraintcheckbox', get_string('selectconstraint', 'gnrquiz'));
        $mform->setExpanded('constraintcheckbox');
        $mform->addElement('advcheckbox', 'usescore', get_string('totalscore', 'gnrquiz'), null, array('group' => 1));
        $mform->addElement('advcheckbox', 'usetype', get_string('numquestioneachtype', 'gnrquiz'), null, array('group' => 1));
        $mform->addElement('advcheckbox', 'usediff', get_string('averagedifficulty', 'gnrquiz'), null, array('group' => 1));
        $mform->addElement('advcheckbox', 'usechapter', get_string('numquestioneachchapter', 'gnrquiz'), null, array('group' => 1));
        $mform->addElement('advcheckbox', 'usedist', get_string('averagedistinguishingdegree', 'gnrquiz'), null, array('group' => 1));
        $mform->addElement('advcheckbox', 'usetime', get_string('timelimit', 'gnrquiz'), null, array('group' => 1));

        // Header for constraints.
        $mform->addElement('header', 'constraints', 'Constraints');

        // Number of question.
        $mform->addElement('text', 'nquestion', get_string('numberofquestion', 'gnrquiz'),
            array('size' => '3', 'maxlength' => '3'));
        $mform->addRule('nquestion', get_string('formelementempty', 'gnrquiz'), 'required', null, 'client');
        $mform->addRule('nquestion', get_string('formelementnumeric', 'gnrquiz'), 'numeric', null, 'client');
        $mform->setDefault('nquestion', 0);
        $mform->setType('nquestion', PARAM_INT);

        // Total score.
        $mform->addElement('text', 'sumscore', get_string('totalscore', 'gnrquiz'),
            array('size' => '3', 'maxlength' => '3'));
        $mform->addRule('sumscore', get_string('formelementnumeric', 'gnrquiz'), 'numeric', null, 'client');
        $mform->setType('sumscore', PARAM_INT);
        $mform->setDefault('sumscore', 0);
        // Disable my control unless a checkbox is checked.
        $mform->disabledIf('sumscore', 'usescore');

        //average difficulty
        $mform->addElement('text', 'avgdiff', get_string('averagedifficulty', 'gnrquiz'),
            array('size' => '6', 'maxlength' => '6'));
        $mform->addRule('avgdiff', get_string('formelementnumeric', 'gnrquiz'), 'numeric', null, 'client');
        $mform->setType('avgdiff', PARAM_FLOAT);
        $mform->setDefault('avgdiff', 0);
        // Disable my control unless a checkbox is checked.
        $mform->disabledIf('avgdiff', 'usediff');

        // Average distinguishing degree.
        $mform->addElement('text', 'avgdist', get_string('averagedistinguishingdegree', 'gnrquiz'),
            array('size' => '6', 'maxlength' => '6'));
        $mform->addRule('avgdist', get_string('formelementnumeric', 'gnrquiz'), 'numeric', null, 'client');
        $mform->setType('avgdist', PARAM_FLOAT);
        $mform->setDefault('avgdist', 0);
        // Disable my control unless a checkbox is checked.
        $mform->disabledIf('avgdist', 'usedist');

        // Time limit.
        $mform->addElement('duration', 'timelimit', get_string('timelimit', 'gnrquiz'),
                array('optional' => false));
        $mform->addHelpButton('timelimit', 'timelimit', 'gnrquiz');
        $mform->setAdvanced('timelimit', $gnrquizconfig->timelimit_adv);
        $mform->setDefault('timelimit', $gnrquizconfig->timelimit);
        // Disable my control unless a checkbox is checked.
        $mform->disabledIf('timelimit', 'usetime');

        // -------------------------------------------------------------------------------
        
        //type constraint block
        $mform->addElement('header', 'qtypeconstraints', get_string('numquestioneachtype', 'gnrquiz'));
        $mform->setExpanded('qtypeconstraints');
        //multiple choice
        $mform->addElement('text', 'multichoice', 'Multiple Choice',
            array('size' => '3', 'maxlength' => '3'));
        $mform->addRule('multichoice', get_string('formelementnumeric', 'gnrquiz'), 'numeric', null, 'client');
        $mform->setType('multichoice', PARAM_INT);
        $mform->setDefault('multichoice', 0);
        $mform->disabledIf('multichoice', 'usetype');
        //essay
        $mform->addElement('text', 'essay', 'Essay',
            array('size' => '3', 'maxlength' => '3'));
        $mform->addRule('essay', get_string('formelementnumeric', 'gnrquiz'), 'numeric', null, 'client');
        $mform->setDefault('essay', 0);
        $mform->setType('essay', PARAM_INT);
        $mform->disabledIf('essay', 'usetype');
        //match
        $mform->addElement('text', 'match', 'Matching',
            array('size' => '3', 'maxlength' => '3'));
        $mform->addRule('match', get_string('formelementnumeric', 'gnrquiz'), 'numeric', null, 'client');
        $mform->setType('match', PARAM_INT);
        $mform->setDefault('match', 0);
        $mform->disabledIf('match', 'usetype');
        //true false
        $mform->addElement('text', 'truefalse', 'True Flase',
            array('size' => '3', 'maxlength' => '3'));
        $mform->addRule('truefalse', get_string('formelementnumeric', 'gnrquiz'), 'numeric', null, 'client');
        $mform->setType('truefalse', PARAM_INT);
        $mform->setDefault('truefalse', 0);
        $mform->disabledIf('truefalse', 'usetype');        
        //short answer
        $mform->addElement('text', 'shortanswer', 'Short Answer',
            array('size' => '3', 'maxlength' => '3'));
        $mform->addRule('shortanswer', get_string('formelementnumeric', 'gnrquiz'), 'numeric', null, 'client');
        $mform->setType('shortanswer', PARAM_INT);
        $mform->setDefault('shortanswer', 0);
        $mform->disabledIf('shortanswer', 'usetype');  

        $mform->addElement('hidden', 'types', '');
        $mform->setType('types', PARAM_TEXT);

        //chapter constraint block
        $mform->addElement('header', 'chapterconstraints', get_string('numquestioneachchapter', 'gnrquiz'));
        $mform->setExpanded('chapterconstraints');
        $questids = array();
        $qesteditctx  = new question_edit_contexts($this->context);
        $this->contexts     = $qesteditctx->having_one_edit_tab_cap('editq');
        $questioncats = question_category_options($this->contexts);
        foreach ($questioncats as $questioncatcourse) {
            foreach ($questioncatcourse as $key => $questioncat) {
                // Key format is [question cat id, question cat context id], we need to explode it.
                $questidcontext = explode(',', $key);
                $questid = array_shift($questidcontext);

                $mform->addElement('text', 'category_' . $questid, $questioncat,
            array('size' => '3', 'maxlength' => '3'));
                $mform->addRule('category_' . $questid, get_string('formelementnumeric', 'gnrquiz'), 'numeric', null, 'client');
                $mform->setType('category_' . $questid, PARAM_INT);
                $mform->setDefault('category_' . $questid, 0);
                $mform->disabledIf('category_' . $questid, 'usechapter'); 

                $questids[] = $questid;
            }
        }

        $mform->addElement('hidden', 'chapters', '');
        $mform->setType('chapters', PARAM_TEXT);

        $mform->addElement('hidden', 'allids', serialize($questids));
        $mform->setType('allids', PARAM_TEXT);


        $mform->addElement('header', 'timing', get_string('timing', 'gnrquiz'));

        // Open and close dates.
        $mform->addElement('date_time_selector', 'timeopen', get_string('gnrquizopen', 'gnrquiz'),
                self::$datefieldoptions);
        $mform->addHelpButton('timeopen', 'gnrquizopenclose', 'gnrquiz');

        $mform->addElement('date_time_selector', 'timeclose', get_string('gnrquizclose', 'gnrquiz'),
                self::$datefieldoptions);

        // What to do with overdue attempts.
        $mform->addElement('select', 'overduehandling', get_string('overduehandling', 'gnrquiz'),
                gnrquiz_get_overdue_handling_options());
        $mform->addHelpButton('overduehandling', 'overduehandling', 'gnrquiz');
        $mform->setAdvanced('overduehandling', $gnrquizconfig->overduehandling_adv);
        $mform->setDefault('overduehandling', $gnrquizconfig->overduehandling);
        // TODO Formslib does OR logic on disableif, and we need AND logic here.
        // $mform->disabledIf('overduehandling', 'timelimit', 'eq', 0);
        // $mform->disabledIf('overduehandling', 'timeclose', 'eq', 0);

        // Grace period time.
        $mform->addElement('duration', 'graceperiod', get_string('graceperiod', 'gnrquiz'),
                array('optional' => true));
        $mform->addHelpButton('graceperiod', 'graceperiod', 'gnrquiz');
        $mform->setAdvanced('graceperiod', $gnrquizconfig->graceperiod_adv);
        $mform->setDefault('graceperiod', $gnrquizconfig->graceperiod);
        $mform->disabledIf('graceperiod', 'overduehandling', 'neq', 'graceperiod');

        // -------------------------------------------------------------------------------
        // Grade settings.
        $this->standard_grading_coursemodule_elements();

        $mform->removeElement('grade');
        if (property_exists($this->current, 'grade')) {
            $currentgrade = $this->current->grade;
        } else {
            $currentgrade = $gnrquizconfig->maximumgrade;
        }
        $mform->addElement('hidden', 'grade', $currentgrade);
        $mform->setType('grade', PARAM_FLOAT);

        // Number of attempts.
        $attemptoptions = array('0' => get_string('unlimited'));
        for ($i = 1; $i <= GNRQUIZ_MAX_ATTEMPT_OPTION; $i++) {
            $attemptoptions[$i] = $i;
        }
        $mform->addElement('select', 'attempts', get_string('attemptsallowed', 'gnrquiz'),
                $attemptoptions);
        $mform->setAdvanced('attempts', $gnrquizconfig->attempts_adv);
        $mform->setDefault('attempts', $gnrquizconfig->attempts);

        // Grading method.
        $mform->addElement('select', 'grademethod', get_string('grademethod', 'gnrquiz'),
                gnrquiz_get_grading_options());
        $mform->addHelpButton('grademethod', 'grademethod', 'gnrquiz');
        $mform->setAdvanced('grademethod', $gnrquizconfig->grademethod_adv);
        $mform->setDefault('grademethod', $gnrquizconfig->grademethod);
        if ($this->get_max_attempts_for_any_override() < 2) {
            $mform->disabledIf('grademethod', 'attempts', 'eq', 1);
        }

        // -------------------------------------------------------------------------------
        $mform->addElement('header', 'layouthdr', get_string('layout', 'gnrquiz'));

        $pagegroup = array();
        $pagegroup[] = $mform->createElement('select', 'questionsperpage',
                get_string('newpage', 'gnrquiz'), gnrquiz_questions_per_page_options(), array('id' => 'id_questionsperpage'));
        $mform->setDefault('questionsperpage', $gnrquizconfig->questionsperpage);

        if (!empty($this->_cm)) {
            $pagegroup[] = $mform->createElement('checkbox', 'repaginatenow', '',
                    get_string('repaginatenow', 'gnrquiz'), array('id' => 'id_repaginatenow'));
        }

        $mform->addGroup($pagegroup, 'questionsperpagegrp',
                get_string('newpage', 'gnrquiz'), null, false);
        $mform->addHelpButton('questionsperpagegrp', 'newpage', 'gnrquiz');
        $mform->setAdvanced('questionsperpagegrp', $gnrquizconfig->questionsperpage_adv);

        // Navigation method.
        $mform->addElement('select', 'navmethod', get_string('navmethod', 'gnrquiz'),
                gnrquiz_get_navigation_options());
        $mform->addHelpButton('navmethod', 'navmethod', 'gnrquiz');
        $mform->setAdvanced('navmethod', $gnrquizconfig->navmethod_adv);
        $mform->setDefault('navmethod', $gnrquizconfig->navmethod);

        // -------------------------------------------------------------------------------
        $mform->addElement('header', 'interactionhdr', get_string('questionbehaviour', 'gnrquiz'));

        // Shuffle within questions.
        $mform->addElement('selectyesno', 'shuffleanswers', get_string('shufflewithin', 'gnrquiz'));
        $mform->addHelpButton('shuffleanswers', 'shufflewithin', 'gnrquiz');
        $mform->setAdvanced('shuffleanswers', $gnrquizconfig->shuffleanswers_adv);
        $mform->setDefault('shuffleanswers', $gnrquizconfig->shuffleanswers);

        // How questions behave (question behaviour).
        if (!empty($this->current->preferredbehaviour)) {
            $currentbehaviour = $this->current->preferredbehaviour;
        } else {
            $currentbehaviour = '';
        }
        $behaviours = question_engine::get_behaviour_options($currentbehaviour);
        $mform->addElement('select', 'preferredbehaviour',
                get_string('howquestionsbehave', 'question'), $behaviours);
        $mform->addHelpButton('preferredbehaviour', 'howquestionsbehave', 'question');
        $mform->setDefault('preferredbehaviour', $gnrquizconfig->preferredbehaviour);

        // Can redo completed questions.
        $redochoices = array(0 => get_string('no'), 1 => get_string('canredoquestionsyes', 'gnrquiz'));
        $mform->addElement('select', 'canredoquestions', get_string('canredoquestions', 'gnrquiz'), $redochoices);
        $mform->addHelpButton('canredoquestions', 'canredoquestions', 'gnrquiz');
        $mform->setAdvanced('canredoquestions', $gnrquizconfig->canredoquestions_adv);
        $mform->setDefault('canredoquestions', $gnrquizconfig->canredoquestions);
        foreach ($behaviours as $behaviour => $notused) {
            if (!question_engine::can_questions_finish_during_the_attempt($behaviour)) {
                $mform->disabledIf('canredoquestions', 'preferredbehaviour', 'eq', $behaviour);
            }
        }

        // Each attempt builds on last.
        $mform->addElement('selectyesno', 'attemptonlast',
                get_string('eachattemptbuildsonthelast', 'gnrquiz'));
        $mform->addHelpButton('attemptonlast', 'eachattemptbuildsonthelast', 'gnrquiz');
        $mform->setAdvanced('attemptonlast', $gnrquizconfig->attemptonlast_adv);
        $mform->setDefault('attemptonlast', $gnrquizconfig->attemptonlast);
        if ($this->get_max_attempts_for_any_override() < 2) {
            $mform->disabledIf('attemptonlast', 'attempts', 'eq', 1);
        }

        // -------------------------------------------------------------------------------
        $mform->addElement('header', 'reviewoptionshdr',
                get_string('reviewoptionsheading', 'gnrquiz'));
        $mform->addHelpButton('reviewoptionshdr', 'reviewoptionsheading', 'gnrquiz');

        // Review options.
        $this->add_review_options_group($mform, $gnrquizconfig, 'during',
                mod_gnrquiz_display_options::DURING, true);
        $this->add_review_options_group($mform, $gnrquizconfig, 'immediately',
                mod_gnrquiz_display_options::IMMEDIATELY_AFTER);
        $this->add_review_options_group($mform, $gnrquizconfig, 'open',
                mod_gnrquiz_display_options::LATER_WHILE_OPEN);
        $this->add_review_options_group($mform, $gnrquizconfig, 'closed',
                mod_gnrquiz_display_options::AFTER_CLOSE);

        foreach ($behaviours as $behaviour => $notused) {
            $unusedoptions = question_engine::get_behaviour_unused_display_options($behaviour);
            foreach ($unusedoptions as $unusedoption) {
                $mform->disabledIf($unusedoption . 'during', 'preferredbehaviour',
                        'eq', $behaviour);
            }
        }
        $mform->disabledIf('attemptduring', 'preferredbehaviour',
                'neq', 'wontmatch');
        $mform->disabledIf('overallfeedbackduring', 'preferredbehaviour',
                'neq', 'wontmatch');

        // -------------------------------------------------------------------------------
        $mform->addElement('header', 'display', get_string('appearance'));

        // Show user picture.
        $mform->addElement('select', 'showuserpicture', get_string('showuserpicture', 'gnrquiz'),
                gnrquiz_get_user_image_options());
        $mform->addHelpButton('showuserpicture', 'showuserpicture', 'gnrquiz');
        $mform->setAdvanced('showuserpicture', $gnrquizconfig->showuserpicture_adv);
        $mform->setDefault('showuserpicture', $gnrquizconfig->showuserpicture);

        // Overall decimal points.
        $options = array();
        for ($i = 0; $i <= GNRQUIZ_MAX_DECIMAL_OPTION; $i++) {
            $options[$i] = $i;
        }
        $mform->addElement('select', 'decimalpoints', get_string('decimalplaces', 'gnrquiz'),
                $options);
        $mform->addHelpButton('decimalpoints', 'decimalplaces', 'gnrquiz');
        $mform->setAdvanced('decimalpoints', $gnrquizconfig->decimalpoints_adv);
        $mform->setDefault('decimalpoints', $gnrquizconfig->decimalpoints);

        // Question decimal points.
        $options = array(-1 => get_string('sameasoverall', 'gnrquiz'));
        for ($i = 0; $i <= GNRQUIZ_MAX_Q_DECIMAL_OPTION; $i++) {
            $options[$i] = $i;
        }
        $mform->addElement('select', 'questiondecimalpoints',
                get_string('decimalplacesquestion', 'gnrquiz'), $options);
        $mform->addHelpButton('questiondecimalpoints', 'decimalplacesquestion', 'gnrquiz');
        $mform->setAdvanced('questiondecimalpoints', $gnrquizconfig->questiondecimalpoints_adv);
        $mform->setDefault('questiondecimalpoints', $gnrquizconfig->questiondecimalpoints);

        // Show blocks during gnrquiz attempt.
        $mform->addElement('selectyesno', 'showblocks', get_string('showblocks', 'gnrquiz'));
        $mform->addHelpButton('showblocks', 'showblocks', 'gnrquiz');
        $mform->setAdvanced('showblocks', $gnrquizconfig->showblocks_adv);
        $mform->setDefault('showblocks', $gnrquizconfig->showblocks);

        // -------------------------------------------------------------------------------
        $mform->addElement('header', 'security', get_string('extraattemptrestrictions', 'gnrquiz'));

        // Require password to begin gnrquiz attempt.
        $mform->addElement('passwordunmask', 'gnrquizpassword', get_string('requirepassword', 'gnrquiz'));
        $mform->setType('gnrquizpassword', PARAM_TEXT);
        $mform->addHelpButton('gnrquizpassword', 'requirepassword', 'gnrquiz');
        $mform->setAdvanced('gnrquizpassword', $gnrquizconfig->password_adv);
        $mform->setDefault('gnrquizpassword', $gnrquizconfig->password);

        // IP address.
        $mform->addElement('text', 'subnet', get_string('requiresubnet', 'gnrquiz'));
        $mform->setType('subnet', PARAM_TEXT);
        $mform->addHelpButton('subnet', 'requiresubnet', 'gnrquiz');
        $mform->setAdvanced('subnet', $gnrquizconfig->subnet_adv);
        $mform->setDefault('subnet', $gnrquizconfig->subnet);

        // Enforced time delay between gnrquiz attempts.
        $mform->addElement('duration', 'delay1', get_string('delay1st2nd', 'gnrquiz'),
                array('optional' => true));
        $mform->addHelpButton('delay1', 'delay1st2nd', 'gnrquiz');
        $mform->setAdvanced('delay1', $gnrquizconfig->delay1_adv);
        $mform->setDefault('delay1', $gnrquizconfig->delay1);
        if ($this->get_max_attempts_for_any_override() < 2) {
            $mform->disabledIf('delay1', 'attempts', 'eq', 1);
        }

        $mform->addElement('duration', 'delay2', get_string('delaylater', 'gnrquiz'),
                array('optional' => true));
        $mform->addHelpButton('delay2', 'delaylater', 'gnrquiz');
        $mform->setAdvanced('delay2', $gnrquizconfig->delay2_adv);
        $mform->setDefault('delay2', $gnrquizconfig->delay2);
        if ($this->get_max_attempts_for_any_override() < 3) {
            $mform->disabledIf('delay2', 'attempts', 'eq', 1);
            $mform->disabledIf('delay2', 'attempts', 'eq', 2);
        }

        // Browser security choices.
        $mform->addElement('select', 'browsersecurity', get_string('browsersecurity', 'gnrquiz'),
                gnrquiz_access_manager::get_browser_security_choices());
        $mform->addHelpButton('browsersecurity', 'browsersecurity', 'gnrquiz');
        $mform->setAdvanced('browsersecurity', $gnrquizconfig->browsersecurity_adv);
        $mform->setDefault('browsersecurity', $gnrquizconfig->browsersecurity);

        // Any other rule plugins.
        gnrquiz_access_manager::add_settings_form_fields($this, $mform);

        // -------------------------------------------------------------------------------
        $mform->addElement('header', 'overallfeedbackhdr', get_string('overallfeedback', 'gnrquiz'));
        $mform->addHelpButton('overallfeedbackhdr', 'overallfeedback', 'gnrquiz');

        if (isset($this->current->grade)) {
            $needwarning = $this->current->grade === 0;
        } else {
            $needwarning = $gnrquizconfig->maximumgrade == 0;
        }
        if ($needwarning) {
            $mform->addElement('static', 'nogradewarning', '',
                    get_string('nogradewarning', 'gnrquiz'));
        }

        $mform->addElement('static', 'gradeboundarystatic1',
                get_string('gradeboundary', 'gnrquiz'), '100%');

        $repeatarray = array();
        $repeatedoptions = array();
        $repeatarray[] = $mform->createElement('editor', 'feedbacktext',
                get_string('feedback', 'gnrquiz'), array('rows' => 3), array('maxfiles' => EDITOR_UNLIMITED_FILES,
                        'noclean' => true, 'context' => $this->context));
        $repeatarray[] = $mform->createElement('text', 'feedbackboundaries',
                get_string('gradeboundary', 'gnrquiz'), array('size' => 10));
        $repeatedoptions['feedbacktext']['type'] = PARAM_RAW;
        $repeatedoptions['feedbackboundaries']['type'] = PARAM_RAW;

        if (!empty($this->_instance)) {
            $this->_feedbacks = $DB->get_records('gnrquiz_feedback',
                    array('gnrquizid' => $this->_instance), 'mingrade DESC');
            $numfeedbacks = count($this->_feedbacks);
        } else {
            $this->_feedbacks = array();
            $numfeedbacks = $gnrquizconfig->initialnumfeedbacks;
        }
        $numfeedbacks = max($numfeedbacks, 1);

        $nextel = $this->repeat_elements($repeatarray, $numfeedbacks - 1,
                $repeatedoptions, 'boundary_repeats', 'boundary_add_fields', 3,
                get_string('addmoreoverallfeedbacks', 'gnrquiz'), true);

        // Put some extra elements in before the button.
        $mform->insertElementBefore($mform->createElement('editor',
                "feedbacktext[$nextel]", get_string('feedback', 'gnrquiz'), array('rows' => 3),
                array('maxfiles' => EDITOR_UNLIMITED_FILES, 'noclean' => true,
                      'context' => $this->context)),
                'boundary_add_fields');
        $mform->insertElementBefore($mform->createElement('static',
                'gradeboundarystatic2', get_string('gradeboundary', 'gnrquiz'), '0%'),
                'boundary_add_fields');

        // Add the disabledif rules. We cannot do this using the $repeatoptions parameter to
        // repeat_elements because we don't want to dissable the first feedbacktext.
        for ($i = 0; $i < $nextel; $i++) {
            $mform->disabledIf('feedbackboundaries[' . $i . ']', 'grade', 'eq', 0);
            $mform->disabledIf('feedbacktext[' . ($i + 1) . ']', 'grade', 'eq', 0);
        }

        // -------------------------------------------------------------------------------
        $this->standard_coursemodule_elements();

        // Check and act on whether setting outcomes is considered an advanced setting.
        $mform->setAdvanced('modoutcomes', !empty($gnrquizconfig->outcomes_adv));

        // The standard_coursemodule_elements method sets this to 100, but the
        // gnrquiz has its own setting, so use that.
        $mform->setDefault('grade', $gnrquizconfig->maximumgrade);

        // -------------------------------------------------------------------------------
        $this->add_action_buttons();

        $PAGE->requires->yui_module('moodle-mod_gnrquiz-modform', 'M.mod_gnrquiz.modform.init');
    }

    protected function add_review_options_group($mform, $gnrquizconfig, $whenname,
            $when, $withhelp = false) {
        global $OUTPUT;

        $group = array();
        foreach (self::$reviewfields as $field => $string) {
            list($identifier, $component) = $string;

            $label = get_string($identifier, $component);
            if ($withhelp) {
                $label .= ' ' . $OUTPUT->help_icon($identifier, $component);
            }

            $group[] = $mform->createElement('checkbox', $field . $whenname, '', $label);
        }
        $mform->addGroup($group, $whenname . 'optionsgrp',
                get_string('review' . $whenname, 'gnrquiz'), null, false);

        foreach (self::$reviewfields as $field => $notused) {
            $cfgfield = 'review' . $field;
            if ($gnrquizconfig->$cfgfield & $when) {
                $mform->setDefault($field . $whenname, 1);
            } else {
                $mform->setDefault($field . $whenname, 0);
            }
        }

        if ($whenname != 'during') {
            $mform->disabledIf('correctness' . $whenname, 'attempt' . $whenname);
            $mform->disabledIf('specificfeedback' . $whenname, 'attempt' . $whenname);
            $mform->disabledIf('generalfeedback' . $whenname, 'attempt' . $whenname);
            $mform->disabledIf('rightanswer' . $whenname, 'attempt' . $whenname);
        }
    }

    protected function preprocessing_review_settings(&$toform, $whenname, $when) {
        foreach (self::$reviewfields as $field => $notused) {
            $fieldname = 'review' . $field;
            if (array_key_exists($fieldname, $toform)) {
                $toform[$field . $whenname] = $toform[$fieldname] & $when;
            }
        }
    }

    public function data_preprocessing(&$toform) {
        if (isset($toform['grade'])) {
            // Convert to a real number, so we don't get 0.0000.
            $toform['grade'] = $toform['grade'] + 0;
        }

        #self::$usetime = $toform['usetime'];

        #var_dump($toform);

        if (isset($toform['types'])){
            $alltypes = unserialize($toform['types']);
            $toform['multichoice'] = $alltypes['choicegnrquiz'];
            $toform['essay'] = $alltypes['essaygnrquiz'];
            $toform['match'] = $alltypes['matchgnrquiz'];
            $toform['truefalse'] = $alltypes['truefalsegnrquiz'];
            $toform['shortanswer'] = $alltypes['shortgnrquiz'];
        }

        if (isset($toform['chapters'])){
            $allchapters = unserialize($toform['chapters']);
            $questids = array();
            $qesteditctx  = new question_edit_contexts($this->context);
            $this->contexts     = $qesteditctx->having_one_edit_tab_cap('editq');
            $questioncats = question_category_options($this->contexts);
            foreach ($questioncats as $questioncatcourse) {
                foreach ($questioncatcourse as $key => $questioncat) {
                    // Key format is [question cat id, question cat context id], we need to explode it.
                    $questidcontext = explode(',', $key);
                    $questid = array_shift($questidcontext);

                    $questids[] = $questid;
                }
            }
            $i = 0;
            foreach ($questids as $value) {
                $toform['category_' . $value] = $allchapters[$value];
            }
        }

        if (count($this->_feedbacks)) {
            $key = 0;
            foreach ($this->_feedbacks as $feedback) {
                $draftid = file_get_submitted_draft_itemid('feedbacktext['.$key.']');
                $toform['feedbacktext['.$key.']']['text'] = file_prepare_draft_area(
                    $draftid,               // Draftid.
                    $this->context->id,     // Context.
                    'mod_gnrquiz',             // Component.
                    'feedback',             // Filarea.
                    !empty($feedback->id) ? (int) $feedback->id : null, // Itemid.
                    null,
                    $feedback->feedbacktext // Text.
                );
                $toform['feedbacktext['.$key.']']['format'] = $feedback->feedbacktextformat;
                $toform['feedbacktext['.$key.']']['itemid'] = $draftid;

                if ($toform['grade'] == 0) {
                    // When a gnrquiz is un-graded, there can only be one lot of
                    // feedback. If the gnrquiz previously had a maximum grade and
                    // several lots of feedback, we must now avoid putting text
                    // into input boxes that are disabled, but which the
                    // validation will insist are blank.
                    break;
                }

                if ($feedback->mingrade > 0) {
                    $toform['feedbackboundaries['.$key.']'] =
                            round(100.0 * $feedback->mingrade / $toform['grade'], 6) . '%';
                }
                $key++;
            }
        }

        if (isset($toform['timelimit'])) {
            $toform['timelimitenable'] = $toform['timelimit'] > 0;
        }

        $this->preprocessing_review_settings($toform, 'during',
                mod_gnrquiz_display_options::DURING);
        $this->preprocessing_review_settings($toform, 'immediately',
                mod_gnrquiz_display_options::IMMEDIATELY_AFTER);
        $this->preprocessing_review_settings($toform, 'open',
                mod_gnrquiz_display_options::LATER_WHILE_OPEN);
        $this->preprocessing_review_settings($toform, 'closed',
                mod_gnrquiz_display_options::AFTER_CLOSE);
        $toform['attemptduring'] = true;
        $toform['overallfeedbackduring'] = false;

        // Password field - different in form to stop browsers that remember
        // passwords from getting confused.
        if (isset($toform['password'])) {
            $toform['gnrquizpassword'] = $toform['password'];
            unset($toform['password']);
        }

        // Load any settings belonging to the access rules.
        if (!empty($toform['instance'])) {
            $accesssettings = gnrquiz_access_manager::load_settings($toform['instance']);
            foreach ($accesssettings as $name => $value) {
                $toform[$name] = $value;
            }
        }
    }

    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        // Check if number of question are consistent.
        $sumqtypes = $data['multichoice'] + $data['truefalse'] + $data['match'] + $data['essay'] + $data['shortanswer'];

        $questids = unserialize($data['allids']);

        $sumqchapters = 0;
        foreach ($questids as $value) {
            $sumqchapters = $sumqchapters + $data['category_' . $value];
        }
        /*
        if (($sumqtypes == 0) || ($sumqtypes != $sumqchapters)) {
            $errors['nquestion'] = get_string('numberofquestionnotconsistent', 'gnrquiz');
        }

        // Check if number of question is more than zero
        if ($sumqtypes <= 0) {
            $errors['nquestion'] = get_string('numberofquestionmustmorethanzero', 'gnrquiz');
        }*/

        if ($data['nquestion'] <= 0){
            $errors['nquestion'] = get_string('numberofquestionmustmorethanzero', 'gnrquiz');
        }

        // Check open and close times are consistent.
        if ($data['timeopen'] != 0 && $data['timeclose'] != 0 &&
                $data['timeclose'] < $data['timeopen']) {
            $errors['timeclose'] = get_string('closebeforeopen', 'gnrquiz');
        }

        // Check that the grace period is not too short.
        if ($data['overduehandling'] == 'graceperiod') {
            $graceperiodmin = get_config('gnrquiz', 'graceperiodmin');
            if ($data['graceperiod'] <= $graceperiodmin) {
                $errors['graceperiod'] = get_string('graceperiodtoosmall', 'gnrquiz', format_time($graceperiodmin));
            }
        }

        if (array_key_exists('completion', $data) && $data['completion'] == COMPLETION_TRACKING_AUTOMATIC) {
            $completionpass = isset($data['completionpass']) ? $data['completionpass'] : $this->current->completionpass;

            // Show an error if require passing grade was selected and the grade to pass was set to 0.
            if ($completionpass && (empty($data['gradepass']) || grade_floatval($data['gradepass']) == 0)) {
                if (isset($data['completionpass'])) {
                    $errors['completionpassgroup'] = get_string('gradetopassnotset', 'gnrquiz');
                } else {
                    $errors['gradepass'] = get_string('gradetopassmustbeset', 'gnrquiz');
                }
            }
        }

        // Check the boundary value is a number or a percentage, and in range.
        $i = 0;
        while (!empty($data['feedbackboundaries'][$i] )) {
            $boundary = trim($data['feedbackboundaries'][$i]);
            if (strlen($boundary) > 0) {
                if ($boundary[strlen($boundary) - 1] == '%') {
                    $boundary = trim(substr($boundary, 0, -1));
                    if (is_numeric($boundary)) {
                        $boundary = $boundary * $data['grade'] / 100.0;
                    } else {
                        $errors["feedbackboundaries[$i]"] =
                                get_string('feedbackerrorboundaryformat', 'gnrquiz', $i + 1);
                    }
                } else if (!is_numeric($boundary)) {
                    $errors["feedbackboundaries[$i]"] =
                            get_string('feedbackerrorboundaryformat', 'gnrquiz', $i + 1);
                }
            }
            if (is_numeric($boundary) && $boundary <= 0 || $boundary >= $data['grade'] ) {
                $errors["feedbackboundaries[$i]"] =
                        get_string('feedbackerrorboundaryoutofrange', 'gnrquiz', $i + 1);
            }
            if (is_numeric($boundary) && $i > 0 &&
                    $boundary >= $data['feedbackboundaries'][$i - 1]) {
                $errors["feedbackboundaries[$i]"] =
                        get_string('feedbackerrororder', 'gnrquiz', $i + 1);
            }
            $data['feedbackboundaries'][$i] = $boundary;
            $i += 1;
        }
        $numboundaries = $i;

        // Check there is nothing in the remaining unused fields.
        if (!empty($data['feedbackboundaries'])) {
            for ($i = $numboundaries; $i < count($data['feedbackboundaries']); $i += 1) {
                if (!empty($data['feedbackboundaries'][$i] ) &&
                        trim($data['feedbackboundaries'][$i] ) != '') {
                    $errors["feedbackboundaries[$i]"] =
                            get_string('feedbackerrorjunkinboundary', 'gnrquiz', $i + 1);
                }
            }
        }
        for ($i = $numboundaries + 1; $i < count($data['feedbacktext']); $i += 1) {
            if (!empty($data['feedbacktext'][$i]['text']) &&
                    trim($data['feedbacktext'][$i]['text'] ) != '') {
                $errors["feedbacktext[$i]"] =
                        get_string('feedbackerrorjunkinfeedback', 'gnrquiz', $i + 1);
            }
        }

        // Any other rule plugins.
        $errors = gnrquiz_access_manager::validate_settings_form_fields($errors, $data, $files, $this);

        return $errors;
    }

    /**
     * Display module-specific activity completion rules.
     * Part of the API defined by moodleform_mod
     * @return array Array of string IDs of added items, empty array if none
     */
    public function add_completion_rules() {
        $mform = $this->_form;
        $items = array();

        $group = array();
        $group[] = $mform->createElement('advcheckbox', 'completionpass', null, get_string('completionpass', 'gnrquiz'),
                array('group' => 'cpass'));

        $group[] = $mform->createElement('advcheckbox', 'completionattemptsexhausted', null,
                get_string('completionattemptsexhausted', 'gnrquiz'),
                array('group' => 'cattempts'));
        $mform->disabledIf('completionattemptsexhausted', 'completionpass', 'notchecked');
        $mform->addGroup($group, 'completionpassgroup', get_string('completionpass', 'gnrquiz'), ' &nbsp; ', false);
        $mform->addHelpButton('completionpassgroup', 'completionpass', 'gnrquiz');
        $items[] = 'completionpassgroup';
        return $items;
    }

    /**
     * Called during validation. Indicates whether a module-specific completion rule is selected.
     *
     * @param array $data Input data (not yet validated)
     * @return bool True if one or more rules is enabled, false if none are.
     */
    public function completion_rule_enabled($data) {
        return !empty($data['completionattemptsexhausted']) || !empty($data['completionpass']);
    }

    /**
     * Get the maximum number of attempts that anyone might have due to a user
     * or group override. Used to decide whether disabledIf rules should be applied.
     * @return int the number of attempts allowed. For the purpose of this method,
     * unlimited is returned as 1000, not 0.
     */
    public function get_max_attempts_for_any_override() {
        global $DB;

        if (empty($this->_instance)) {
            // Quiz not created yet, so no overrides.
            return 1;
        }

        if ($this->maxattemptsanyoverride === null) {
            $this->maxattemptsanyoverride = $DB->get_field_sql("
                    SELECT MAX(CASE WHEN attempts = 0 THEN 1000 ELSE attempts END)
                      FROM {gnrquiz_overrides}
                     WHERE gnrquiz = ?",
                    array($this->_instance));
            if ($this->maxattemptsanyoverride < 1) {
                // This happens when no override alters the number of attempts.
                $this->maxattemptsanyoverride = 1;
            }
        }

        return $this->maxattemptsanyoverride;
    }
}
