<?php
// This file is part of Moodle - http://moodle.org/

require_once('../../config.php');
require_once($CFG->dirroot . '/mod/testhive/lib.php');

use mod_testhive\local\api_client;

global $DB, $PAGE, $OUTPUT, $USER;

$id = required_param('id', PARAM_INT);
$launch = optional_param('launch', 0, PARAM_BOOL);
$sync = optional_param('sync', 0, PARAM_BOOL);

$cm = get_coursemodule_from_id('testhive', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$instance = $DB->get_record('testhive', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/testhive:view', $context);

$PAGE->set_url('/mod/testhive/view.php', ['id' => $cm->id]);
$PAGE->set_title(format_string($instance->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

$isteacher = has_capability('moodle/course:manageactivities', $context);
$canattempt = has_capability('mod/testhive:attempt', $context);
$now = time();

if ($sync && $isteacher) {
    require_sesskey();
    if (api_client::is_configured() && !empty($instance->testhive_test_id)) {
        try {
            testhive_update_grades($instance, 0);
            \core\notification::success(get_string('gradesynced', 'mod_testhive'));
        } catch (moodle_exception $e) {
            \core\notification::error($e->getMessage());
        }
    }
    redirect(new moodle_url('/mod/testhive/view.php', ['id' => $cm->id]));
}

if ($launch) {
    require_sesskey();
    require_capability('mod/testhive:attempt', $context);
    if (!api_client::is_configured()) {
        throw new moodle_exception('error_apinotconfigured', 'mod_testhive');
    }
    if (!empty($instance->timeopen) && $now < (int) $instance->timeopen) {
        throw new moodle_exception('notopen', 'mod_testhive', '', userdate($instance->timeopen));
    }
    if (!empty($instance->timeclose) && $now > (int) $instance->timeclose) {
        throw new moodle_exception('closed', 'mod_testhive', '', userdate($instance->timeclose));
    }
    redirect(testhive_get_launch_url($instance, $USER->id));
}

if (!$isteacher && $canattempt && api_client::is_configured() && !empty($instance->testhive_test_id)) {
    try {
        testhive_update_grades($instance, (int) $USER->id);
    } catch (moodle_exception $e) {
        debugging('mod_testhive: grade sync user ' . $USER->id . ': ' . $e->getMessage(), DEBUG_NORMAL);
    }
}

$questionset = $instance->questionset;
$status = '';
$resultrows = [];
if (api_client::is_configured()) {
    try {
        $match = api_client::find_questionset((string) $instance->questionset);
        if ($match) {
            $questionset = api_client::questionset_label($match);
        }
    } catch (moodle_exception $e) {
        $questionset = $instance->questionset;
    }
    if (!empty($instance->testhive_test_id)) {
        try {
            $response = api_client::request('GET', '/v1/tests/' . $instance->testhive_test_id);
            $status = (string) ($response['data']['status'] ?? '');
        } catch (moodle_exception $e) {
            $status = '';
        }
        if ($isteacher) {
            try {
                $resultrows = testhive_format_results_rows($instance);
            } catch (moodle_exception $e) {
                debugging('mod_testhive: results: ' . $e->getMessage(), DEBUG_NORMAL);
            }
        }
    }
}

$rows = [
    [
        'label' => get_string('questionset', 'mod_testhive'),
        'value' => $questionset,
        'html' => false,
    ],
    [
        'label' => get_string('timeopen', 'mod_testhive'),
        'value' => $instance->timeopen ? userdate($instance->timeopen) : get_string('notset', 'mod_testhive'),
        'html' => false,
    ],
    [
        'label' => get_string('timeclose', 'mod_testhive'),
        'value' => $instance->timeclose ? userdate($instance->timeclose) : get_string('notset', 'mod_testhive'),
        'html' => false,
    ],
    [
        'label' => get_string('grademax', 'grades'),
        'value' => format_float($instance->grade, 0),
        'html' => false,
    ],
];
if ($isteacher && $status !== '') {
    $rows[] = [
        'label' => get_string('status', 'mod_testhive'),
        'value' => html_writer::span(testhive_status_label($status), 'badge text-bg-secondary'),
        'html' => true,
    ];
}

$launchurl = '';
$notice = '';
$syncurl = '';
if (!$isteacher && $canattempt) {
    if (!api_client::is_configured()) {
        $notice = get_string('error_apinotconfigured', 'mod_testhive');
    } else if (!empty($instance->timeopen) && $now < (int) $instance->timeopen) {
        $notice = get_string('notopen', 'mod_testhive', userdate($instance->timeopen));
    } else if (!empty($instance->timeclose) && $now > (int) $instance->timeclose) {
        $notice = get_string('closed', 'mod_testhive', userdate($instance->timeclose));
    } else {
        $launchurl = (new moodle_url('/mod/testhive/view.php', ['id' => $cm->id]))->out(false);
    }
} else if ($isteacher) {
    $notice = empty($instance->testhive_test_id)
        ? get_string('noinvitees', 'mod_testhive')
        : get_string('teacher_intro', 'mod_testhive');
    if (!empty($instance->testhive_test_id) && api_client::is_configured()) {
        $syncurl = (new moodle_url('/mod/testhive/view.php', [
            'id' => $cm->id,
            'sync' => 1,
            'sesskey' => sesskey(),
        ]))->out(false);
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($instance->name));
echo $OUTPUT->render_from_template('mod_testhive/view', [
    'intro' => format_module_intro('testhive', $instance, $cm->id),
    'rows' => $rows,
    'launchurl' => $launchurl,
    'sesskey' => sesskey(),
    'startlabel' => get_string('start', 'mod_testhive'),
    'notice' => $notice,
    'syncurl' => $syncurl,
    'synclabel' => get_string('syncgrades', 'mod_testhive'),
    'showresults' => $isteacher && count($resultrows) > 0,
    'resultrows' => $resultrows,
    'resultheading' => get_string('resultsheading', 'mod_testhive'),
    'colstudent' => get_string('resultstudent', 'mod_testhive'),
    'colsubmitted' => get_string('resultsubmitted', 'mod_testhive'),
    'colevaluated' => get_string('resultevaluated', 'mod_testhive'),
    'colscore' => get_string('resultscore', 'mod_testhive'),
    'colpassed' => get_string('resultpassed', 'mod_testhive'),
]);
echo $OUTPUT->footer();
