<?php
// Event observer for local_tutoragent.

namespace local_tutoragent;

use core\event\course_module_viewed;
use core\event\course_viewed;
use core\output\notification;
use moodle_url;

/**
 * Turns "a student opened an activity" into a recommendation.
 *
 * Observers run in the middle of a request, before the page has finished
 * rendering. Two rules follow from that and neither is negotiable:
 *
 *   - Nothing here may write to the output buffer. Writing from an observer
 *     corrupts the headers and trips "Coding error: unexpected output". The
 *     2022 original sent its response straight to the page, which is one
 *     reason it could not work. Use \core\notification instead.
 *   - Nothing here may be slow or throw. Every failure path returns quietly,
 *     and the HTTP call has a 1s connect / 2s total timeout, so a dead
 *     recommender costs the page nothing.
 */
class observer {

    /**
     * On the course page, invite a student who has no learning style yet.
     */
    public static function course_viewed(course_viewed $event): void {
        global $CFG;

        if (!self::should_act($event->userid)) {
            return;
        }

        require_once($CFG->dirroot . '/local/tutoragent/lib.php');

        if (!local_tutoragent_get_style($event->userid)) {
            self::invite_to_questionnaire();
        }
    }

    public static function course_module_viewed(course_module_viewed $event): void {
        global $DB, $CFG;

        if (!self::should_act($event->userid)) {
            return;
        }

        require_once($CFG->dirroot . '/local/tutoragent/lib.php');

        $style = local_tutoragent_get_style($event->userid);
        if (!$style) {
            self::invite_to_questionnaire();
            return;
        }

        // A real conditions array. The original passed array(), which returns an
        // arbitrary row: whatever activity happened to come first in the table.
        $module = $DB->get_record(
            $event->objecttable,
            ['id' => $event->objectid],
            'name, intro',
            IGNORE_MISSING
        );
        if (!$module) {
            return;
        }

        $html = local_tutoragent_request_recommendation(
            strip_tags($module->name),
            strip_tags($module->intro ?? ''),
            $style->style
        );
        if ($html === null) {
            return;
        }

        \core\notification::add($html, notification::NOTIFY_INFO);
    }

    /**
     * True only when there is a page to put a notification on and the event is
     * about the person looking at it.
     */
    private static function should_act(?int $userid): bool {
        global $USER;

        if (CLI_SCRIPT || (defined('AJAX_SCRIPT') && AJAX_SCRIPT) || (defined('WS_SERVER') && WS_SERVER)) {
            return false;
        }

        return !empty($userid) && isloggedin() && !isguestuser() && $userid == $USER->id;
    }

    private static function invite_to_questionnaire(): void {
        \core\notification::add(
            get_string('takequestionnaire', 'local_tutoragent',
                (new moodle_url('/local/tutoragent/vark.php'))->out()),
            notification::NOTIFY_INFO
        );
    }
}
