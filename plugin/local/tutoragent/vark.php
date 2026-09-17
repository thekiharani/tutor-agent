<?php
// Take the questionnaire, or see the style you already have.

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

use local_tutoragent\form\vark_form;

require_login();

if (isguestuser()) {
    redirect(new moodle_url('/'), get_string('varkguest', 'local_tutoragent'), null,
        \core\output\notification::NOTIFY_WARNING);
}

$retake = optional_param('retake', 0, PARAM_BOOL);
$url = new moodle_url('/local/tutoragent/vark.php');

$PAGE->set_url($url);
$PAGE->set_context(context_user::instance($USER->id));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('mylearningstyle', 'local_tutoragent'));
$PAGE->set_heading(get_string('mylearningstyle', 'local_tutoragent'));

$existing = local_tutoragent_get_style($USER->id);
$form = new vark_form($url);
$justsubmitted = false;

if ($data = $form->get_data()) {
    $result = local_tutoragent_score(vark_form::answers_from($data));
    local_tutoragent_save_style($USER->id, $result['style'], $result['scores']);
    $existing = local_tutoragent_get_style($USER->id);
    $justsubmitted = true;
}

echo $OUTPUT->header();

if ($existing && !$retake) {
    $style = $existing->style;
    echo $OUTPUT->heading(get_string('style_' . $style, 'local_tutoragent'), 3);
    echo html_writer::tag('p', get_string('style_' . $style . '_desc', 'local_tutoragent'));

    if ($justsubmitted) {
        echo $OUTPUT->notification(get_string('varksaved', 'local_tutoragent'),
            \core\output\notification::NOTIFY_SUCCESS);
    }

    // The breakdown, so the result is explainable rather than asserted.
    $scores = json_decode($existing->scores, true);
    if (!empty($scores['counts'])) {
        $rows = [];
        foreach ($scores['counts'] as $dimension => $count) {
            $rows[] = html_writer::tag('li', get_string(
                'style_' . LOCAL_TUTORAGENT_STYLES[$dimension], 'local_tutoragent') . ': ' . $count);
        }
        echo $OUTPUT->heading(get_string('varkbreakdown', 'local_tutoragent'), 4);
        echo html_writer::tag('ul', implode('', $rows));

        if (!empty($scores['tiebreak'])) {
            echo html_writer::tag('p', get_string('varktiebreak', 'local_tutoragent', $scores['tiebreak']),
                ['class' => 'text-muted']);
        }
    }

    echo $OUTPUT->single_button(new moodle_url($url, ['retake' => 1]),
        get_string('varkretake', 'local_tutoragent'), 'get');
    echo html_writer::tag('p', html_writer::link(new moodle_url('/my/courses.php'),
        get_string('varkbacktocourses', 'local_tutoragent')), ['class' => 'mt-3']);
} else {
    if (vark_form::SAMPLE_QUESTIONS) {
        echo $OUTPUT->notification(get_string('varksamplequestions', 'local_tutoragent'),
            \core\output\notification::NOTIFY_WARNING);
    }

    echo html_writer::tag('p', get_string('varkintro', 'local_tutoragent'));
    $form->display();
    echo html_writer::tag('p', get_string('varkattribution', 'local_tutoragent'),
        ['class' => 'text-muted small mt-4']);
}

echo $OUTPUT->footer();
