<?php
defined('MOODLE_INTERNAL') || die();

$callbacks = [
    [
        'hook' => \core\hook\navigation\primary_extend::class,
        'callback' => '\local_tutoragent\hook_callbacks::extend_primary_navigation',
    ],
    [
        'hook' => \core\hook\after_config::class,
        'callback' => '\local_tutoragent\hook_callbacks::after_config',
    ],
];
