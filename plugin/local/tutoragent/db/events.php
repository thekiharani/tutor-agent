<?php
// Event observers for local_tutoragent.

defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        // Moodle dispatches to observers registered on a parent event class, so
        // this catches \mod_page\event\course_module_viewed and its siblings.
        'eventname' => '\core\event\course_module_viewed',
        'callback' => '\local_tutoragent\observer::course_module_viewed',
    ],
    [
        // A student who lands on the course page but has no learning style yet
        // gets the invitation there, rather than having to open an activity
        // first to discover the questionnaire exists.
        'eventname' => '\core\event\course_viewed',
        'callback' => '\local_tutoragent\observer::course_viewed',
    ],
];
