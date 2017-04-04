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
 * Administration settings definitions for the quiz module.
 *
 * @package   mod_quiz
 * @copyright 2010 Petr Skoda
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/gnrquiz/lib.php');

// First get a list of quiz reports with there own settings pages. If there none,
// we use a simpler overall menu structure.
$reports = core_component::get_plugin_list_with_file('gnrquiz', 'settings.php', false);
$reportsbyname = array();
foreach ($reports as $report => $reportdir) {
    $strreportname = get_string($report . 'report', 'gnrquiz_'.$report);
    $reportsbyname[$strreportname] = $report;
}
core_collator::ksort($reportsbyname);

// First get a list of quiz reports with there own settings pages. If there none,
// we use a simpler overall menu structure.
$rules = core_component::get_plugin_list_with_file('gnrquizaccess', 'settings.php', false);
$rulesbyname = array();
foreach ($rules as $rule => $ruledir) {
    $strrulename = get_string('pluginname', 'gnrquizaccess_' . $rule);
    $rulesbyname[$strrulename] = $rule;
}
core_collator::ksort($rulesbyname);

// Create the quiz settings page.
if (empty($reportsbyname) && empty($rulesbyname)) {
    $pagetitle = get_string('modulename', 'gnrquiz');
} else {
    $pagetitle = get_string('generalsettings', 'admin');
}
$quizsettings = new admin_settingpage('modsettinggnrquiz', $pagetitle, 'moodle/site:config');

if ($ADMIN->fulltree) {
    // Introductory explanation that all the settings are defaults for the add quiz form.
    $quizsettings->add(new admin_setting_heading('gnrquizintro', '', get_string('configintro', 'gnrquiz')));

    // Time limit.
    $quizsettings->add(new admin_setting_configduration_with_advanced('gnrquiz/timelimit',
            get_string('timelimit', 'gnrquiz'), get_string('configtimelimitsec', 'gnrquiz'),
            array('value' => '0', 'adv' => false), 60));

    // What to do with overdue attempts.
    $quizsettings->add(new mod_gnrquiz_admin_setting_overduehandling('gnrquiz/overduehandling',
            get_string('overduehandling', 'gnrquiz'), get_string('overduehandling_desc', 'gnrquiz'),
            array('value' => 'autosubmit', 'adv' => false), null));

    // Grace period time.
    $quizsettings->add(new admin_setting_configduration_with_advanced('gnrquiz/graceperiod',
            get_string('graceperiod', 'gnrquiz'), get_string('graceperiod_desc', 'gnrquiz'),
            array('value' => '86400', 'adv' => false)));

    // Minimum grace period used behind the scenes.
    $quizsettings->add(new admin_setting_configduration('gnrquiz/graceperiodmin',
            get_string('graceperiodmin', 'gnrquiz'), get_string('graceperiodmin_desc', 'gnrquiz'),
            60, 1));

    // Number of attempts.
    $options = array(get_string('unlimited'));
    for ($i = 1; $i <= GNRQUIZ_MAX_ATTEMPT_OPTION; $i++) {
        $options[$i] = $i;
    }
    $quizsettings->add(new admin_setting_configselect_with_advanced('gnrquiz/attempts',
            get_string('attemptsallowed', 'gnrquiz'), get_string('configattemptsallowed', 'gnrquiz'),
            array('value' => 0, 'adv' => false), $options));

    // Grading method.
    $quizsettings->add(new mod_gnrquiz_admin_setting_grademethod('gnrquiz/grademethod',
            get_string('grademethod', 'gnrquiz'), get_string('configgrademethod', 'gnrquiz'),
            array('value' => GNRQUIZ_GRADEHIGHEST, 'adv' => false), null));

    // Maximum grade.
    $quizsettings->add(new admin_setting_configtext('gnrquiz/maximumgrade',
            get_string('maximumgrade'), get_string('configmaximumgrade', 'gnrquiz'), 10, PARAM_INT));

    // Questions per page.
    $perpage = array();
    $perpage[0] = get_string('never');
    $perpage[1] = get_string('aftereachquestion', 'gnrquiz');
    for ($i = 2; $i <= GNRQUIZ_MAX_QPP_OPTION; ++$i) {
        $perpage[$i] = get_string('afternquestions', 'gnrquiz', $i);
    }
    $quizsettings->add(new admin_setting_configselect_with_advanced('gnrquiz/questionsperpage',
            get_string('newpageevery', 'gnrquiz'), get_string('confignewpageevery', 'gnrquiz'),
            array('value' => 1, 'adv' => false), $perpage));

    // Navigation method.
    $quizsettings->add(new admin_setting_configselect_with_advanced('gnrquiz/navmethod',
            get_string('navmethod', 'gnrquiz'), get_string('confignavmethod', 'gnrquiz'),
            array('value' => GNRQUIZ_NAVMETHOD_FREE, 'adv' => true), gnrquiz_get_navigation_options()));

    // Shuffle within questions.
    $quizsettings->add(new admin_setting_configcheckbox_with_advanced('gnrquiz/shuffleanswers',
            get_string('shufflewithin', 'gnrquiz'), get_string('configshufflewithin', 'gnrquiz'),
            array('value' => 1, 'adv' => false)));

    // Preferred behaviour.
    $quizsettings->add(new admin_setting_question_behaviour('gnrquiz/preferredbehaviour',
            get_string('howquestionsbehave', 'question'), get_string('howquestionsbehave_desc', 'gnrquiz'),
            'deferredfeedback'));

    // Can redo completed questions.
    $quizsettings->add(new admin_setting_configselect_with_advanced('gnrquiz/canredoquestions',
            get_string('canredoquestions', 'gnrquiz'), get_string('canredoquestions_desc', 'gnrquiz'),
            array('value' => 0, 'adv' => true),
            array(0 => get_string('no'), 1 => get_string('canredoquestionsyes', 'gnrquiz'))));

    // Each attempt builds on last.
    $quizsettings->add(new admin_setting_configcheckbox_with_advanced('gnrquiz/attemptonlast',
            get_string('eachattemptbuildsonthelast', 'gnrquiz'),
            get_string('configeachattemptbuildsonthelast', 'gnrquiz'),
            array('value' => 0, 'adv' => true)));

    // Review options.
    $quizsettings->add(new admin_setting_heading('reviewheading',
            get_string('reviewoptionsheading', 'gnrquiz'), ''));
    foreach (mod_gnrquiz_admin_review_setting::fields() as $field => $name) {
        $default = mod_gnrquiz_admin_review_setting::all_on();
        $forceduring = null;
        if ($field == 'attempt') {
            $forceduring = true;
        } else if ($field == 'overallfeedback') {
            $default = $default ^ mod_gnrquiz_admin_review_setting::DURING;
            $forceduring = false;
        }
        $quizsettings->add(new mod_gnrquiz_admin_review_setting('gnrquiz/review' . $field,
                $name, '', $default, $forceduring));
    }

    // Show the user's picture.
    $quizsettings->add(new mod_gnrquiz_admin_setting_user_image('gnrquiz/showuserpicture',
            get_string('showuserpicture', 'gnrquiz'), get_string('configshowuserpicture', 'gnrquiz'),
            array('value' => 0, 'adv' => false), null));

    // Decimal places for overall grades.
    $options = array();
    for ($i = 0; $i <= GNRQUIZ_MAX_DECIMAL_OPTION; $i++) {
        $options[$i] = $i;
    }
    $quizsettings->add(new admin_setting_configselect_with_advanced('gnrquiz/decimalpoints',
            get_string('decimalplaces', 'gnrquiz'), get_string('configdecimalplaces', 'gnrquiz'),
            array('value' => 2, 'adv' => false), $options));

    // Decimal places for question grades.
    $options = array(-1 => get_string('sameasoverall', 'gnrquiz'));
    for ($i = 0; $i <= GNRQUIZ_MAX_Q_DECIMAL_OPTION; $i++) {
        $options[$i] = $i;
    }
    $quizsettings->add(new admin_setting_configselect_with_advanced('gnrquiz/questiondecimalpoints',
            get_string('decimalplacesquestion', 'gnrquiz'),
            get_string('configdecimalplacesquestion', 'gnrquiz'),
            array('value' => -1, 'adv' => true), $options));

    // Show blocks during quiz attempts.
    $quizsettings->add(new admin_setting_configcheckbox_with_advanced('gnrquiz/showblocks',
            get_string('showblocks', 'gnrquiz'), get_string('configshowblocks', 'gnrquiz'),
            array('value' => 0, 'adv' => true)));

    // Password.
    $quizsettings->add(new admin_setting_configtext_with_advanced('gnrquiz/password',
            get_string('requirepassword', 'gnrquiz'), get_string('configrequirepassword', 'gnrquiz'),
            array('value' => '', 'adv' => false), PARAM_TEXT));

    // IP restrictions.
    $quizsettings->add(new admin_setting_configtext_with_advanced('gnrquiz/subnet',
            get_string('requiresubnet', 'gnrquiz'), get_string('configrequiresubnet', 'gnrquiz'),
            array('value' => '', 'adv' => true), PARAM_TEXT));

    // Enforced delay between attempts.
    $quizsettings->add(new admin_setting_configduration_with_advanced('gnrquiz/delay1',
            get_string('delay1st2nd', 'gnrquiz'), get_string('configdelay1st2nd', 'gnrquiz'),
            array('value' => 0, 'adv' => true), 60));
    $quizsettings->add(new admin_setting_configduration_with_advanced('gnrquiz/delay2',
            get_string('delaylater', 'gnrquiz'), get_string('configdelaylater', 'gnrquiz'),
            array('value' => 0, 'adv' => true), 60));

    // Browser security.
    $quizsettings->add(new mod_gnrquiz_admin_setting_browsersecurity('gnrquiz/browsersecurity',
            get_string('showinsecurepopup', 'gnrquiz'), get_string('configpopup', 'gnrquiz'),
            array('value' => '-', 'adv' => true), null));

    $quizsettings->add(new admin_setting_configtext('gnrquiz/initialnumfeedbacks',
            get_string('initialnumfeedbacks', 'gnrquiz'), get_string('initialnumfeedbacks_desc', 'gnrquiz'),
            2, PARAM_INT, 5));

    // Allow user to specify if setting outcomes is an advanced setting.
    if (!empty($CFG->enableoutcomes)) {
        $quizsettings->add(new admin_setting_configcheckbox('gnrquiz/outcomes_adv',
            get_string('outcomesadvanced', 'gnrquiz'), get_string('configoutcomesadvanced', 'gnrquiz'),
            '0'));
    }

    // Autosave frequency.
    $quizsettings->add(new admin_setting_configduration('gnrquiz/autosaveperiod',
            get_string('autosaveperiod', 'gnrquiz'), get_string('autosaveperiod_desc', 'gnrquiz'), 60, 1));
}

// Now, depending on whether any reports have their own settings page, add
// the quiz setting page to the appropriate place in the tree.
if (empty($reportsbyname) && empty($rulesbyname)) {
    $ADMIN->add('modsettings', $quizsettings);
} else {
    $ADMIN->add('modsettings', new admin_category('modsettingsquizcat',
            get_string('modulename', 'gnrquiz'), $module->is_enabled() === false));
    $ADMIN->add('modsettingsquizcat', $quizsettings);

    // Add settings pages for the quiz report subplugins.
    foreach ($reportsbyname as $strreportname => $report) {
        $reportname = $report;

        $settings = new admin_settingpage('modsettingsquizcat'.$reportname,
                $strreportname, 'moodle/site:config', $module->is_enabled() === false);
        if ($ADMIN->fulltree) {
            include($CFG->dirroot . "/mod/gnrquiz/report/$reportname/settings.php");
        }
        if (!empty($settings)) {
            $ADMIN->add('modsettingsquizcat', $settings);
        }
    }

    // Add settings pages for the quiz access rule subplugins.
    foreach ($rulesbyname as $strrulename => $rule) {
        $settings = new admin_settingpage('modsettingsquizcat' . $rule,
                $strrulename, 'moodle/site:config', $module->is_enabled() === false);
        if ($ADMIN->fulltree) {
            include($CFG->dirroot . "/mod/gnrquiz/accessrule/$rule/settings.php");
        }
        if (!empty($settings)) {
            $ADMIN->add('modsettingsquizcat', $settings);
        }
    }
}

$settings = null; // We do not want standard settings link.
