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
 * JavaScript library for the gnrquiz module.
 *
 * @package    mod
 * @subpackage gnrquiz
 * @copyright  1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

M.mod_gnrquiz = M.mod_gnrquiz || {};

M.mod_gnrquiz.init_attempt_form = function(Y) {
    M.core_question_engine.init_form(Y, '#responseform');
    Y.on('submit', M.mod_gnrquiz.timer.stop, '#responseform');
    M.core_formchangechecker.init({formid: 'responseform'});
};

M.mod_gnrquiz.init_review_form = function(Y) {
    M.core_question_engine.init_form(Y, '.questionflagsaveform');
    Y.on('submit', function(e) { e.halt(); }, '.questionflagsaveform');
};

M.mod_gnrquiz.init_comment_popup = function(Y) {
    // Add a close button to the window.
    var closebutton = Y.Node.create('<input type="button" />');
    closebutton.set('value', M.util.get_string('cancel', 'moodle'));
    Y.one('#id_submitbutton').ancestor().append(closebutton);
    Y.on('click', function() { window.close() }, closebutton);
}

// Code for updating the countdown timer that is used on timed gnrquizzes.
M.mod_gnrquiz.timer = {
    // YUI object.
    Y: null,

    // Timestamp at which time runs out, according to the student's computer's clock.
    endtime: 0,

    // Is this a gnrquiz preview?
    preview: 0,

    // This records the id of the timeout that updates the clock periodically,
    // so we can cancel.
    timeoutid: null,

    /**
     * @param Y the YUI object
     * @param start, the timer starting time, in seconds.
     * @param preview, is this a gnrquiz preview?
     */
    init: function(Y, start, preview) {
        M.mod_gnrquiz.timer.Y = Y;
        M.mod_gnrquiz.timer.endtime = M.pageloadstarttime.getTime() + start*1000;
        M.mod_gnrquiz.timer.preview = preview;
        M.mod_gnrquiz.timer.update();
        Y.one('#gnrquiz-timer').setStyle('display', 'block');
    },

    /**
     * Stop the timer, if it is running.
     */
    stop: function(e) {
        if (M.mod_gnrquiz.timer.timeoutid) {
            clearTimeout(M.mod_gnrquiz.timer.timeoutid);
        }
    },

    /**
     * Function to convert a number between 0 and 99 to a two-digit string.
     */
    two_digit: function(num) {
        if (num < 10) {
            return '0' + num;
        } else {
            return num;
        }
    },

    // Function to update the clock with the current time left, and submit the gnrquiz if necessary.
    update: function() {
        var Y = M.mod_gnrquiz.timer.Y;
        var secondsleft = Math.floor((M.mod_gnrquiz.timer.endtime - new Date().getTime())/1000);

        // If time has expired, set the hidden form field that says time has expired and submit
        if (secondsleft < 0) {
            M.mod_gnrquiz.timer.stop(null);
            Y.one('#gnrquiz-time-left').setContent(M.util.get_string('timesup', 'gnrquiz'));
            var input = Y.one('input[name=timeup]');
            input.set('value', 1);
            var form = input.ancestor('form');
            if (form.one('input[name=finishattempt]')) {
                form.one('input[name=finishattempt]').set('value', 0);
            }
            M.core_formchangechecker.set_form_submitted();
            form.submit();
            return;
        }

        // If time has nearly expired, change the colour.
        if (secondsleft < 100) {
            Y.one('#gnrquiz-timer').removeClass('timeleft' + (secondsleft + 2))
                    .removeClass('timeleft' + (secondsleft + 1))
                    .addClass('timeleft' + secondsleft);
        }

        // Update the time display.
        var hours = Math.floor(secondsleft/3600);
        secondsleft -= hours*3600;
        var minutes = Math.floor(secondsleft/60);
        secondsleft -= minutes*60;
        var seconds = secondsleft;
        Y.one('#gnrquiz-time-left').setContent(hours + ':' +
                M.mod_gnrquiz.timer.two_digit(minutes) + ':' +
                M.mod_gnrquiz.timer.two_digit(seconds));

        // Arrange for this method to be called again soon.
        M.mod_gnrquiz.timer.timeoutid = setTimeout(M.mod_gnrquiz.timer.update, 100);
    }
};

M.mod_gnrquiz.nav = M.mod_gnrquiz.nav || {};

M.mod_gnrquiz.nav.update_flag_state = function(attemptid, questionid, newstate) {
    var Y = M.mod_gnrquiz.nav.Y;
    var navlink = Y.one('#gnrquiznavbutton' + questionid);
    navlink.removeClass('flagged');
    if (newstate == 1) {
        navlink.addClass('flagged');
        navlink.one('.accesshide .flagstate').setContent(M.util.get_string('flagged', 'question'));
    } else {
        navlink.one('.accesshide .flagstate').setContent('');
    }
};

M.mod_gnrquiz.nav.init = function(Y) {
    M.mod_gnrquiz.nav.Y = Y;

    Y.all('#gnrquiznojswarning').remove();

    var form = Y.one('#responseform');
    if (form) {
        function nav_to_page(pageno) {
            Y.one('#followingpage').set('value', pageno);

            // Automatically submit the form. We do it this strange way because just
            // calling form.submit() does not run the form's submit event handlers.
            var submit = form.one('input[name="next"]');
            submit.set('name', '');
            submit.getDOMNode().click();
        };

        Y.delegate('click', function(e) {
            if (this.hasClass('thispage')) {
                return;
            }

            e.preventDefault();

            var pageidmatch = this.get('href').match(/page=(\d+)/);
            var pageno;
            if (pageidmatch) {
                pageno = pageidmatch[1];
            } else {
                pageno = 0;
            }

            var questionidmatch = this.get('href').match(/#q(\d+)/);
            if (questionidmatch) {
                form.set('action', form.get('action') + '#q' + questionidmatch[1]);
            }

            nav_to_page(pageno);
        }, document.body, '.qnbutton');
    }

    if (Y.one('a.endtestlink')) {
        Y.on('click', function(e) {
            e.preventDefault();
            nav_to_page(-1);
        }, 'a.endtestlink');
    }

    if (M.core_question_flags) {
        M.core_question_flags.add_listener(M.mod_gnrquiz.nav.update_flag_state);
    }
};

M.mod_gnrquiz.secure_window = {
    init: function(Y) {
        if (window.location.href.substring(0, 4) == 'file') {
            window.location = 'about:blank';
        }
        Y.delegate('contextmenu', M.mod_gnrquiz.secure_window.prevent, document, '*');
        Y.delegate('mousedown',   M.mod_gnrquiz.secure_window.prevent_mouse, 'body', '*');
        Y.delegate('mouseup',     M.mod_gnrquiz.secure_window.prevent_mouse, 'body', '*');
        Y.delegate('dragstart',   M.mod_gnrquiz.secure_window.prevent, document, '*');
        Y.delegate('selectstart', M.mod_gnrquiz.secure_window.prevent_selection, document, '*');
        Y.delegate('cut',         M.mod_gnrquiz.secure_window.prevent, document, '*');
        Y.delegate('copy',        M.mod_gnrquiz.secure_window.prevent, document, '*');
        Y.delegate('paste',       M.mod_gnrquiz.secure_window.prevent, document, '*');
        Y.on('beforeprint', function() {
            Y.one(document.body).setStyle('display', 'none');
        }, window);
        Y.on('afterprint', function() {
            Y.one(document.body).setStyle('display', 'block');
        }, window);
        Y.on('key', M.mod_gnrquiz.secure_window.prevent, '*', 'press:67,86,88+ctrl');
        Y.on('key', M.mod_gnrquiz.secure_window.prevent, '*', 'up:67,86,88+ctrl');
        Y.on('key', M.mod_gnrquiz.secure_window.prevent, '*', 'down:67,86,88+ctrl');
        Y.on('key', M.mod_gnrquiz.secure_window.prevent, '*', 'press:67,86,88+meta');
        Y.on('key', M.mod_gnrquiz.secure_window.prevent, '*', 'up:67,86,88+meta');
        Y.on('key', M.mod_gnrquiz.secure_window.prevent, '*', 'down:67,86,88+meta');
    },

    is_content_editable: function(n) {
        if (n.test('[contenteditable=true]')) {
            return true;
        }
        n = n.get('parentNode');
        if (n === null) {
            return false;
        }
        return M.mod_gnrquiz.secure_window.is_content_editable(n);
    },

    prevent_selection: function(e) {
        return false;
    },

    prevent: function(e) {
        alert(M.util.get_string('functiondisabledbysecuremode', 'gnrquiz'));
        e.halt();
    },

    prevent_mouse: function(e) {
        if (e.button == 1 && /^(INPUT|TEXTAREA|BUTTON|SELECT|LABEL|A)$/i.test(e.target.get('tagName'))) {
            // Left click on a button or similar. No worries.
            return;
        }
        if (e.button == 1 && M.mod_gnrquiz.secure_window.is_content_editable(e.target)) {
            // Left click in Atto or similar.
            return;
        }
        e.halt();
    },

    init_close_button: function(Y, url) {
        Y.on('click', function(e) {
            M.mod_gnrquiz.secure_window.close(url, 0)
        }, '#secureclosebutton');
    },

    close: function(Y, url, delay) {
        setTimeout(function() {
            if (window.opener) {
                window.opener.document.location.reload();
                window.close();
            } else {
                window.location.href = url;
            }
        }, delay*1000);
    }
};
