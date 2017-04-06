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
 * Implementaton of the gnrquizaccess_openclosedate plugin.
 *
 * @package    gnrquizaccess
 * @subpackage openclosedate
 * @copyright  2011 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/gnrquiz/accessrule/accessrulebase.php');


/**
 * A rule enforcing open and close dates.
 *
 * @copyright  2009 Tim Hunt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class gnrquizaccess_openclosedate extends gnrquiz_access_rule_base {

    public static function make(gnrquiz $gnrquizobj, $timenow, $canignoretimelimits) {
        // This rule is always used, even if the gnrquiz has no open or close date.
        return new self($gnrquizobj, $timenow);
    }

    public function description() {
        $result = array();
        if ($this->timenow < $this->gnrquiz->timeopen) {
            $result[] = get_string('gnrquiznotavailable', 'gnrquizaccess_openclosedate',
                    userdate($this->gnrquiz->timeopen));
            if ($this->gnrquiz->timeclose) {
                $result[] = get_string('gnrquizcloseson', 'gnrquiz', userdate($this->gnrquiz->timeclose));
            }

        } else if ($this->gnrquiz->timeclose && $this->timenow > $this->gnrquiz->timeclose) {
            $result[] = get_string('gnrquizclosed', 'gnrquiz', userdate($this->gnrquiz->timeclose));

        } else {
            if ($this->gnrquiz->timeopen) {
                $result[] = get_string('gnrquizopenedon', 'gnrquiz', userdate($this->gnrquiz->timeopen));
            }
            if ($this->gnrquiz->timeclose) {
                $result[] = get_string('gnrquizcloseson', 'gnrquiz', userdate($this->gnrquiz->timeclose));
            }
        }

        return $result;
    }

    public function prevent_access() {
        $message = get_string('notavailable', 'gnrquizaccess_openclosedate');

        if ($this->timenow < $this->gnrquiz->timeopen) {
            return $message;
        }

        if (!$this->gnrquiz->timeclose) {
            return false;
        }

        if ($this->timenow <= $this->gnrquiz->timeclose) {
            return false;
        }

        if ($this->gnrquiz->overduehandling != 'graceperiod') {
            return $message;
        }

        if ($this->timenow <= $this->gnrquiz->timeclose + $this->gnrquiz->graceperiod) {
            return false;
        }

        return $message;
    }

    public function is_finished($numprevattempts, $lastattempt) {
        return $this->gnrquiz->timeclose && $this->timenow > $this->gnrquiz->timeclose;
    }

    public function end_time($attempt) {
        if ($this->gnrquiz->timeclose) {
            return $this->gnrquiz->timeclose;
        }
        return false;
    }

    public function time_left_display($attempt, $timenow) {
        // If this is a teacher preview after the close date, do not show
        // the time.
        if ($attempt->preview && $timenow > $this->gnrquiz->timeclose) {
            return false;
        }
        // Otherwise, return to the time left until the close date, providing that is
        // less than GNRQUIZ_SHOW_TIME_BEFORE_DEADLINE.
        $endtime = $this->end_time($attempt);
        if ($endtime !== false && $timenow > $endtime - GNRQUIZ_SHOW_TIME_BEFORE_DEADLINE) {
            return $endtime - $timenow;
        }
        return false;
    }
}
