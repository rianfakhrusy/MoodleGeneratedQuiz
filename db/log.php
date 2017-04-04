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
 * Definition of log events for the quiz module.
 *
 * @package    mod_quiz
 * @category   log
 * @copyright  2010 Petr Skoda (http://skodak.org)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$logs = array(
    array('module'=>'gnrquiz', 'action'=>'add', 'mtable'=>'gnrquiz', 'field'=>'name'),
    array('module'=>'gnrquiz', 'action'=>'update', 'mtable'=>'gnrquiz', 'field'=>'name'),
    array('module'=>'gnrquiz', 'action'=>'view', 'mtable'=>'gnrquiz', 'field'=>'name'),
    array('module'=>'gnrquiz', 'action'=>'report', 'mtable'=>'gnrquiz', 'field'=>'name'),
    array('module'=>'gnrquiz', 'action'=>'attempt', 'mtable'=>'gnrquiz', 'field'=>'name'),
    array('module'=>'gnrquiz', 'action'=>'submit', 'mtable'=>'gnrquiz', 'field'=>'name'),
    array('module'=>'gnrquiz', 'action'=>'review', 'mtable'=>'gnrquiz', 'field'=>'name'),
    array('module'=>'gnrquiz', 'action'=>'editquestions', 'mtable'=>'gnrquiz', 'field'=>'name'),
    array('module'=>'gnrquiz', 'action'=>'preview', 'mtable'=>'gnrquiz', 'field'=>'name'),
    array('module'=>'gnrquiz', 'action'=>'start attempt', 'mtable'=>'gnrquiz', 'field'=>'name'),
    array('module'=>'gnrquiz', 'action'=>'close attempt', 'mtable'=>'gnrquiz', 'field'=>'name'),
    array('module'=>'gnrquiz', 'action'=>'continue attempt', 'mtable'=>'gnrquiz', 'field'=>'name'),
    array('module'=>'gnrquiz', 'action'=>'edit override', 'mtable'=>'gnrquiz', 'field'=>'name'),
    array('module'=>'gnrquiz', 'action'=>'delete override', 'mtable'=>'gnrquiz', 'field'=>'name'),
    array('module'=>'gnrquiz', 'action'=>'view summary', 'mtable'=>'gnrquiz', 'field'=>'name'),
);