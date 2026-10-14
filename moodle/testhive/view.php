<?php
// This file is part of Moodle - http://moodle.org/

require_once('../../config.php');
require_once($CFG->dirroot . '/mod/testhive/lib.php');

use mod_testhive\local\api_client;

global $DB, $PAGE, $OUTPUT, $USER;

$id = required_param('id', PARAM_INT);

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

if (!api_client::is_configured()) {
    throw new moodle_exception('error_apinotconfigured', 'mod_testhive');
}

if (has_capability('moodle/course:manageactivities', $context)) {
    $status = '-';
    if (!empty($instance->testhive_test_id)) {
        try {
            $response = api_client::request('GET', '/v1/tests/' . $instance->testhive_test_id);
            $status = (string) ($response['data']['status'] ?? '-');
        } catch (moodle_exception $e) {
            $status = $e->getMessage();
        }
    }
    echo $OUTPUT->header();
    echo $OUTPUT->heading(format_string($instance->name));
    echo $OUTPUT->box(get_string('teacher_status', 'mod_testhive', (object) [
        'testid' => $instance->testhive_test_id ?: '-',
        'status' => $status,
    ]));
    echo $OUTPUT->footer();
    exit;
}

$launchurl = testhive_get_launch_url($instance, $USER->id);
redirect($launchurl);
