<?php
// Shared functions for local_tutoragent: navigation, VARK scoring, and the
// call out to the recommender service.

defined('MOODLE_INTERNAL') || die();

/**
 * The four VARK dimensions, in the order used to break a tie, mapped to the
 * style strings the recommender expects.
 */
const LOCAL_TUTORAGENT_STYLES = [
    'V' => 'visual',
    'A' => 'auditory',
    'R' => 'read_write',
    'K' => 'kinesthetic',
];

/**
 * The stored learning style for a user, or null if they have not answered yet.
 *
 * @param int $userid
 * @return stdClass|null
 */
function local_tutoragent_get_style(int $userid): ?stdClass {
    global $DB;

    $record = $DB->get_record('local_tutoragent_vark', ['userid' => $userid], '*', IGNORE_MISSING);
    return $record ?: null;
}

/**
 * Score a completed questionnaire.
 *
 * Each answer is the list of dimensions the student selected for one question;
 * VARK allows more than one. The dominant style is simply the highest count,
 * and a tie is broken in the order V, A, R, K. The full breakdown is kept so
 * the result can be explained rather than asserted.
 *
 * @param array $answers list of arrays of 'V'|'A'|'R'|'K'
 * @return array ['style' => string, 'scores' => array]
 */
function local_tutoragent_score(array $answers): array {
    $counts = array_fill_keys(array_keys(LOCAL_TUTORAGENT_STYLES), 0);

    foreach ($answers as $selected) {
        foreach ((array) $selected as $dimension) {
            if (isset($counts[$dimension])) {
                $counts[$dimension]++;
            }
        }
    }

    $dominant = 'V';
    foreach ($counts as $dimension => $count) {
        if ($count > $counts[$dominant]) {
            $dominant = $dimension;
        }
    }

    $tied = array_keys($counts, $counts[$dominant], true);

    return [
        'style' => LOCAL_TUTORAGENT_STYLES[$dominant],
        'scores' => [
            'counts' => $counts,
            'dominant' => $dominant,
            'tiebreak' => count($tied) > 1 ? implode(',', $tied) : null,
        ],
    ];
}

/**
 * Store a scored questionnaire, replacing any previous answer.
 *
 * @param int $userid
 * @param string $style
 * @param array $scores
 */
function local_tutoragent_save_style(int $userid, string $style, array $scores) {
    global $DB;

    $record = $DB->get_record('local_tutoragent_vark', ['userid' => $userid], '*', IGNORE_MISSING);
    $now = time();

    if ($record) {
        $record->style = $style;
        $record->scores = json_encode($scores);
        $record->timemodified = $now;
        $DB->update_record('local_tutoragent_vark', $record);
        return;
    }

    $DB->insert_record('local_tutoragent_vark', (object) [
        'userid' => $userid,
        'style' => $style,
        'scores' => json_encode($scores),
        'timemodified' => $now,
    ]);
}

/**
 * Ask the recommender for a resource, and never let it break the page.
 *
 * Every failure path returns null: a wrong URL, a dead container, a timeout, a
 * 204 (the service has nothing for this activity), a non-JSON body. A page must
 * render normally and on time whatever the recommender is doing.
 *
 * Native curl_* rather than Moodle's \curl wrapper on purpose. The wrapper
 * enforces $CFG->curlsecurityblockedhosts, which blocks private IP ranges by
 * default, and the recommender lives on a Docker private address. Using the
 * wrapper would mean weakening a site-wide security setting to make one
 * internal call work.
 *
 * @param string $modulename
 * @param string $moduleintro
 * @param string $style
 * @return string|null HTML for the recommendation, or null for "say nothing"
 */
function local_tutoragent_request_recommendation(string $modulename, string $moduleintro, string $style): ?string {
    $base = get_config('local_tutoragent', 'recommenderurl');
    if (empty($base)) {
        return null;
    }

    try {
        $handle = curl_init(rtrim($base, '/') . '/recommend');
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'module_name' => $modulename,
                'module_intro' => $moduleintro,
                'style' => $style,
            ]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_CONNECTTIMEOUT => 1,
            CURLOPT_TIMEOUT => 2,
        ]);

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if ($body === false || $status !== 200) {
            return null;
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded) || empty($decoded['html']) || !is_string($decoded['html'])) {
            return null;
        }

        return $decoded['html'];
    } catch (Throwable $e) {
        return null;
    }
}
