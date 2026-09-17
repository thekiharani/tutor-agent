<?php
namespace local_tutoragent;

use core\hook\navigation\primary_extend;
use moodle_url;
use navigation_node;

/**
 * Puts the questionnaire in the top navigation bar.
 *
 * Moodle 5.2 builds the navigation from the primary view, so the older
 * local_*_extend_navigation callback runs but surfaces nothing.
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
