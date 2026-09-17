<?php
// One course, four activities, five students. `make seed` runs it, `make
// rehearse` passes --reset-blank to clear student.blank for another run.

define('CLI_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/enrollib.php');
require_once($CFG->libdir . '/resourcelib.php');
require_once($CFG->dirroot . '/local/tutoragent/lib.php');

const SEED_SHORTNAME = 'PROG-C';

// One topic per intro: matching is substring-based over name and intro, so an
// Arrays intro mentioning "loops" would pull it towards control structures.
const SEED_ACTIVITIES = [
    [
        'name' => 'Arrays',
        'intro' => 'Declaring an array, the array index, and accessing elements in C.',
        'content' => '<p>An array stores several values of the same type under one name. '
            . 'This topic covers declaring an array, indexing it, and reading and assigning elements.</p>',
    ],
    [
        'name' => 'Control Structures',
        'intro' => 'Selection with if/else and switch, and repetition with for, while and do loops.',
        'content' => '<p>Control structures decide what runs and how often: if/else and switch for '
            . 'selection, and for, while and do while for repetition.</p>',
    ],
    [
        'name' => 'Functions',
        'intro' => 'Defining and calling functions, arguments, recursion and storage classes.',
        'content' => '<p>A function is a named block of code you can call. This topic covers defining '
            . 'and calling them, passing arguments, recursion, and storage classes.</p>',
    ],
    [
        'name' => 'Data Types',
        'intro' => 'Basic, derived, enumerated and void data types in C.',
        'content' => '<p>Every variable in C has a type. This topic covers the basic types, derived '
            . 'types, enumerated types and void.</p>',
    ],
];

const SEED_USERS = [
    ['username' => 'student.visual', 'firstname' => 'Vera', 'lastname' => 'Visual', 'style' => 'visual'],
    ['username' => 'student.aural', 'firstname' => 'Alan', 'lastname' => 'Aural', 'style' => 'auditory'],
    ['username' => 'student.rw', 'firstname' => 'Rita', 'lastname' => 'Reader', 'style' => 'read_write'],
    ['username' => 'student.kines', 'firstname' => 'Ken', 'lastname' => 'Kinetic', 'style' => 'kinesthetic'],
    ['username' => 'student.blank', 'firstname' => 'Blair', 'lastname' => 'Blank', 'style' => null],
];

list($options) = cli_get_params(['reset-blank' => false, 'help' => false], ['h' => 'help']);

if ($options['help']) {
    cli_writeln("Seed the demo course, activities and students.\n"
        . "  --reset-blank   Only clear student.blank's learning style, for another rehearsal.\n");
    exit(0);
}

if ($options['reset-blank']) {
    $blank = $DB->get_record('user', ['username' => 'student.blank'], 'id', MUST_EXIST);
    $DB->delete_records('local_tutoragent_vark', ['userid' => $blank->id]);
    cli_writeln('student.blank has no learning style again. Ready for another run.');
    exit(0);
}

if ($DB->record_exists('course', ['shortname' => SEED_SHORTNAME])) {
    cli_error("The demo course '" . SEED_SHORTNAME . "' already exists. "
        . "Run 'make reset' for a clean site, then 'make seed' again.");
}

$password = getenv('DEMO_STUDENT_PASS');
if (empty($password)) {
    cli_error('DEMO_STUDENT_PASS is not set. It comes from .env via docker-compose.yml.');
}

$studentrole = $DB->get_record('role', ['shortname' => 'student'], '*', MUST_EXIST);
$categoryid = $DB->get_field_sql('SELECT MIN(id) FROM {course_categories}');

cli_writeln('Creating the course...');
$course = create_course((object) [
    'fullname' => 'Introduction to Programming in C',
    'shortname' => SEED_SHORTNAME,
    'category' => $categoryid,
    'summary' => 'A first course in C, used to demonstrate personalised resource recommendations.',
    'summaryformat' => FORMAT_HTML,
    'format' => 'topics',
    'numsections' => 4,
    'visible' => 1,
]);
cli_writeln("  course id {$course->id}");

foreach (SEED_ACTIVITIES as $index => $activity) {
    // add_moduleinfo() wants more than its signature suggests: the generic
    // course_modules fields plus whatever the module's add_instance() reads.
    $moduleinfo = (object) [
        'modulename' => 'page',
        'module' => $DB->get_field('modules', 'id', ['name' => 'page'], MUST_EXIST),
        'course' => $course->id,
        'section' => $index + 1,
        'name' => $activity['name'],
        'intro' => $activity['intro'],
        'introformat' => FORMAT_HTML,
        'content' => $activity['content'],
        'contentformat' => FORMAT_HTML,
        'display' => RESOURCELIB_DISPLAY_OPEN,
        'printintro' => 1,
        'printlastmodified' => 1,
        'showdescription' => 1,
        'visible' => 1,
        'visibleoncoursepage' => 1,
        'cmidnumber' => '',
        'groupmode' => 0,
        'groupingid' => 0,
        'completion' => 0,
        'completionview' => 0,
        'completionexpected' => 0,
    ];

    $created = add_moduleinfo($moduleinfo, $course);
    cli_writeln("  activity '{$activity['name']}' (cmid {$created->coursemodule})");
}

cli_writeln('Creating the students...');
foreach (SEED_USERS as $seeduser) {
    $userid = user_create_user((object) [
        'username' => $seeduser['username'],
        'password' => $password,
        'firstname' => $seeduser['firstname'],
        'lastname' => $seeduser['lastname'],
        'email' => $seeduser['username'] . '@example.com',
        'auth' => 'manual',
        'confirmed' => 1,
        'mnethostid' => $CFG->mnet_localhost_id,
        'policyagreed' => 1,
    ], true, false);

    enrol_try_internal_enrol($course->id, $userid, $studentrole->id);

    if ($seeduser['style'] !== null) {
        // Flagged as seeded so nobody mistakes it for a real submission.
        $dominant = array_search($seeduser['style'], LOCAL_TUTORAGENT_STYLES, true);
        $counts = ['V' => 3, 'A' => 3, 'R' => 3, 'K' => 3];
        $counts[$dominant] = 9;

        local_tutoragent_save_style($userid, $seeduser['style'], [
            'counts' => $counts,
            'dominant' => $dominant,
            'tiebreak' => null,
            'seeded' => true,
        ]);
    }

    cli_writeln("  {$seeduser['username']} (" . ($seeduser['style'] ?? 'no style yet') . ')');
}

purge_all_caches();

cli_writeln('');
cli_writeln('Done. Run `make demo` for the logins and the demo script.');
