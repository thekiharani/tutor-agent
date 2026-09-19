<?php
// Ten courses, thirty-eight activities, one extra site admin and ten students
// enrolled in everything, all sharing DEMO_PASSWORD. `make rehearse` passes
// --reset-blank to clear every student.blank* account for another run.

define('CLI_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/enrollib.php');
require_once($CFG->libdir . '/resourcelib.php');
require_once($CFG->dirroot . '/local/tutoragent/lib.php');

// Every activity name or intro has to contain exactly one key from TOPICS in
// the recommender, and the longest key present is the one that wins: 'Functions' says 'return values'
// rather than 'recursion' because 'recursion' is a topic of its own and the
// longer match. recommender/app.py holds the table these have to agree with.
const SEED_COURSES = [
    [
        'key' => 'prog-c',
        'shortname' => 'CS 101',
        'idnumber' => 'CS101',
        'category' => 'fundamentals',
        'fullname' => 'Introduction to Programming in C',
        'summary' => 'A first course in C: the language core, from a first program through '
            . 'operators, control flow, arrays and functions.',
        'activities' => [
            [
                'name' => 'Introduction to C',
                'intro' => 'Your first C program, the main function, headers, and how a source '
                    . 'file becomes a running program.',
                'content' => '<p>C is a compiled language. This topic covers the shape of a source '
                    . 'file, the <code>main</code> function, including a header, printing '
                    . 'with <code>printf</code>, and the compile-and-run cycle.</p>',
            ],
            [
                'name' => 'Data Types',
                'intro' => 'Basic, derived, enumerated and void data types in C.',
                'content' => '<p>Every variable in C has a type. This topic covers the basic types, '
                    . 'derived types, enumerated types and void.</p>',
            ],
            [
                'name' => 'Operators',
                'intro' => 'Arithmetic, relational, logical, bitwise and assignment operators, and '
                    . 'the precedence rules that decide what binds first.',
                'content' => '<p>Operators combine values into expressions. This topic covers the '
                    . 'arithmetic, relational, logical, bitwise and assignment operators, and '
                    . 'the precedence and associativity rules that decide the order of '
                    . 'evaluation.</p>',
            ],
            [
                'name' => 'Control Structures',
                'intro' => 'Selection with if/else and switch, and repetition with for, while and '
                    . 'do loops.',
                'content' => '<p>Control structures decide what runs and how often: if/else and '
                    . 'switch for selection, and for, while and do while for repetition.</p>',
            ],
            [
                'name' => 'Arrays',
                'intro' => 'Declaring an array, the array index, and accessing elements in C.',
                'content' => '<p>An array stores several values of the same type under one name. '
                    . 'This topic covers declaring an array, indexing it, and reading and '
                    . 'assigning elements.</p>',
            ],
            [
                'name' => 'Functions',
                'intro' => 'Defining and calling functions, arguments, return values and storage '
                    . 'classes.',
                'content' => '<p>A function is a named block of code you can call. This topic covers '
                    . 'defining and calling them, passing arguments, recursion, and storage '
                    . 'classes.</p>',
            ],
        ],
    ],
    [
        'key' => 'cs-ds',
        'shortname' => 'CS 201',
        'idnumber' => 'CS201',
        'category' => 'core',
        'fullname' => 'Data Structures',
        'summary' => 'How data is organised in memory and what each arrangement costs: '
            . 'lists, stacks, queues, trees and hash tables.',
        'activities' => [
            [
                'name' => 'Linked Lists',
                'intro' => 'Nodes joined by pointers, traversing a list, and inserting or deleting '
                    . 'without shifting everything along.',
                'content' => '<p>A linked list stores each value in its own node, with a pointer to '
                    . 'the next. This topic covers traversal, insertion and deletion, and why '
                    . 'that differs from contiguous storage.</p>',
            ],
            [
                'name' => 'Stacks and Queues',
                'intro' => 'Last-in-first-out and first-in-first-out collections, with push, pop, '
                    . 'enqueue and dequeue.',
                'content' => '<p>A stack removes the most recently added item; a queue removes the '
                    . 'oldest. This topic covers both, their operations, and where each one '
                    . 'is the natural fit.</p>',
            ],
            [
                'name' => 'Binary Trees',
                'intro' => 'Root, leaves and subtrees, binary search trees, and the inorder, '
                    . 'preorder and postorder traversals.',
                'content' => '<p>A tree stores values in nodes with children rather than in a line. '
                    . 'This topic covers binary trees, the binary search tree property, and '
                    . 'the three traversal orders.</p>',
            ],
            [
                'name' => 'Hash Tables',
                'intro' => 'Turning a key into a bucket index, what happens when two keys collide, '
                    . 'and why lookup is fast.',
                'content' => '<p>A hash table maps a key to a position using a hash function. This '
                    . 'topic covers the hash function, collisions and chaining, and the cost '
                    . 'of a lookup.</p>',
            ],
        ],
    ],
    [
        'key' => 'cs-algo',
        'shortname' => 'CS 202',
        'idnumber' => 'CS202',
        'category' => 'core',
        'fullname' => 'Algorithms and Complexity',
        'summary' => 'Sorting, searching and recursion, and how to describe what an '
            . 'algorithm costs as its input grows.',
        'activities' => [
            [
                'name' => 'Sorting Algorithms',
                'intro' => 'Bubble, insertion, merge and quicksort: how each one orders a '
                    . 'sequence, and what each one costs.',
                'content' => '<p>Sorting puts a sequence in order. This topic covers bubble and '
                    . 'insertion sort, then merge sort and quicksort, and compares the work '
                    . 'each one does.</p>',
            ],
            [
                'name' => 'Searching Algorithms',
                'intro' => 'Linear search against binary search, and why binary search needs its '
                    . 'input already in order.',
                'content' => '<p>Searching finds a value in a collection. This topic covers linear '
                    . 'search, binary search on sorted data, and the halving argument behind '
                    . 'it.</p>',
            ],
            [
                'name' => 'Recursion',
                'intro' => 'A function that calls itself, the base case that stops it, and how the '
                    . 'call stack unwinds.',
                'content' => '<p>A recursive function solves a problem in terms of a smaller version '
                    . 'of itself. This topic covers the base case, the recursive case, and '
                    . 'what the call stack does while it runs.</p>',
            ],
            [
                'name' => 'Time Complexity',
                'intro' => 'Big-O notation, worst and average case, and comparing algorithms by '
                    . 'how they grow rather than by seconds.',
                'content' => '<p>Complexity describes how the work an algorithm does grows with its '
                    . 'input. This topic covers Big-O notation, worst and average case, and '
                    . 'reading a growth curve.</p>',
            ],
        ],
    ],
    [
        'key' => 'cs-oop',
        'shortname' => 'CS 203',
        'idnumber' => 'CS203',
        'category' => 'core',
        'fullname' => 'Object-Oriented Programming',
        'summary' => 'Modelling a problem as objects that hold their own data: classes, '
            . 'inheritance, polymorphism and encapsulation.',
        'activities' => [
            [
                'name' => 'Classes and Objects',
                'intro' => 'A class as a template and an object as an instance of it, with '
                    . 'attributes, methods and a constructor.',
                'content' => '<p>A class describes what its objects hold and what they can do. This '
                    . 'topic covers defining a class, creating instances, attributes, methods '
                    . 'and the constructor.</p>',
            ],
            [
                'name' => 'Inheritance',
                'intro' => 'Deriving one class from another, reusing what the parent defines, and '
                    . 'overriding what differs.',
                'content' => '<p>Inheritance lets a class build on another. This topic covers parent '
                    . 'and child classes, what is inherited, and overriding a method the '
                    . 'parent already defines.</p>',
            ],
            [
                'name' => 'Polymorphism',
                'intro' => 'One interface, several behaviours: overriding, overloading and '
                    . 'choosing an implementation at run time.',
                'content' => '<p>Polymorphism lets the same call do different things depending on '
                    . 'the object. This topic covers method overriding, overloading, and '
                    . 'dispatch at run time.</p>',
            ],
            [
                'name' => 'Encapsulation',
                'intro' => 'Keeping an object\'s data private and exposing it only through methods '
                    . 'you control.',
                'content' => '<p>Encapsulation hides an object\'s internals behind its methods. This '
                    . 'topic covers access levels, getters and setters, and why hiding state '
                    . 'makes change safer.</p>',
            ],
        ],
    ],
    [
        'key' => 'cs-db',
        'shortname' => 'CS 204',
        'idnumber' => 'CS204',
        'category' => 'data-web',
        'fullname' => 'Databases and SQL',
        'summary' => 'Storing data in relations and getting it back out: the relational '
            . 'model, SQL, joins and normalisation.',
        'activities' => [
            [
                'name' => 'The Relational Model',
                'intro' => 'Relations, rows and columns, primary and foreign keys, and what a '
                    . 'schema fixes in advance.',
                'content' => '<p>The relational model stores data in tables of rows and columns. '
                    . 'This topic covers relations, primary and foreign keys, and the role of '
                    . 'the schema.</p>',
            ],
            [
                'name' => 'SQL Queries',
                'intro' => 'SELECT, FROM, WHERE, ORDER BY and GROUP BY: asking a relational '
                    . 'database a question.',
                'content' => '<p>SQL is how you ask a relational database for data. This topic '
                    . 'covers SELECT and FROM, filtering with WHERE, ordering, and grouping '
                    . 'with aggregates.</p>',
            ],
            [
                'name' => 'Joins',
                'intro' => 'Combining rows from two tables on a matching column, and how inner '
                    . 'differs from outer.',
                'content' => '<p>A join brings together rows from more than one table. This topic '
                    . 'covers the inner join, left and right outer joins, and the matching '
                    . 'column that drives them.</p>',
            ],
            [
                'name' => 'Normalisation',
                'intro' => 'First, second and third normal form, and the update anomalies that '
                    . 'redundancy causes.',
                'content' => '<p>Normalisation removes redundancy from a schema. This topic covers '
                    . 'first, second and third normal form, functional dependency, and the '
                    . 'anomalies each form prevents.</p>',
            ],
        ],
    ],
    [
        'key' => 'cs-os',
        'shortname' => 'CS 301',
        'idnumber' => 'CS301',
        'category' => 'systems',
        'fullname' => 'Operating Systems',
        'summary' => 'What the operating system does underneath a running program: '
            . 'processes, threads and memory.',
        'activities' => [
            [
                'name' => 'Processes and Scheduling',
                'intro' => 'What a process is, the states it moves through, and how the scheduler '
                    . 'decides who runs next.',
                'content' => '<p>A process is a program in execution. This topic covers the process '
                    . 'states, the context switch, and the scheduling policies that choose '
                    . 'what runs next.</p>',
            ],
            [
                'name' => 'Threads and Concurrency',
                'intro' => 'Several threads inside one program, the race conditions that follow, '
                    . 'and the locks that prevent them.',
                'content' => '<p>Threads let one process do more than one thing at a time. This '
                    . 'topic covers thread creation, race conditions, mutual exclusion and '
                    . 'locks.</p>',
            ],
            [
                'name' => 'Memory Management',
                'intro' => 'Virtual addresses, paging, and how the operating system gives each '
                    . 'program its own view of memory.',
                'content' => '<p>Memory management gives every process its own address space. This '
                    . 'topic covers virtual addresses, paging, page frames and '
                    . 'allocation.</p>',
            ],
        ],
    ],
    [
        'key' => 'cs-net',
        'shortname' => 'CS 302',
        'idnumber' => 'CS302',
        'category' => 'systems',
        'fullname' => 'Computer Networks',
        'summary' => 'How machines talk to each other: the layered model, the internet '
            . 'protocols, and the web\'s own protocol.',
        'activities' => [
            [
                'name' => 'The OSI Model',
                'intro' => 'The seven layers, what each one is responsible for, and how data is '
                    . 'wrapped on its way down.',
                'content' => '<p>The OSI model splits communication into seven layers. This topic '
                    . 'covers what each layer does and how a message is encapsulated as it '
                    . 'passes down the stack.</p>',
            ],
            [
                'name' => 'TCP and IP',
                'intro' => 'Addressing with IP, reliable delivery with TCP, and the handshake that '
                    . 'opens a connection.',
                'content' => '<p>IP moves packets between addresses; TCP makes that delivery '
                    . 'reliable. This topic covers addressing, the three-way handshake, and '
                    . 'the difference from UDP.</p>',
            ],
            [
                'name' => 'HTTP and DNS',
                'intro' => 'Turning a name into an address, then asking for a page: DNS resolution '
                    . 'and the HTTP request and response.',
                'content' => '<p>DNS turns a domain name into an address; HTTP is how a browser then '
                    . 'asks for the page. This topic covers resolution, request methods, '
                    . 'headers and status codes.</p>',
            ],
        ],
    ],
    [
        'key' => 'cs-web',
        'shortname' => 'CS 303',
        'idnumber' => 'CS303',
        'category' => 'data-web',
        'fullname' => 'Web Development',
        'summary' => 'Building for the browser: document structure, presentation, behaviour, '
            . 'and talking to a server.',
        'activities' => [
            [
                'name' => 'HTML Structure',
                'intro' => 'Elements, tags and attributes, and how a document is nested into the '
                    . 'hierarchy a browser renders.',
                'content' => '<p>HTML marks up the structure of a page. This topic covers elements '
                    . 'and tags, attributes, nesting, and the document tree the browser '
                    . 'builds from them.</p>',
            ],
            [
                'name' => 'CSS Styling',
                'intro' => 'Selectors and declarations, the cascade, the box model, and laying a '
                    . 'page out with flexbox.',
                'content' => '<p>CSS decides how a page looks. This topic covers selectors and '
                    . 'properties, the cascade and specificity, the box model, and flexbox '
                    . 'layout.</p>',
            ],
            [
                'name' => 'JavaScript Basics',
                'intro' => 'Variables, functions and events, and changing the page from script '
                    . 'through the DOM.',
                'content' => '<p>JavaScript makes a page behave. This topic covers variables and '
                    . 'functions, responding to events, and changing the document through the '
                    . 'DOM.</p>',
            ],
            [
                'name' => 'REST APIs',
                'intro' => 'Resources and endpoints, the HTTP methods that act on them, and JSON '
                    . 'as the payload.',
                'content' => '<p>A REST API exposes resources over HTTP. This topic covers '
                    . 'endpoints, the methods that act on a resource, status codes, and JSON '
                    . 'request and response bodies.</p>',
            ],
        ],
    ],
    [
        'key' => 'cs-swe',
        'shortname' => 'CS 304',
        'idnumber' => 'CS304',
        'category' => 'software-engineering',
        'fullname' => 'Software Engineering Practice',
        'summary' => 'Working on software with other people: tracking change, proving it '
            . 'works, and reusing known designs.',
        'activities' => [
            [
                'name' => 'Version Control with Git',
                'intro' => 'Commits as a history you can move through, branching, merging, and '
                    . 'working from a shared repository.',
                'content' => '<p>Version control records how code changed and who changed it. This '
                    . 'topic covers the commit, branching and merging, and working against a '
                    . 'shared remote.</p>',
            ],
            [
                'name' => 'Software Testing',
                'intro' => 'Unit tests and assertions, what coverage does and does not tell you, '
                    . 'and catching regressions.',
                'content' => '<p>Testing checks that code does what it should, repeatedly. This '
                    . 'topic covers unit tests and assertions, test fixtures, coverage, and '
                    . 'regression testing.</p>',
            ],
            [
                'name' => 'Design Patterns',
                'intro' => 'Named solutions to problems that recur: singleton, factory, observer '
                    . 'and strategy.',
                'content' => '<p>A design pattern is a reusable answer to a problem that keeps '
                    . 'coming back. This topic covers the singleton, factory, observer and '
                    . 'strategy patterns.</p>',
            ],
        ],
    ],
    [
        'key' => 'cs-py',
        'shortname' => 'CS 102',
        'idnumber' => 'CS102',
        'category' => 'fundamentals',
        'fullname' => 'Python Programming',
        'summary' => 'Python from the ground up: the basics, its built-in collections, and '
            . 'writing your own functions.',
        'activities' => [
            [
                'name' => 'Python Basics',
                'intro' => 'Variables, indentation as syntax, printing, and running a script '
                    . 'through the interpreter.',
                'content' => '<p>Python runs from an interpreter and uses indentation as syntax. '
                    . 'This topic covers variables, the basic types, printing, and running a '
                    . 'script.</p>',
            ],
            [
                'name' => 'Lists and Dictionaries',
                'intro' => 'Ordered lists and key-value dictionaries, indexing, appending and '
                    . 'looking a value up by key.',
                'content' => '<p>Lists hold values in order; dictionaries map keys to values. This '
                    . 'topic covers indexing, appending, lookup by key, and when each '
                    . 'collection fits.</p>',
            ],
            [
                'name' => 'Python Functions',
                'intro' => 'Defining a function with def, parameters and return values, and '
                    . 'default and keyword arguments.',
                'content' => '<p>A function groups work under a name. This topic covers def, '
                    . 'parameters and return values, default arguments, and keyword '
                    . 'arguments.</p>',
            ],
        ],
    ],
];

// The installer leaves one category called "Category 1" and every course would
// otherwise land in it. Parent is a key from this same list, or null for the top.
const SEED_CATEGORIES = [
    [
        'key' => 'computing', 'parent' => null,
        'name' => 'School of Computing and Informatics',
        'description' => 'Undergraduate computing courses.',
    ],
    [
        'key' => 'fundamentals', 'parent' => 'computing',
        'name' => 'Programming Fundamentals',
        'description' => 'First courses in a programming language.',
    ],
    [
        'key' => 'core', 'parent' => 'computing',
        'name' => 'Core Computer Science',
        'description' => 'Data structures, algorithms and program design.',
    ],
    [
        'key' => 'data-web', 'parent' => 'computing',
        'name' => 'Data and Web Systems',
        'description' => 'Storing data and building for the browser.',
    ],
    [
        'key' => 'systems', 'parent' => 'computing',
        'name' => 'Systems and Networks',
        'description' => 'What runs underneath a program, and how machines talk.',
    ],
    [
        'key' => 'software-engineering', 'parent' => 'computing',
        'name' => 'Software Engineering',
        'description' => 'Working on software with other people.',
    ],
];

const SEED_TEACHER = ['username' => 'demo.teacher', 'firstname' => 'Miriam', 'lastname' => 'Wafula'];

const SEED_ADMIN = ['username' => 'demo.admin', 'firstname' => 'Lydia', 'lastname' => 'Muthoni'];

const SEED_USERS = [
    ['username' => 'student.visual', 'firstname' => 'Amara', 'lastname' => 'Otieno', 'style' => 'visual'],
    ['username' => 'student.aural', 'firstname' => 'Brian', 'lastname' => 'Kamau', 'style' => 'auditory'],
    ['username' => 'student.rw', 'firstname' => 'Chloe', 'lastname' => 'Wanjiru', 'style' => 'read_write'],
    ['username' => 'student.kines', 'firstname' => 'David', 'lastname' => 'Mwangi', 'style' => 'kinesthetic'],
    // Several with no style, so the questionnaire can be demonstrated more than
    // once, or handed to someone in the room to try.
    ['username' => 'student.blank', 'firstname' => 'Esther', 'lastname' => 'Achieng', 'style' => null],
    ['username' => 'student.blank2', 'firstname' => 'Felix', 'lastname' => 'Njoroge', 'style' => null],
    ['username' => 'student.blank3', 'firstname' => 'Grace', 'lastname' => 'Wambui', 'style' => null],
    ['username' => 'student.blank4', 'firstname' => 'Hassan', 'lastname' => 'Ali', 'style' => null],
    ['username' => 'student.blank5', 'firstname' => 'Irene', 'lastname' => 'Chebet', 'style' => null],
    ['username' => 'student.blank6', 'firstname' => 'Joseph', 'lastname' => 'Kiprono', 'style' => null],
];

// Re-runnable. Courses and activities are brought into line with this file on
// every run, because an activity's intro is what the recommender routes on: if
// the file says "return values" and the database still says "recursion", the
// activity quietly starts recommending the Algorithms topic. Users, passwords
// and saved learning styles are only ever created, never overwritten, because
// rewriting those mid-demo locks someone out or resets a run in progress.
// Nothing is ever deleted.

/**
 * Stable key per activity. Built from the course's 'key', never its shortname,
 * which is a course code now and could be renumbered.
 */
function seed_idnumber(string $coursekey, string $activityname): string {
    return 'tutoragent:' . $coursekey . ':'
        . preg_replace('/[^a-z0-9]+/', '-', strtolower($activityname));
}

/**
 * The category tree, created once and then found by idnumber. The top level
 * adopts the installer's placeholder rather than leaving an empty "Category 1"
 * next to the real one - but only while it is still untouched.
 *
 * @return array key => category id
 */
function seed_categories(array &$tally): array {
    global $DB;

    $ids = [];
    foreach (SEED_CATEGORIES as $seedcat) {
        $idnumber = 'tutoragent:cat:' . $seedcat['key'];
        // Cast: ids come back from the database as strings, and comparing one
        // strictly against (int) $category->parent marked every child changed on
        // every run.
        $parentid = $seedcat['parent'] === null ? 0 : (int) $ids[$seedcat['parent']];

        $record = $DB->get_record('course_categories', ['idnumber' => $idnumber], '*', IGNORE_MISSING);

        if (!$record && $seedcat['parent'] === null) {
            $record = $DB->get_record('course_categories',
                ['name' => 'Category 1', 'idnumber' => null], '*', IGNORE_MISSING)
                ?: $DB->get_record('course_categories',
                    ['name' => 'Category 1', 'idnumber' => ''], '*', IGNORE_MISSING);
            if ($record) {
                $tally['categories adopted']++;
            }
        }

        if (!$record) {
            $category = core_course_category::create([
                'name' => $seedcat['name'],
                'idnumber' => $idnumber,
                'parent' => $parentid,
                'description' => $seedcat['description'],
                'descriptionformat' => FORMAT_HTML,
            ]);
            $ids[$seedcat['key']] = (int) $category->id;
            $tally['categories created']++;
            continue;
        }

        $category = core_course_category::get($record->id, MUST_EXIST, true);
        if ($category->name !== $seedcat['name']
                || (string) $category->idnumber !== $idnumber
                || (int) $category->parent !== $parentid) {
            $category->update([
                'name' => $seedcat['name'],
                'idnumber' => $idnumber,
                'parent' => $parentid,
                'description' => $seedcat['description'],
                'descriptionformat' => FORMAT_HTML,
            ]);
            $tally['categories updated']++;
        } else {
            $tally['categories unchanged']++;
        }
        $ids[$seedcat['key']] = (int) $category->id;
    }

    return $ids;
}

/**
 * The teaching term. Courses created with no dates show 1 January 1970 on the
 * course listing, which is the first thing anyone notices.
 *
 * @return array [start, end]
 */
function seed_term(): array {
    $year = (int) date('Y');
    $september = make_timestamp($year, 9, 1);
    $start = time() >= $september ? $september : make_timestamp($year, 1, 1);

    return [$start, $start + (17 * WEEKSECS)];
}

list($options) = cli_get_params(['reset-blank' => false, 'help' => false], ['h' => 'help']);

if ($options['help']) {
    cli_writeln("Seed the demo courses, activities, admin and students.\n"
        . "Safe to run repeatedly: missing things are created, changed courses and\n"
        . "activities are updated, and users and learning styles are left alone.\n\n"
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

/**
 * By course code first, then by shortname, then by the shortname the course had
 * before it was given a code. Without that last step this run would leave the
 * ten original courses in place and create ten more beside them.
 */
function seed_find_course(array $seedcourse): ?stdClass {
    global $DB;

    foreach ([
        ['idnumber' => $seedcourse['idnumber']],
        ['shortname' => $seedcourse['shortname']],
        ['shortname' => strtoupper($seedcourse['key'])],
    ] as $conditions) {
        $course = $DB->get_record('course', $conditions, '*', IGNORE_MULTIPLE);
        if ($course) {
            return $course;
        }
    }

    return null;
}

/** Create the course, or bring it into line with this file. */
function seed_course(array $seedcourse, int $categoryid, array &$tally): stdClass {
    list($start, $end) = seed_term();

    $course = seed_find_course($seedcourse);

    if (!$course) {
        $tally['courses created']++;
        return create_course((object) [
            'fullname' => $seedcourse['fullname'],
            'shortname' => $seedcourse['shortname'],
            'idnumber' => $seedcourse['idnumber'],
            'category' => $categoryid,
            'summary' => $seedcourse['summary'],
            'summaryformat' => FORMAT_HTML,
            'format' => 'topics',
            'numsections' => count($seedcourse['activities']),
            'startdate' => $start,
            'enddate' => $end,
            'visible' => 1,
        ]);
    }

    $wanted = [
        'fullname' => $seedcourse['fullname'],
        'shortname' => $seedcourse['shortname'],
        'idnumber' => $seedcourse['idnumber'],
        'category' => $categoryid,
        'summary' => $seedcourse['summary'],
    ];

    $changed = false;
    foreach ($wanted as $field => $value) {
        if ((string) $course->$field !== (string) $value) {
            $course->$field = $value;
            $changed = true;
        }
    }

    // Repaired only when it was never set. Courses created before this existed
    // sit at the epoch; a course someone has dated deliberately is left alone.
    if (empty($course->startdate)) {
        $course->startdate = $start;
        $course->enddate = $end;
        $changed = true;
    }

    if ($changed) {
        update_course($course);
        $tally['courses updated']++;
    } else {
        $tally['courses unchanged']++;
    }

    return $course;
}

/**
 * Find this activity however it can. By idnumber first; failing that by name,
 * which adopts activities seeded before idnumbers were written and stops this
 * run creating a duplicate beside each one.
 */
function seed_find_activity(stdClass $course, array $activity, int $pagemoduleid,
                            string $idnumber, array &$tally): ?stdClass {
    global $DB;

    $cm = $DB->get_record('course_modules',
        ['course' => $course->id, 'idnumber' => $idnumber], '*', IGNORE_MISSING);
    if ($cm) {
        return $cm;
    }

    $cm = $DB->get_record_sql(
        "SELECT cm.*
           FROM {course_modules} cm
           JOIN {page} p ON p.id = cm.instance
          WHERE cm.course = :course AND cm.module = :module AND p.name = :name",
        ['course' => $course->id, 'module' => $pagemoduleid, 'name' => $activity['name']],
        IGNORE_MULTIPLE
    );
    if ($cm) {
        $DB->set_field('course_modules', 'idnumber', $idnumber, ['id' => $cm->id]);
        $tally['activities adopted']++;
    }

    return $cm ?: null;
}

/** Create the activity, or rewrite the fields the recommender reads. */
function seed_activity(stdClass $course, string $coursekey, array $activity, int $index,
                       int $pagemoduleid, array &$tally): void {
    global $DB;

    $idnumber = seed_idnumber($coursekey, $activity['name']);
    $cm = seed_find_activity($course, $activity, $pagemoduleid, $idnumber, $tally);

    if (!$cm) {
        // add_moduleinfo() wants more than its signature suggests: the generic
        // course_modules fields plus whatever the module's add_instance() reads.
        add_moduleinfo((object) [
            'modulename' => 'page',
            'module' => $pagemoduleid,
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
            'cmidnumber' => $idnumber,
            'groupmode' => 0,
            'groupingid' => 0,
            'completion' => 0,
            'completionview' => 0,
            'completionexpected' => 0,
        ], $course);

        // create_course() makes the sections but leaves them all called "New
        // section", which is what the course index then shows.
        $section = $DB->get_record('course_sections',
            ['course' => $course->id, 'section' => $index + 1], '*', IGNORE_MISSING);
        if ($section && $section->name !== $activity['name']) {
            course_update_section($course, $section, ['name' => $activity['name']]);
        }

        $tally['activities created']++;
        return;
    }

    $page = $DB->get_record('page', ['id' => $cm->instance], '*', IGNORE_MISSING);
    if (!$page) {
        $tally['activities skipped']++;
        return;
    }

    if ($page->name === $activity['name']
            && $page->intro === $activity['intro']
            && $page->content === $activity['content']) {
        $tally['activities unchanged']++;
        return;
    }

    $page->name = $activity['name'];
    $page->intro = $activity['intro'];
    $page->introformat = FORMAT_HTML;
    $page->content = $activity['content'];
    $page->contentformat = FORMAT_HTML;
    $page->timemodified = time();
    $DB->update_record('page', $page);
    rebuild_course_cache($course->id, true);

    $tally['activities updated']++;
}

/** Names may be corrected; passwords and learning styles never are. */
function seed_rename(int $userid, array $person, array &$tally): void {
    global $DB;

    $user = $DB->get_record('user', ['id' => $userid], 'id, firstname, lastname', MUST_EXIST);
    if ($user->firstname === $person['firstname'] && $user->lastname === $person['lastname']) {
        $tally['users left alone']++;
        return;
    }

    $user->firstname = $person['firstname'];
    $user->lastname = $person['lastname'];
    user_update_user($user, false, false);
    $tally['users renamed']++;
}

// Now that the courses have a start date and a teacher to send as, Moodle tries
// to email a welcome on every enrolment. There is no mail server in the
// container, so each one fails and writes a stack trace into the startup log,
// where it would bury anything that actually matters.
if (get_config('enrol_manual', 'sendcoursewelcomemessage') != ENROL_DO_NOT_SEND_EMAIL) {
    set_config('sendcoursewelcomemessage', ENROL_DO_NOT_SEND_EMAIL, 'enrol_manual');
}

/**
 * enrol_try_internal_enrol() rewrites the enrolment even when it already exists,
 * which re-fires the welcome-message hook on every start. Check first.
 */
function seed_enrol(stdClass $course, int $userid, int $roleid, array &$tally): void {
    $context = context_course::instance($course->id);

    if (is_enrolled($context, $userid)
            && user_has_role_assignment($userid, $roleid, $context->id)) {
        return;
    }

    enrol_try_internal_enrol($course->id, $userid, $roleid);
    $tally['enrolments added']++;
}

$studentrole = $DB->get_record('role', ['shortname' => 'student'], '*', MUST_EXIST);
$teacherrole = $DB->get_record('role', ['shortname' => 'editingteacher'], '*', MUST_EXIST);
$pagemoduleid = $DB->get_field('modules', 'id', ['name' => 'page'], MUST_EXIST);

$tally = array_fill_keys([
    'categories created', 'categories updated', 'categories unchanged', 'categories adopted',
    'courses created', 'courses updated', 'courses unchanged',
    'activities created', 'activities updated', 'activities unchanged',
    'activities adopted', 'activities skipped',
    'users created', 'users renamed', 'users left alone',
    'enrolments added', 'styles set',
], 0);

$categoryids = seed_categories($tally);

$courses = [];

foreach (SEED_COURSES as $seedcourse) {
    $course = seed_course($seedcourse, $categoryids[$seedcourse['category']], $tally);
    $courses[] = $course;

    foreach ($seedcourse['activities'] as $index => $activity) {
        seed_activity($course, $seedcourse['key'], $activity, $index, $pagemoduleid, $tally);
    }
}

cli_writeln('Courses and activities are in step with seed_demo.php.');

// An existing account is never touched: its password may have been changed and
// its learning style may be mid-demo.
$adminid = $DB->get_field('user', 'id', ['username' => SEED_ADMIN['username']]);
if (!$adminid) {
    $adminid = seed_create_user(SEED_ADMIN, $password);
    // Site admins are a config list, not a role assignment.
    $siteadmins = array_filter(explode(',', (string) $CFG->siteadmins));
    $siteadmins[] = $adminid;
    set_config('siteadmins', implode(',', array_unique($siteadmins)));
    $tally['users created']++;
} else {
    seed_rename($adminid, SEED_ADMIN, $tally);
}

// A course with no teacher is the other thing that gives a seeded site away.
$teacherid = $DB->get_field('user', 'id', ['username' => SEED_TEACHER['username']]);
if (!$teacherid) {
    $teacherid = seed_create_user(SEED_TEACHER, $password);
    $tally['users created']++;
} else {
    seed_rename($teacherid, SEED_TEACHER, $tally);
}
foreach ($courses as $course) {
    seed_enrol($course, $teacherid, $teacherrole->id, $tally);
}

foreach (SEED_USERS as $seeduser) {
    $userid = $DB->get_field('user', 'id', ['username' => $seeduser['username']]);
    $isnew = !$userid;

    if ($isnew) {
        $userid = seed_create_user($seeduser, $password);
        $tally['users created']++;
    } else {
        seed_rename($userid, $seeduser, $tally);
    }

    foreach ($courses as $course) {
        seed_enrol($course, $userid, $studentrole->id, $tally);
    }

    // Only ever set on a new account. Overwriting would undo `make rehearse`,
    // or reset someone who is halfway through the questionnaire.
    if ($isnew && $seeduser['style'] !== null) {
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
        $tally['styles set']++;
    }
}

purge_all_caches();

cli_writeln('');
foreach ($tally as $what => $count) {
    if ($count > 0) {
        cli_writeln(sprintf('  %-22s %d', $what, $count));
    }
}
cli_writeln('');
cli_writeln(count(SEED_CATEGORIES) . ' categories, ' . count($courses) . ' courses, '
    . array_sum(array_map(fn($c) => count($c['activities']), SEED_COURSES))
    . ' activities, 1 teacher, ' . count(SEED_USERS) . ' students.');
cli_writeln('Done. Run `make demo` for the logins and the demo script.');
