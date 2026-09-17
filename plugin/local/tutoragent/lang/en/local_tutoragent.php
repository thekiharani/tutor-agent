<?php
defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Tutor agent';
$string['privacy:metadata:local_tutoragent_vark'] =
    'The learning style each user gets from the VARK questionnaire, used to pick which resources to recommend.';
$string['privacy:metadata:local_tutoragent_vark:userid'] = 'The user the learning style belongs to.';
$string['privacy:metadata:local_tutoragent_vark:style'] = 'The dominant learning style: visual, auditory, read_write or kinesthetic.';
$string['privacy:metadata:local_tutoragent_vark:scores'] = 'The score for each of the four dimensions, kept so the result can be explained.';
$string['privacy:metadata:local_tutoragent_vark:timemodified'] = 'When the questionnaire was last submitted.';
$string['recommenderurl'] = 'Recommender service URL';
$string['recommenderurl_desc'] =
    'Base URL of the recommender service, reached over the private container network. Leave this alone unless you have moved the service.';

$string['forceredirect'] = 'Require the questionnaire before anything else';
$string['forceredirect_desc'] =
    'Send students who have not answered the questionnaire straight to it, whatever page they ask for. Site administrators are never redirected. To turn this off without loading a page: php admin/cli/cfg.php --component=local_tutoragent --name=forceredirect --set=0';
$string['varkrequired'] =
    'Please answer these questions first. Your course opens as soon as you are done.';
$string['varkcontinue'] = 'Continue';

$string['mylearningstyle'] = 'My learning style';
$string['takequestionnaire'] = 'Find out your learning style: <a href="{$a}">take the short VARK questionnaire</a>.';

$string['style_visual'] = 'Visual';
$string['style_auditory'] = 'Aural';
$string['style_read_write'] = 'Read/Write';
$string['style_kinesthetic'] = 'Kinesthetic';
$string['style_visual_desc'] = 'You learn best from pictures, diagrams, charts and video.';
$string['style_auditory_desc'] = 'You learn best from listening: lectures, discussion and audio explanations.';
$string['style_read_write_desc'] = 'You learn best from text: articles, notes, lists and written examples.';
$string['style_kinesthetic_desc'] = 'You learn best from doing: worked examples, demonstrations and practice.';

$string['varkintro'] =
    'Answer these sixteen questions to find out how you prefer to learn. Pick as many options per question as apply, or none. It takes about two minutes, and you can retake it at any time.';
$string['varksubmit'] = 'See my learning style';
$string['varksaved'] = 'Your learning style has been saved. Open an activity in your course to see a resource picked for it.';
$string['varkretake'] = 'Take the questionnaire again';
$string['varkbreakdown'] = 'Your scores';
$string['varktiebreak'] = 'Tied on {$a}; resolved in the order Visual, Aural, Read/Write, Kinesthetic.';
$string['varkbacktocourses'] = 'Back to my courses';
$string['varkanswernone'] = 'Please answer at least one question.';
$string['varkguest'] = 'Please log in to take the questionnaire.';
$string['varkattribution'] =
    'Questionnaire: VARK, copyright VARK Learn Limited, reproduced from Appendix A of the project report.';
$string['varksamplequestions'] =
    'These are sample questions, not the questionnaire from the source thesis. Replace the QUESTIONS array in classes/form/vark_form.php, then set SAMPLE_QUESTIONS to false.';
