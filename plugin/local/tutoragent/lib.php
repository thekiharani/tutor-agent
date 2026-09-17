<?php
// VARK scoring and the call out to the recommender service.

defined('MOODLE_INTERNAL') || die();

/** VARK dimensions in tie-break order, mapped to the recommender's style strings. */
const LOCAL_TUTORAGENT_STYLES = [
    'V' => 'visual',
    'A' => 'auditory',
    'R' => 'read_write',
    'K' => 'kinesthetic',
];

/** The stored learning style for a user, or null if they have not answered yet. */
function local_tutoragent_get_style(int $userid): ?stdClass {
    global $DB;

    $record = $DB->get_record('local_tutoragent_vark', ['userid' => $userid], '*', IGNORE_MISSING);
    return $record ?: null;
}

/**
 * Score a questionnaire. Highest count wins; ties break V, A, R, K.
 *
 * @param array $answers one array of 'V'|'A'|'R'|'K' per question
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

/** Store a scored questionnaire, replacing any previous answer. */
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
 * Ask the recommender for a resource. Every failure path returns null so a dead
 * or slow service costs the page nothing.
 *
 * Native curl_*, not Moodle's \curl wrapper: the wrapper enforces
 * $CFG->curlsecurityblockedhosts, which blocks the private IP the recommender
 * runs on.
 *
 * @return string|null HTML for the recommendation, or null to say nothing
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
