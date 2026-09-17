<?php
defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        // Registering on the parent class catches \mod_page\event\* and siblings.
        'eventname' => '\core\event\course_module_viewed',
        'callback' => '\local_tutoragent\observer::course_module_viewed',
    ],
    [
        // So the invitation appears on the course page, not only inside an activity.
        'eventname' => '\core\event\course_viewed',
        'callback' => '\local_tutoragent\observer::course_viewed',
    ],
];
