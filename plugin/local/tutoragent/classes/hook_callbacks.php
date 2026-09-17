<?php
// Hook callbacks for local_tutoragent.

namespace local_tutoragent;

use core\hook\navigation\primary_extend;
use moodle_url;
use navigation_node;

/**
 * Puts the questionnaire in the top navigation bar.
 *
 * This is how a student finds the questionnaire. The thesis forced a redirect
 * instead; a site-wide redirect is one bad conditional away from locking the
 * admin out of their own site, so it is a link and a notification instead.
 *
 * Moodle 5.2 builds the navigation drawer from the primary view, so the older
 * local_*_extend_navigation callback in lib.php no longer surfaces anything.
 */
class hook_callbacks {

    public static function extend_primary_navigation(primary_extend $hook): void {
        if (!isloggedin() || isguestuser() || during_initial_install()) {
            return;
        }

        $hook->get_primaryview()->add(
            get_string('mylearningstyle', 'local_tutoragent'),
            new moodle_url('/local/tutoragent/vark.php'),
            navigation_node::TYPE_CUSTOM,
            null,
            'local_tutoragent'
        );
    }
}
