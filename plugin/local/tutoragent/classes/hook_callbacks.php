<?php
namespace local_tutoragent;

use core\hook\after_config;
use core\hook\navigation\primary_extend;
use moodle_url;
use navigation_node;

/**
 * Moodle 5.2 builds the navigation from the primary view, so the older
 * local_*_extend_navigation callback runs but surfaces nothing.
 */
class hook_callbacks {

    /**
     * Paths a student without a learning style may still reach.
     *
     * The asset entries are not optional: without them the questionnaire is
     * served with no CSS and no JavaScript, and the form cannot submit. The
     * /login and /user/policy entries keep this gate from fighting Moodle's
     * own forced flows, which would bounce the user between two screens.
     */
    private const ALLOWED_PATHS = [
        '/local/tutoragent/vark.php',
        '/login/',
        '/user/policy.php',
        '/pluginfile.php',
        '/tokenpluginfile.php',
        '/draftfile.php',
        '/theme/',
        '/lib/javascript.php',
        '/lib/requirejs.php',
        '/lib/ajax/',
    ];

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

    /**
     * Send a student with no learning style to the questionnaire, whatever page
     * they asked for.
     *
     * This runs on every request, so it must never throw: anything escaping
     * here takes the whole site down rather than one page. Site admins are
     * never gated, which is how you recover if this misbehaves; failing that,
     * admin/cli/cfg.php can clear forceredirect without loading a page.
     */
    public static function after_config(after_config $hook): void {
        global $CFG, $SCRIPT, $SESSION, $USER;

        try {
            if (CLI_SCRIPT
                || (defined('AJAX_SCRIPT') && AJAX_SCRIPT)
                || (defined('WS_SERVER') && WS_SERVER)
                || (defined('NO_MOODLE_COOKIES') && NO_MOODLE_COOKIES)) {
                return;
            }

            // Nothing is reliable mid-install or mid-upgrade.
            if (during_initial_install() || isset($CFG->upgraderunning) || !empty($CFG->adminsetuppending)) {
                return;
            }

            if (empty($SCRIPT)) {
                // Cannot identify the request, so do not gate it.
                return;
            }

            if (!get_config('local_tutoragent', 'forceredirect')) {
                return;
            }

            if (!isloggedin() || isguestuser() || is_siteadmin()
                || \core\session\manager::is_loggedinas()) {
                return;
            }

            foreach (self::ALLOWED_PATHS as $allowed) {
                if (strpos($SCRIPT, $allowed) === 0) {
                    return;
                }
            }

            require_once($CFG->dirroot . '/local/tutoragent/lib.php');
            if (local_tutoragent_get_style($USER->id)) {
                return;
            }

            $SESSION->wantsurl = qualified_me();
            redirect(new moodle_url('/local/tutoragent/vark.php'));
        } catch (\Throwable $e) {
            // A gate that breaks must fail open, never closed.
            return;
        }
    }
}
