<?php
defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage(
        'local_tutoragent',
        get_string('pluginname', 'local_tutoragent')
    );
    $ADMIN->add('localplugins', $settings);

    // PARAM_RAW_TRIMMED, not PARAM_URL: the default is a Docker service name
    // with no dot in it, which PARAM_URL rejects.
    $settings->add(new admin_setting_configtext(
        'local_tutoragent/recommenderurl',
        get_string('recommenderurl', 'local_tutoragent'),
        get_string('recommenderurl_desc', 'local_tutoragent'),
        'http://recommender:8000',
        PARAM_RAW_TRIMMED
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_tutoragent/forceredirect',
        get_string('forceredirect', 'local_tutoragent'),
        get_string('forceredirect_desc', 'local_tutoragent'),
        1
    ));
}
