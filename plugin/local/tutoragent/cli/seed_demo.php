<?php
// One course, four activities, one extra site admin and ten students, all
// sharing DEMO_PASSWORD. `make rehearse` passes --reset-blank to clear every
// student.blank* account for another run.

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

const SEED_ADMIN = ['username' => 'demo.admin', 'firstname' => 'Dana', 'lastname' => 'Admin'];

const SEED_USERS = [
    ['username' => 'student.visual', 'firstname' => 'Vera', 'lastname' => 'Visual', 'style' => 'visual'],
    ['username' => 'student.aural', 'firstname' => 'Alan', 'lastname' => 'Aural', 'style' => 'auditory'],
    ['username' => 'student.rw', 'firstname' => 'Rita', 'lastname' => 'Reader', 'style' => 'read_write'],
    ['username' => 'student.kines', 'firstname' => 'Ken', 'lastname' => 'Kinetic', 'style' => 'kinesthetic'],
    // Several with no style, so the questionnaire can be demonstrated more than
    // once, or handed to someone in the room to try.
    ['username' => 'student.blank', 'firstname' => 'Blair', 'lastname' => 'Blank', 'style' => null],
    ['username' => 'student.blank2', 'firstname' => 'Bruno', 'lastname' => 'Blank', 'style' => null],
    ['username' => 'student.blank3', 'firstname' => 'Bella', 'lastname' => 'Blank', 'style' => null],
    ['username' => 'student.blank4', 'firstname' => 'Bilal', 'lastname' => 'Blank', 'style' => null],
    ['username' => 'student.blank5', 'firstname' => 'Bina', 'lastname' => 'Blank', 'style' => null],
    ['username' => 'student.blank6', 'firstname' => 'Bram', 'lastname' => 'Blank', 'style' => null],
];

list($options) = cli_get_params(['reset-blank' => false, 'help' => false], ['h' => 'help']);

if ($options['help']) {
    cli_writeln("Seed the demo course, activities, admin and students.\n"
        . "  --reset-blank   Only clear the student.blank* learning styles, for another rehearsal.\n");
    exit(0);
}

if ($options['reset-blank']) {
    $blanks = $DB->get_fieldset_select('user', 'id', $DB->sql_like('username', ':name'),
        ['name' => 'student.blank%']);
    if (!$blanks) {
        cli_error('No student.blank* users found. Run `make seed` first.');
    }
    list($insql, $params) = $DB->get_in_or_equal($blanks, SQL_PARAMS_NAMED);
    $DB->delete_records_select('local_tutoragent_vark', "userid $insql", $params);
    cli_writeln(count($blanks) . ' blank students have no learning style again. Ready for another run.');
    exit(0);
}

if ($DB->record_exists('course', ['shortname' => SEED_SHORTNAME])) {
    cli_error("The demo course '" . SEED_SHORTNAME . "' already exists. "
        . "Run 'make reset' for a clean site, then 'make seed' again.");
}

$password = getenv('DEMO_PASSWORD');
if (empty($password)) {
    cli_error('DEMO_PASSWORD is not set. It comes from .env via compose.yml.');
}

/** Every seeded account is identical apart from its name. */
function seed_create_user(array $person, string $password): int {
    global $CFG;

    return user_create_user((object) [
        'username' => $person['username'],
        'password' => $password,
        'firstname' => $person['firstname'],
        'lastname' => $person['lastname'],
        'email' => $person['username'] . '@example.com',
        'auth' => 'manual',
        'confirmed' => 1,
        'mnethostid' => $CFG->mnet_localhost_id,
        'policyagreed' => 1,
    ], true, false);
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

    // create_course() makes the sections but leaves them all called "New
    // section", which is what the course index then shows.
    $section = $DB->get_record('course_sections',
        ['course' => $course->id, 'section' => $index + 1], '*', MUST_EXIST);
    course_update_section($course, $section, ['name' => $activity['name']]);

    cli_writeln("  activity '{$activity['name']}' (cmid {$created->coursemodule})");
}

cli_writeln('Creating the second site administrator...');
$adminid = seed_create_user(SEED_ADMIN, $password);
// Site admins are a config list, not a role assignment.
$siteadmins = array_filter(explode(',', (string) $CFG->siteadmins));
$siteadmins[] = $adminid;
set_config('siteadmins', implode(',', array_unique($siteadmins)));
cli_writeln('  ' . SEED_ADMIN['username'] . ' (site administrator)');

cli_writeln('Creating the students...');
foreach (SEED_USERS as $seeduser) {
    $userid = seed_create_user($seeduser, $password);

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
