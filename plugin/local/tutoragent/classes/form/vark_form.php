<?php
// The VARK questionnaire form for local_tutoragent.

namespace local_tutoragent\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

use html_writer;
use moodleform;

/**
 * The sixteen VARK questions, four options each, one per dimension. More than
 * one option may be selected per question: the instrument allows it and the
 * scoring simply counts selections.
 *
 * The questions are reproduced from Appendix A of the source thesis, which
 * uses the VARK questionnaire. The option order varies from question to
 * question, which is how the instrument is written - do not sort them, or
 * every answer becomes the first checkbox.
 *
 * The VARK questionnaire is copyright VARK Learn Limited; it is used here for
 * the educational purpose the thesis describes and credited on the page.
 */
class vark_form extends moodleform {

    /** @var bool True while QUESTIONS holds placeholder text rather than the instrument. */
    const SAMPLE_QUESTIONS = false;

    const QUESTIONS = [
        ['text' => 'When learning from the Internet I like:', 'options' => [
            ['dim' => 'V', 'text' => 'Interesting design and visual features.'],
            ['dim' => 'K', 'text' => 'Videos showing how to do or make things.'],
            ['dim' => 'R', 'text' => 'Interesting written descriptions, lists and explanations.'],
            ['dim' => 'A', 'text' => 'Audio channels where I can listen to podcasts or interviews.'],
        ]],
        ['text' => 'A website has a video showing how to make a special graph or chart. There is a person speaking, some lists and words describing what to do and some diagrams. I would learn most from:', 'options' => [
            ['dim' => 'R', 'text' => 'Reading the words.'],
            ['dim' => 'V', 'text' => 'Seeing the diagrams.'],
            ['dim' => 'K', 'text' => 'Watching the actions.'],
            ['dim' => 'A', 'text' => 'Listening.'],
        ]],
        ['text' => 'I need to find the way to a shop that a friend has recommended. I would:', 'options' => [
            ['dim' => 'K', 'text' => 'Find out where the shop is in relation to somewhere I know.'],
            ['dim' => 'V', 'text' => 'Use a map.'],
            ['dim' => 'A', 'text' => 'Ask my friend to tell me the directions.'],
            ['dim' => 'R', 'text' => 'Write down the street directions I need to remember.'],
        ]],
        ['text' => 'I want to assemble a wooden table that came in parts (kitset). I would learn best from:', 'options' => [
            ['dim' => 'A', 'text' => 'Advice from someone who has done it before.'],
            ['dim' => 'K', 'text' => 'Watching a video of a person assembling a similar table.'],
            ['dim' => 'V', 'text' => 'Diagrams showing each stage of the assembly.'],
            ['dim' => 'R', 'text' => 'Written instructions that came with the parts for the table.'],
        ]],
        ['text' => 'I want to find out more about a tour that I am going on. I would:', 'options' => [
            ['dim' => 'A', 'text' => 'Talk with the person who planned the tour or others who are going on the tour.'],
            ['dim' => 'K', 'text' => 'Look at details about the highlights and activities on the tour.'],
            ['dim' => 'R', 'text' => 'Read about the tour on the itinerary.'],
            ['dim' => 'V', 'text' => 'Use a map and see where the places are.'],
        ]],
        ['text' => 'I prefer a presenter or a teacher who uses:', 'options' => [
            ['dim' => 'R', 'text' => 'Handouts, books, or readings.'],
            ['dim' => 'A', 'text' => 'Question and answer, talk, group discussion, or guest speakers.'],
            ['dim' => 'K', 'text' => 'Demonstrations, models or practical sessions.'],
            ['dim' => 'V', 'text' => 'Diagrams, charts, maps or graphs.'],
        ]],
        ['text' => 'I want to learn to do something new on a computer. I would:', 'options' => [
            ['dim' => 'V', 'text' => 'Follow the diagrams in a book.'],
            ['dim' => 'K', 'text' => 'Start using it and learn by trial and error.'],
            ['dim' => 'A', 'text' => 'Talk with people who know about the program.'],
            ['dim' => 'R', 'text' => 'Read the written instructions that came with the program.'],
        ]],
        ['text' => 'I have a problem with my heart. I would prefer that the doctor:', 'options' => [
            ['dim' => 'K', 'text' => 'Used a plastic model to show me what was wrong.'],
            ['dim' => 'V', 'text' => 'Showed me a diagram of what was wrong.'],
            ['dim' => 'A', 'text' => 'Described what was wrong.'],
            ['dim' => 'R', 'text' => 'Gave me something to read to explain what was wrong.'],
        ]],
        ['text' => 'I want to learn how to take better photos. I would:', 'options' => [
            ['dim' => 'R', 'text' => 'Use the written instructions about what to do.'],
            ['dim' => 'V', 'text' => 'Use diagrams showing the camera and what each part does.'],
            ['dim' => 'K', 'text' => 'Use examples of good and poor photos showing how to improve them.'],
            ['dim' => 'A', 'text' => 'Ask questions and talk about the camera and its features.'],
        ]],
        ['text' => 'I want to learn about a new project. I would ask for:', 'options' => [
            ['dim' => 'V', 'text' => 'Diagrams to show the project stages with charts of benefits and costs.'],
            ['dim' => 'A', 'text' => 'An opportunity to discuss the project.'],
            ['dim' => 'K', 'text' => 'Examples where the project has been used successfully.'],
            ['dim' => 'R', 'text' => 'A written report describing the main features of the project.'],
        ]],
        ['text' => 'I want to save more money and to decide between a range of options. I would:', 'options' => [
            ['dim' => 'A', 'text' => 'Talk with an expert about the options.'],
            ['dim' => 'R', 'text' => 'Read a print brochure that describes the options in detail.'],
            ['dim' => 'V', 'text' => 'Use graphs showing different options for different times.'],
            ['dim' => 'K', 'text' => 'Consider examples of each option using my financial information.'],
        ]],
        ['text' => 'When I am learning I:', 'options' => [
            ['dim' => 'K', 'text' => 'Use examples and applications.'],
            ['dim' => 'V', 'text' => 'See patterns in things.'],
            ['dim' => 'A', 'text' => 'Like to talk things through.'],
            ['dim' => 'R', 'text' => 'Read books, articles and handouts.'],
        ]],
        ['text' => 'I have finished a competition or test and I would like some feedback. I would like to have feedback:', 'options' => [
            ['dim' => 'A', 'text' => 'From somebody who talks it through with me.'],
            ['dim' => 'R', 'text' => 'Using a written description of my results.'],
            ['dim' => 'V', 'text' => 'Using graphs showing what I achieved.'],
            ['dim' => 'K', 'text' => 'Using examples from what I have done.'],
        ]],
        ['text' => 'I want to learn how to play a new board game or card game. I would:', 'options' => [
            ['dim' => 'K', 'text' => 'Watch others play the game before joining in.'],
            ['dim' => 'V', 'text' => 'Use the diagrams that explain the various stages, moves and strategies in the game.'],
            ['dim' => 'R', 'text' => 'Read the instructions.'],
            ['dim' => 'A', 'text' => 'Listen to somebody explaining it and ask questions.'],
        ]],
        ['text' => 'When choosing a career or area of study, these are important for me:', 'options' => [
            ['dim' => 'R', 'text' => 'Using words well in written communications.'],
            ['dim' => 'V', 'text' => 'Working with designs, maps or charts.'],
            ['dim' => 'K', 'text' => 'Applying my knowledge in real situations.'],
            ['dim' => 'A', 'text' => 'Communicating with others through discussion.'],
        ]],
        ['text' => 'I want to find out about a house or an apartment. Before visiting it, I would want:', 'options' => [
            ['dim' => 'R', 'text' => 'A printed description of the rooms and features.'],
            ['dim' => 'K', 'text' => 'To view a video of the property.'],
            ['dim' => 'A', 'text' => 'A discussion with the owner.'],
            ['dim' => 'V', 'text' => 'A plan showing the rooms and a map of the area.'],
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
