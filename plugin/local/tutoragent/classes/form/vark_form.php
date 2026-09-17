<?php
// The VARK questionnaire form for local_tutoragent.

namespace local_tutoragent\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

use html_writer;
use moodleform;

/**
 * Sixteen questions, four options each, one per VARK dimension. More than one
 * option may be selected per question: the VARK instrument allows it and the
 * scoring simply counts selections.
 *
 * ---------------------------------------------------------------------------
 * THE QUESTIONS BELOW ARE SAMPLES, NOT THE INSTRUMENT FROM THE SOURCE THESIS.
 *
 * Replace the QUESTIONS array with the thesis questions when you have them.
 * Nothing else needs to change: the form, the scoring and the results page all
 * read this one array. Keep the shape - 'dim' must stay one of V, A, R, K -
 * and set SAMPLE_QUESTIONS to false once they are the real ones, which removes
 * the notice shown at the top of the page.
 *
 * Note also that the published VARK questionnaire is copyrighted by VARK Learn
 * Ltd. Check what your thesis is permitted to reproduce before committing it.
 * ---------------------------------------------------------------------------
 */
class vark_form extends moodleform {

    /** @var bool Set false once QUESTIONS holds the thesis questions. */
    const SAMPLE_QUESTIONS = true;

    const QUESTIONS = [
        ['text' => 'A new topic in C has just been introduced. What helps you most first?', 'options' => [
            ['dim' => 'V', 'text' => 'A diagram showing how the pieces fit together'],
            ['dim' => 'R', 'text' => 'A written summary you can read at your own pace'],
            ['dim' => 'K', 'text' => 'A worked example you can run and change'],
            ['dim' => 'A', 'text' => 'Hearing the lecturer talk it through'],
        ]],
        ['text' => 'You are stuck on an error in your code. What do you do?', 'options' => [
            ['dim' => 'K', 'text' => 'Change something and run it again to see what happens'],
            ['dim' => 'R', 'text' => 'Read the error message and the documentation carefully'],
            ['dim' => 'A', 'text' => 'Ask a classmate to explain it out loud'],
            ['dim' => 'V', 'text' => 'Look for a diagram or flowchart of what should happen'],
        ]],
        ['text' => 'You need to remember how a loop works. What makes it stick?', 'options' => [
            ['dim' => 'V', 'text' => 'A picture of the flow'],
            ['dim' => 'K', 'text' => 'Typing it out and watching it run'],
            ['dim' => 'A', 'text' => 'Saying the steps to yourself'],
            ['dim' => 'R', 'text' => 'Writing the steps down in order'],
        ]],
        ['text' => 'Your lecturer offers extra material on a topic. Which do you pick?', 'options' => [
            ['dim' => 'A', 'text' => 'A recorded audio explanation'],
            ['dim' => 'V', 'text' => 'A short video with animations'],
            ['dim' => 'R', 'text' => 'A PDF handout'],
            ['dim' => 'K', 'text' => 'An exercise sheet with tasks to attempt'],
        ]],
        ['text' => 'When revising for an exam, you mostly:', 'options' => [
            ['dim' => 'R', 'text' => 'Re-read your notes'],
            ['dim' => 'K', 'text' => 'Redo past practical questions'],
            ['dim' => 'V', 'text' => 'Draw summary diagrams'],
            ['dim' => 'A', 'text' => 'Talk through the material with someone'],
        ]],
        ['text' => 'Someone asks you to explain what an array is. You:', 'options' => [
            ['dim' => 'V', 'text' => 'Draw the boxes and the indices'],
            ['dim' => 'R', 'text' => 'Write out a definition and an example'],
            ['dim' => 'A', 'text' => 'Explain it in conversation'],
            ['dim' => 'K', 'text' => 'Open an editor and build one with them'],
        ]],
        ['text' => 'You have new software to learn. You:', 'options' => [
            ['dim' => 'K', 'text' => 'Click around and try things'],
            ['dim' => 'R', 'text' => 'Read the manual first'],
            ['dim' => 'V', 'text' => 'Watch a screen-recorded walkthrough'],
            ['dim' => 'A', 'text' => 'Ask someone to talk you through it'],
        ]],
        ['text' => 'What makes a lecture useful to you?', 'options' => [
            ['dim' => 'A', 'text' => 'The explanations and the discussion'],
            ['dim' => 'V', 'text' => 'The slides and diagrams'],
            ['dim' => 'R', 'text' => 'The handouts and notes'],
            ['dim' => 'K', 'text' => 'The in-class exercises'],
        ]],
        ['text' => 'Someone gives you directions to a building you do not know. You prefer:', 'options' => [
            ['dim' => 'V', 'text' => 'A map'],
            ['dim' => 'R', 'text' => 'Written directions'],
            ['dim' => 'A', 'text' => 'Someone telling you the way'],
            ['dim' => 'K', 'text' => 'Walking it once with someone'],
        ]],
        ['text' => 'Choosing a textbook, you look for:', 'options' => [
            ['dim' => 'V', 'text' => 'Plenty of figures and charts'],
            ['dim' => 'R', 'text' => 'Clear, well-written prose'],
            ['dim' => 'K', 'text' => 'Exercises and worked problems'],
            ['dim' => 'A', 'text' => 'An accompanying lecture or audio series'],
        ]],
        ['text' => 'After class, what do you do with new material?', 'options' => [
            ['dim' => 'R', 'text' => 'Rewrite your notes neatly'],
            ['dim' => 'A', 'text' => 'Discuss it with a friend'],
            ['dim' => 'K', 'text' => 'Try the examples yourself'],
            ['dim' => 'V', 'text' => 'Turn it into a diagram or mind map'],
        ]],
        ['text' => 'You learn a new C function best by:', 'options' => [
            ['dim' => 'R', 'text' => 'Reading its description and parameters'],
            ['dim' => 'K', 'text' => 'Calling it in a small test program'],
            ['dim' => 'V', 'text' => 'Seeing a diagram of what it does'],
            ['dim' => 'A', 'text' => 'Listening to an explanation of when to use it'],
        ]],
        ['text' => 'Which feedback on your work helps most?', 'options' => [
            ['dim' => 'R', 'text' => 'Written comments'],
            ['dim' => 'A', 'text' => 'A spoken conversation about it'],
            ['dim' => 'V', 'text' => 'Annotated screenshots or marked-up diagrams'],
            ['dim' => 'K', 'text' => 'A corrected version to work through yourself'],
        ]],
        ['text' => 'A concept has not clicked yet. You:', 'options' => [
            ['dim' => 'A', 'text' => 'Listen to another explanation'],
            ['dim' => 'V', 'text' => 'Look for a visualisation'],
            ['dim' => 'R', 'text' => 'Find a different written account'],
            ['dim' => 'K', 'text' => 'Work through more examples'],
        ]],
        ['text' => 'Which describes your notes?', 'options' => [
            ['dim' => 'V', 'text' => 'Lots of arrows, boxes and colour'],
            ['dim' => 'R', 'text' => 'Full sentences and headings'],
            ['dim' => 'K', 'text' => 'Snippets of code to try later'],
            ['dim' => 'A', 'text' => 'Sparse: you rely on remembering what was said'],
        ]],
        ['text' => 'Given one hour to prepare for a practical test, you:', 'options' => [
            ['dim' => 'K', 'text' => 'Practise the tasks'],
            ['dim' => 'R', 'text' => 'Read through the instructions'],
            ['dim' => 'V', 'text' => 'Study the diagrams in the manual'],
            ['dim' => 'A', 'text' => 'Have someone quiz you aloud'],
        ]],
    ];

    protected function definition() {
        $mform = $this->_form;

        foreach (self::QUESTIONS as $index => $question) {
            $mform->addElement(
                'static',
                "question{$index}",
                '',
                html_writer::tag('h5', ($index + 1) . '. ' . $question['text'], ['class' => 'mt-3'])
            );

            foreach ($question['options'] as $position => $option) {
                $mform->addElement(
                    'advcheckbox',
                    "q{$index}_{$position}",
                    '',
                    $option['text']
                );
                $mform->setType("q{$index}_{$position}", PARAM_BOOL);
            }
        }

        $this->add_action_buttons(false, get_string('varksubmit', 'local_tutoragent'));
    }

    /**
     * Answers are optional per question, but an entirely empty form would score
     * every dimension zero and hand back "Visual" by tie-break, which would be
     * a lie rather than a result.
     */
    public function validation($data, $files) {
        foreach ($data as $key => $value) {
            if (str_starts_with($key, 'q') && $value) {
                return [];
            }
        }

        return ['question0' => get_string('varkanswernone', 'local_tutoragent')];
    }

    /**
     * Turn submitted form data into the per-question dimension lists that
     * local_tutoragent_score() expects.
     */
    public static function answers_from(\stdClass $data): array {
        $answers = [];

        foreach (self::QUESTIONS as $index => $question) {
            $selected = [];
            foreach ($question['options'] as $position => $option) {
                $field = "q{$index}_{$position}";
                if (!empty($data->$field)) {
                    $selected[] = $option['dim'];
                }
            }
            $answers[] = $selected;
        }

        return $answers;
    }
}
