<?php
// This file is part of Moodle - http://moodle.org/

defined('MOODLE_INTERNAL') || die();

use mod_testhive\local\api_client;

/**
 * @param string $feature FEATURE_xx constant
 * @return bool|null
 */
function testhive_supports(string $feature) {
    switch ($feature) {
        case FEATURE_MOD_INTRO:
            return true;
        case FEATURE_SHOW_DESCRIPTION:
            return true;
        case FEATURE_GRADE_HAS_GRADE:
            return true;
        case FEATURE_BACKUP_MOODLE2:
            return true;
        default:
            return null;
    }
}

/**
 * @param stdClass $data
 * @param moodleform|null $mform
 * @return int instance id
 * @throws moodle_exception
 */
function testhive_add_instance(stdClass $data, $mform = null) {
    global $DB;

    $data->timemodified = time();
    $id = $DB->insert_record('testhive', $data);
    $instance = $DB->get_record('testhive', ['id' => $id], '*', MUST_EXIST);
    testhive_sync_remote_test($instance);
    return $id;
}

/**
 * @param stdClass $data
 * @param moodleform|null $mform
 * @return bool
 */
function testhive_update_instance(stdClass $data, $mform = null) {
    global $DB;

    $data->timemodified = time();
    $data->id = $data->instance;
    $DB->update_record('testhive', $data);
    $instance = $DB->get_record('testhive', ['id' => $data->id], '*', MUST_EXIST);
    testhive_sync_remote_test($instance);
    return true;
}

/**
 * @param int $id
 * @return bool
 */
function testhive_delete_instance($id) {
    global $DB;
    $DB->delete_records('testhive', ['id' => $id]);
    return true;
}

/**
 * Build invitee list from course enrolment.
 *
 * @param int $courseid
 * @return array<int, array{email: string, externalId: string}>
 */
function testhive_build_invitees_for_course(int $courseid): array {
    $context = context_course::instance($courseid);
    $users = get_enrolled_users($context, 'mod/testhive:view', 0, 'u.id, u.email');
    $invitees = [];
    foreach ($users as $user) {
        if (empty($user->email)) {
            continue;
        }
        $invitees[] = [
            'email' => strtolower(trim($user->email)),
            'externalId' => (string) $user->id,
        ];
    }
    return $invitees;
}

/**
 * Create or update the remote Testhive test for this activity instance.
 *
 * @param stdClass $instance
 */
function testhive_external_ref(stdClass $instance): string {
    global $CFG;
    $sitekey = substr(sha1($CFG->wwwroot), 0, 16);
    return 'moodle:' . $sitekey . ':' . (int) $instance->id;
}

function testhive_sync_remote_test(stdClass $instance): void {
    global $DB;

    $invitees = testhive_build_invitees_for_course((int) $instance->course);
    if (count($invitees) === 0) {
        return;
    }

    $payload = [
        'name' => $instance->name,
        'questionset' => $instance->questionset,
        'starttime' => (int) $instance->timeopen,
        'endtime' => (int) $instance->timeclose,
        'invitees' => $invitees,
        'externalRef' => testhive_external_ref($instance),
        'notifyInvitees' => false,
        'slotMode' => 'shared_window',
    ];

    $response = api_client::request('POST', '/v1/tests', $payload);
    $testid = $response['data']['id'] ?? null;
    if (!$testid) {
        throw new moodle_exception('error_apirequest', 'mod_testhive', '', 'Missing test id in API response');
    }

    if ($instance->testhive_test_id !== $testid) {
        $DB->set_field('testhive', 'testhive_test_id', $testid, ['id' => $instance->id]);
        $instance->testhive_test_id = $testid;
    }
}

/**
 * @param stdClass $instance
 * @param int $userid
 * @return string launch URL
 */
function testhive_get_launch_url(stdClass $instance, int $userid): string {
    global $DB;
    $user = $DB->get_record('user', ['id' => $userid], 'id,email', MUST_EXIST);
    if (empty($user->email)) {
        throw new moodle_exception('error_apirequest', 'mod_testhive', '', 'User has no email address');
    }

    if (empty($instance->testhive_test_id)) {
        testhive_sync_remote_test($instance);
    }

    $response = api_client::request('POST', '/v1/tests/' . $instance->testhive_test_id . '/launch', [
        'email' => strtolower(trim($user->email)),
    ]);

    $url = $response['data']['url'] ?? '';
    if ($url === '') {
        throw new moodle_exception('error_apirequest', 'mod_testhive', '', 'Missing launch URL');
    }
    return $url;
}

/**
 * @param stdClass $testhive
 * @param int $userid 0 = all users
 */
function testhive_update_grades(stdClass $testhive, int $userid = 0): void {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    if (empty($testhive->testhive_test_id)) {
        return;
    }

    $response = api_client::request('GET', '/v1/tests/' . $testhive->testhive_test_id . '/results');
    $learners = $response['data']['learners'] ?? [];
    if (!is_array($learners)) {
        return;
    }

    $grades = [];
    foreach ($learners as $learner) {
        if (empty($learner['evaluated']) || $learner['score'] === null) {
            continue;
        }
        $moodleuserid = 0;
        if (!empty($learner['externalId'])) {
            $moodleuserid = (int) $learner['externalId'];
        } else if (!empty($learner['email'])) {
            $matched = \core_user::get_user_by_email($learner['email']);
            if ($matched) {
                $moodleuserid = (int) $matched->id;
            }
        }
        if ($moodleuserid <= 0) {
            continue;
        }
        if ($userid !== 0 && $userid !== $moodleuserid) {
            continue;
        }
        $grades[$moodleuserid] = (object) [
            'rawgrade' => (float) $learner['score'],
            'datesubmitted' => time(),
        ];
    }

    if (count($grades) === 0) {
        return;
    }

    $item = [
        'itemname' => $testhive->name,
        'gradetype' => GRADE_TYPE_VALUE,
        'grademax' => (float) $testhive->grade,
        'grademin' => 0,
    ];

    grade_update('mod/testhive', $testhive->course, 'mod', 'testhive', $testhive->id, 0, $grades, $item);
}
