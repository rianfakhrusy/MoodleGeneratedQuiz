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
 * Implementaton of the gnrquizaccess_ipaddress plugin.
 *
 * @package    gnrquizaccess
 * @subpackage ipaddress
 * @copyright  2011 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/gnrquiz/accessrule/accessrulebase.php');


/**
 * A rule implementing the ipaddress check against the ->subnet setting.
 *
 * @copyright  2009 Tim Hunt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class gnrquizaccess_ipaddress extends gnrquiz_access_rule_base {

    public static function make(gnrquiz $gnrquizobj, $timenow, $canignoretimelimits) {
        if (empty($gnrquizobj->get_gnrquiz()->subnet)) {
            return null;
        }

        return new self($gnrquizobj, $timenow);
    }

    public function prevent_access() {
        if (address_in_subnet(getremoteaddr(), $this->gnrquiz->subnet)) {
            return false;
        } else {
            return get_string('subnetwrong', 'gnrquizaccess_ipaddress');
        }
    }
}
