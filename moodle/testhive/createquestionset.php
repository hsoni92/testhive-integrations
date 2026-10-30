<?php
// This file is part of Moodle - http://moodle.org/

require_once('../../config.php');
require_once($CFG->dirroot . '/mod/testhive/lib.php');
require_once($CFG->dirroot . '/mod/testhive/createquestionset_form.php');

use mod_testhive\local\api_client;

global $DB, $PAGE, $OUTPUT, $USER, $SESSION;

$courseid = optional_param('courseid', 0, PARAM_INT);
if ($courseid <= 0) {
    $courseid = optional_param('course', 0, PARAM_INT);
}
if ($courseid <= 0) {
    $courseid = optional_param('id', 0, PARAM_INT);
}
if ($courseid <= 0 && !empty($SESSION->mod_testhive_wizard->courseid)) {
    $courseid = (int) $SESSION->mod_testhive_wizard->courseid;
}
if ($courseid <= 0) {
    require_login();
    throw new moodle_exception('createquestionset_missingcourse', 'mod_testhive');
}
$action = optional_param('action', '', PARAM_ALPHA);
$step = optional_param('step', '', PARAM_ALPHA);

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
require_login($course);
$context = context_course::instance($courseid);
require_capability('mod/testhive:addinstance', $context);

if (!api_client::is_configured()) {
    throw new moodle_exception('error_apinotconfigured', 'mod_testhive');
}

$baseurl = new moodle_url('/mod/testhive/createquestionset.php', ['courseid' => $courseid]);
$PAGE->set_url($baseurl);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('createquestionset', 'mod_testhive'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

/**
 * @param int $userid
 */
$clearwizard = function (int $userid) use (&$SESSION): void {
    testhive_wizard_mutex_release();
    unset($SESSION->mod_testhive_wizard);
};

if ($action === 'status') {
    require_sesskey();
    $expected = testhive_wizard_expected_question_count();
    $response = [
        'ready' => false,
        'failed' => false,
        'queued' => false,
        'count' => 0,
        'expected' => $expected,
        'preview' => [],
        'progressLabel' => '',
        'message' => '',
    ];
    try {
        testhive_wizard_assert_mutex_owner((int) $USER->id);
        $state = api_client::wizard_get_state();
        if ($state === null) {
            $response['failed'] = true;
            $response['message'] = get_string('createquestionset_failed', 'mod_testhive');
        } else {
            $questions = $state['questions'] ?? [];
            if (!is_array($questions)) {
                $questions = [];
            }
            $response['count'] = count($questions);
            $response['ready'] = count($questions) > 0;
            $response['queued'] = !empty($state['jobId']) && count($questions) === 0;
            $response['preview'] = testhive_sanitize_wizard_preview_questions($questions);
            if ($response['ready']) {
                $response['progressLabel'] = get_string('createquestionset_ready', 'mod_testhive');
            } else if ($expected > 0) {
                $response['progressLabel'] = get_string('createquestionset_progress', 'mod_testhive', (object) [
                    'count' => $response['count'],
                    'expected' => $expected,
                ]);
            } else if ($response['queued']) {
                $response['progressLabel'] = get_string('createquestionset_progress_waiting', 'mod_testhive');
            } else {
                $response['progressLabel'] = get_string('createquestionset_generating', 'mod_testhive');
            }
        }
    } catch (moodle_exception $e) {
        $response['failed'] = true;
        $response['message'] = $e->getMessage();
    }
    header('Content-Type: application/json');
    echo json_encode($response);
    die;
}

$mutexowner = testhive_wizard_mutex_owner();
if ($mutexowner !== null && $mutexowner !== (int) $USER->id) {
    throw new moodle_exception('wizard_inprogress', 'mod_testhive');
}

$generating = ($step === 'generating') || ($mutexowner === (int) $USER->id && !empty($SESSION->mod_testhive_wizard));
$readytosave = false;
$wizardquestions = [];
if ($generating) {
    try {
        $state = api_client::wizard_get_state();
        $wizardquestions = is_array($state) ? ($state['questions'] ?? []) : [];
        if (!is_array($wizardquestions)) {
            $wizardquestions = [];
        }
        $readytosave = count($wizardquestions) > 0;
    } catch (moodle_exception $e) {
        $clearwizard((int) $USER->id);
        throw $e;
    }
}

$previewhtml = '';
if ($generating && count($wizardquestions) > 0) {
    $previewhtml = testhive_format_wizard_preview_html($wizardquestions);
}

$mform = new mod_testhive_createquestionset_form(
    $generating && !$readytosave,
    $generating && $readytosave,
    $baseurl->out(false),
    [
        'courseid' => $courseid,
        'previewhtml' => $previewhtml,
    ]
);

if ($mform->is_cancelled()) {
    redirect(new moodle_url('/course/view.php', ['id' => $courseid]));
}

if ($data = $mform->get_data()) {
    if (!empty($data->cancelwizard)) {
        $clearwizard((int) $USER->id);
        redirect($baseurl, get_string('createquestionset_cancelled', 'mod_testhive'), null, \core\output\notification::NOTIFY_INFO);
    }

    if (!empty($data->finalizebutton)) {
        testhive_wizard_assert_mutex_owner((int) $USER->id);
        if (empty($SESSION->mod_testhive_wizard->qsname)) {
            throw new moodle_exception('wizard_notstarted', 'mod_testhive');
        }
        $name = (string) $SESSION->mod_testhive_wizard->qsname;
        try {
            $created = testhive_wizard_with_lock(function () use ($name) {
                return api_client::wizard_finalize_questionset($name);
            });
        } catch (moodle_exception $e) {
            throw $e;
        }
        $clearwizard((int) $USER->id);
        $message = get_string('createquestionset_created', 'mod_testhive', (object) [
            'name' => $created['name'],
            'id' => $created['id'],
        ]);
        redirect($baseurl, $message . ' ' . get_string('createquestionset_reloadform', 'mod_testhive'),
            null, \core\output\notification::NOTIFY_SUCCESS);
    }

    if (!empty($data->qsname)) {
        if ($mutexowner !== null && $mutexowner !== (int) $USER->id) {
            throw new moodle_exception('wizard_inprogress', 'mod_testhive');
        }
        if ($mutexowner === null) {
            testhive_wizard_mutex_acquire((int) $USER->id);
        }

        $intent = [
            'numQuestions' => (int) $data->numquestions,
            'answeringMode' => (string) $data->answeringmode,
        ];
        $sourcevalue = trim((string) $data->sourcevalue);
        if ($data->sourcetype === 'url') {
            $source = ['kind' => 'url', 'url' => $sourcevalue];
        } else {
            $source = ['kind' => 'text', 'text' => $sourcevalue];
        }

        try {
            testhive_wizard_with_lock(function () use ($intent, $source) {
                api_client::wizard_generate($intent, $source);
            });
        } catch (moodle_exception $e) {
            $clearwizard((int) $USER->id);
            throw $e;
        }

        $SESSION->mod_testhive_wizard = (object) [
            'qsname' => trim((string) $data->qsname),
            'courseid' => $courseid,
            'numquestions' => (int) $data->numquestions,
        ];
        redirect(new moodle_url($baseurl, ['step' => 'generating']));
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('createquestionset', 'mod_testhive'));

if ($generating) {
    $pollurl = new moodle_url($baseurl, [
        'action' => 'status',
        'sesskey' => sesskey(),
    ]);
    $PAGE->requires->js_call_amd('mod_testhive/createquestionset', 'init', [
        'pollUrl' => $pollurl->out(false),
        'pollIntervalMs' => 3000,
        'readyMessage' => get_string('createquestionset_ready', 'mod_testhive'),
        'failedMessage' => get_string('createquestionset_failed', 'mod_testhive'),
        'previewEmptyMessage' => get_string('createquestionset_preview_empty', 'mod_testhive'),
        'pollWhileReady' => !$readytosave,
    ]);
}

$mform->display();
echo $OUTPUT->footer();
