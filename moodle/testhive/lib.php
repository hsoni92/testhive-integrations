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
        case FEATURE_COMPLETION_TRACKS_VIEWS:
            return true;
        case FEATURE_GROUPS:
            return true;
        case FEATURE_MOD_PURPOSE:
            return MOD_PURPOSE_ASSESSMENT;
        default:
            return null;
    }
}

/**
 * Keep the Testhive mark in brand colour instead of the assessment tint.
 *
 * @return bool
 */
function testhive_is_branded(): bool {
    return true;
}

/**
 * @param stdClass $data
 * @param moodleform|null $mform
 * @return int instance id
 * @throws moodle_exception
 */
function testhive_add_instance(stdClass $data, $mform = null) {
    global $DB;

    $data = testhive_normalize_instance_data($data);
    $data->timemodified = time();
    $id = $DB->insert_record('testhive', $data);
    $instance = $DB->get_record('testhive', ['id' => $id], '*', MUST_EXIST);
    testhive_grade_item_update($instance);
    testhive_update_calendar($instance, (int) ($data->coursemodule ?? 0));
    testhive_sync_remote_test($instance, (int) ($data->coursemodule ?? 0));
    return $id;
}

/**
 * @param stdClass $data
 * @param moodleform|null $mform
 * @return bool
 */
function testhive_update_instance(stdClass $data, $mform = null) {
    global $DB;

    $data = testhive_normalize_instance_data($data);
    $data->timemodified = time();
    $data->id = $data->instance;
    $DB->update_record('testhive', $data);
    $instance = $DB->get_record('testhive', ['id' => $data->id], '*', MUST_EXIST);
    testhive_grade_item_update($instance);
    testhive_update_calendar($instance, (int) ($data->coursemodule ?? 0));
    testhive_sync_remote_test($instance, (int) ($data->coursemodule ?? 0));
    return true;
}

/**
 * @param int $id
 * @return bool
 */
function testhive_delete_instance($id) {
    global $DB;
    $instance = $DB->get_record('testhive', ['id' => $id]);
    if (!$instance) {
        return false;
    }
    if (!empty($instance->testhive_test_id) && api_client::is_configured()) {
        try {
            api_client::request('POST', '/v1/tests/' . $instance->testhive_test_id . '/cancel', []);
        } catch (Throwable $e) {
            debugging('mod_testhive: cancel test ' . $instance->testhive_test_id . ': ' . $e->getMessage(), DEBUG_NORMAL);
        }
    }
    testhive_grade_item_delete($instance);
    testhive_delete_calendar($instance);
    $DB->delete_records('testhive', ['id' => $id]);
    return true;
}

/**
 * @param stdClass $data
 * @return stdClass
 */
function testhive_normalize_instance_data(stdClass $data): stdClass {
    $data->proctorenabled = empty($data->proctorenabled) ? 0 : 1;
    $data->timebound = empty($data->timebound) ? 0 : 1;
    $data->sessionduration = isset($data->sessionduration) ? (int) $data->sessionduration : 0;
    if (!isset($data->instructions)) {
        $data->instructions = '';
    }
    return $data;
}

/**
 * Resync every Testhive activity in a course (enrolment/group changes).
 *
 * @param int $courseid
 */
function testhive_resync_course_instances(int $courseid): void {
    global $DB;

    if (!api_client::is_configured()) {
        return;
    }

    $instances = $DB->get_records('testhive', ['course' => $courseid]);
    foreach ($instances as $instance) {
        try {
            testhive_sync_remote_test($instance);
        } catch (Throwable $e) {
            debugging('mod_testhive: course ' . $courseid . ' instance ' . $instance->id . ': ' . $e->getMessage(), DEBUG_NORMAL);
        }
    }
}

/**
 * Resolve the course-module row for this activity (add_instance runs before cm.instance is set).
 *
 * @param stdClass $instance
 * @param int $cmid course_modules.id when known (required during add_instance)
 * @return stdClass|null
 */
function testhive_get_module_cm(stdClass $instance, int $cmid = 0): ?stdClass {
    global $DB;

    if ($cmid > 0) {
        // During add_instance, cm.instance is not set yet so get_coursemodule_from_id() cannot join the activity table.
        $record = $DB->get_record_sql(
            "SELECT cm.*, md.name AS modname
               FROM {course_modules} cm
               JOIN {modules} md ON md.id = cm.module
              WHERE cm.id = :cmid
                AND cm.course = :courseid
                AND md.name = :modulename",
            [
                'cmid' => $cmid,
                'courseid' => (int) $instance->course,
                'modulename' => 'testhive',
            ],
            IGNORE_MISSING
        );
        return $record ?: null;
    }

    $cm = get_coursemodule_from_instance('testhive', (int) $instance->id, (int) $instance->course, false, IGNORE_MISSING);
    return $cm ?: null;
}

/**
 * Build invitee list for this activity (respects separate groups).
 *
 * @param stdClass $instance
 * @param int $cmid
 * @return array<int, array{email: string, externalId: string}>
 */
function testhive_build_invitees(stdClass $instance, int $cmid = 0): array {
    $cm = testhive_get_module_cm($instance, $cmid);
    if (!$cm) {
        return [];
    }
    $context = context_module::instance($cm->id);
    $users = [];

    if ((int) $cm->groupmode === SEPARATEGROUPS) {
        $groups = groups_get_all_groups((int) $instance->course, 0, (int) $cm->groupingid);
        foreach ($groups as $group) {
            foreach (get_enrolled_users($context, 'mod/testhive:attempt', $group->id, 'u.id, u.email') as $user) {
                $users[$user->id] = $user;
            }
        }
    } else {
        foreach (get_enrolled_users($context, 'mod/testhive:attempt', 0, 'u.id, u.email') as $user) {
            $users[$user->id] = $user;
        }
    }

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
 * @param stdClass $instance
 * @return int
 */
function testhive_session_duration_sec(stdClass $instance): int {
    $duration = (int) ($instance->sessionduration ?? 0);
    if ($duration > 0) {
        return $duration;
    }
    $window = (int) $instance->timeclose - (int) $instance->timeopen;
    return max(1, $window);
}

/**
 * @param stdClass $instance
 * @param array $invitees
 * @return array
 */
function testhive_build_sync_payload(stdClass $instance, array $invitees): array {
    $payload = [
        'name' => $instance->name,
        'questionset' => $instance->questionset,
        'starttime' => (int) $instance->timeopen,
        'endtime' => (int) $instance->timeclose,
        'invitees' => $invitees,
        'externalRef' => testhive_external_ref($instance),
        'notifyInvitees' => false,
        'slotMode' => 'shared_window',
        'sessionDurationSec' => testhive_session_duration_sec($instance),
        'proctorEnabled' => !empty($instance->proctorenabled),
        'timebound' => !empty($instance->timebound),
    ];
    $instructions = trim((string) ($instance->instructions ?? ''));
    if ($instructions !== '') {
        $payload['instructions'] = $instructions;
    }
    return $payload;
}

/**
 * @param stdClass $instance
 */
function testhive_external_ref(stdClass $instance): string {
    global $CFG;
    $sitekey = substr(sha1($CFG->wwwroot), 0, 16);
    return 'moodle:' . $sitekey . ':' . (int) $instance->id;
}

/**
 * Create or update the remote Testhive test for this activity instance.
 *
 * @param stdClass $instance
 * @param int $cmid course_modules.id when known (pass during add_instance)
 */
function testhive_sync_remote_test(stdClass $instance, int $cmid = 0): void {
    global $DB;

    $invitees = testhive_build_invitees($instance, $cmid);
    if (count($invitees) === 0) {
        return;
    }

    $payload = testhive_build_sync_payload($instance, $invitees);
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

    testhive_sync_remote_test($instance);
    $instance = $DB->get_record('testhive', ['id' => $instance->id], '*', MUST_EXIST);
    if (empty($instance->testhive_test_id)) {
        throw new moodle_exception('error_apirequest', 'mod_testhive', '', 'Missing test id after sync');
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
 * @param array $learner
 * @return float|null
 */
function testhive_scale_learner_grade(stdClass $testhive, array $learner): ?float {
    if (empty($learner['evaluated']) || $learner['score'] === null) {
        return null;
    }
    $grademax = (float) $testhive->grade;
    if ($grademax <= 0) {
        return null;
    }
    $maxscore = (float) ($learner['maxScore'] ?? 0);
    $score = (float) $learner['score'];
    if ($maxscore > 0) {
        return ($score / $maxscore) * $grademax;
    }
    if (isset($learner['percentScore']) && $learner['percentScore'] !== null) {
        return ((float) $learner['percentScore'] / 100) * $grademax;
    }
    return null;
}

/**
 * @param stdClass $testhive
 * @param int $userid 0 = all users
 */
function testhive_update_grades(stdClass $testhive, int $userid = 0): void {
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
        $raw = testhive_scale_learner_grade($testhive, $learner);
        if ($raw === null) {
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
        // grade_update() ignores a grade unless the array key and userid are the same user id.
        $grades[$moodleuserid] = (object) [
            'userid' => $moodleuserid,
            'rawgrade' => $raw,
            'datesubmitted' => time(),
        ];
    }

    if (count($grades) === 0) {
        return;
    }

    testhive_grade_item_update($testhive, $grades);
}

/**
 * @param stdClass $instance
 * @return array<int, array<string, string>>
 */
function testhive_format_results_rows(stdClass $instance): array {
    if (empty($instance->testhive_test_id)) {
        return [];
    }

    $response = api_client::request('GET', '/v1/tests/' . $instance->testhive_test_id . '/results');
    $learners = $response['data']['learners'] ?? [];
    if (!is_array($learners)) {
        return [];
    }

    $rows = [];
    foreach ($learners as $learner) {
        $userid = 0;
        $name = $learner['email'] ?? '-';
        if (!empty($learner['externalId'])) {
            $userid = (int) $learner['externalId'];
            $user = \core_user::get_user($userid, false);
            if ($user) {
                $name = fullname($user);
            }
        } else if (!empty($learner['email'])) {
            $user = \core_user::get_user_by_email($learner['email']);
            if ($user) {
                $name = fullname($user);
            }
        }

        $score = '-';
        $scaled = testhive_scale_learner_grade($instance, $learner);
        if ($scaled !== null) {
            $score = format_float($scaled, 2);
        } else if ($learner['score'] !== null) {
            $score = format_float((float) $learner['score'], 2);
        }

        $rows[] = [
            'name' => $name,
            'submitted' => !empty($learner['submitted']) ? get_string('yes') : get_string('no'),
            'evaluated' => !empty($learner['evaluated']) ? get_string('yes') : get_string('no'),
            'score' => $score,
            'passed' => !empty($learner['passed']) ? get_string('yes') : get_string('no'),
        ];
    }
    return $rows;
}

/**
 * Create or update the gradebook item for this activity.
 *
 * @param stdClass $instance
 * @param array|null $grades
 * @return int
 */
function testhive_grade_item_update(stdClass $instance, ?array $grades = null): int {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    if ($grades === null) {
        $item = [
            'itemname' => $instance->name,
            'gradetype' => GRADE_TYPE_VALUE,
            'grademax' => (float) $instance->grade,
            'grademin' => 0,
        ];
        return grade_update('mod/testhive', (int) $instance->course, 'mod', 'testhive', (int) $instance->id, 0, null, $item);
    }

    return grade_update(
        'mod/testhive',
        (int) $instance->course,
        'mod',
        'testhive',
        (int) $instance->id,
        0,
        $grades,
        null
    );
}

/**
 * @param stdClass $instance
 * @return int
 */
function testhive_grade_item_delete(stdClass $instance): int {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');
    return grade_update('mod/testhive', (int) $instance->course, 'mod', 'testhive', (int) $instance->id, 0, null, ['deleted' => 1]);
}

/**
 * Dates shown under the activity on the course page.
 *
 * @param stdClass $coursemodule
 * @return cached_cm_info|false
 */
function testhive_get_coursemodule_info($coursemodule) {
    global $DB;

    $instance = $DB->get_record('testhive', ['id' => $coursemodule->instance], 'id, name, intro, introformat, timeopen, timeclose');
    if (!$instance) {
        return false;
    }

    $info = new cached_cm_info();
    $info->name = $instance->name;
    if ($coursemodule->showdescription) {
        $info->content = format_module_intro('testhive', $instance, $coursemodule->id, false);
    }
    if ($instance->timeopen) {
        $info->customdata['timeopen'] = (int) $instance->timeopen;
    }
    if ($instance->timeclose) {
        $info->customdata['timeclose'] = (int) $instance->timeclose;
    }
    return $info;
}

/**
 * Grey out the chooser entry until a site admin connects Testhive.
 *
 * @param \core_course\local\entity\content_item $defaultitem
 * @param stdClass $user
 * @param stdClass $course
 * @return \core_course\local\entity\content_item[]
 */
function testhive_get_course_content_items($defaultitem, $user, $course): array {
    if (api_client::is_configured()) {
        return [$defaultitem];
    }
    $reason = has_capability('moodle/site:config', \context_system::instance(), $user)
        ? get_string('unconfigured_chooser_admin', 'mod_testhive')
        : get_string('unconfigured_chooser_user', 'mod_testhive');
    return [new \core_course\local\entity\content_item(
        id: $defaultitem->get_id(),
        name: $defaultitem->get_name(),
        title: $defaultitem->get_title(),
        link: $defaultitem->get_link(),
        icon: $defaultitem->get_icon(),
        help: $defaultitem->get_help(),
        archetype: $defaultitem->get_archetype(),
        componentname: $defaultitem->get_component_name(),
        purpose: $defaultitem->get_purpose(),
        branded: $defaultitem->is_branded(),
        gradable: $defaultitem->is_gradable(),
        otherpurpose: $defaultitem->get_other_purpose(),
        summary: $defaultitem->get_summary(),
        disabled: true,
        disabledreason: $reason,
    )];
}

/**
 * @param stdClass $instance
 */
function testhive_update_calendar(stdClass $instance, int $cmid = 0): void {
    global $CFG, $DB;
    require_once($CFG->dirroot . '/calendar/lib.php');

    if ($cmid <= 0) {
        $cm = get_coursemodule_from_instance('testhive', $instance->id, $instance->course, false, IGNORE_MISSING);
        $cmid = $cm ? (int) $cm->id : 0;
    }
    if ($cmid <= 0) {
        return;
    }

    $times = [
        'open' => (int) $instance->timeopen,
        'close' => (int) $instance->timeclose,
    ];
    foreach ($times as $type => $time) {
        $params = ['modulename' => 'testhive', 'instance' => $instance->id, 'eventtype' => $type];
        $existing = $DB->get_record('event', $params);
        if ($time <= 0) {
            if ($existing) {
                calendar_event::load($existing)->delete();
            }
            continue;
        }
        $event = (object) [
            'name' => get_string('calendarevent' . $type, 'mod_testhive', $instance->name),
            'description' => format_module_intro('testhive', $instance, $cmid, false),
            'format' => FORMAT_HTML,
            'courseid' => $instance->course,
            'groupid' => 0,
            'userid' => 0,
            'modulename' => 'testhive',
            'instance' => $instance->id,
            'eventtype' => $type,
            'timestart' => $time,
            'timeduration' => 0,
            'type' => CALENDAR_EVENT_TYPE_ACTION,
            'visible' => instance_is_visible('testhive', $instance),
        ];
        if ($existing) {
            $event->id = $existing->id;
            calendar_event::load($existing)->update($event, false);
        } else {
            calendar_event::create($event, false);
        }
    }
}

/**
 * @param stdClass $instance
 */
function testhive_delete_calendar(stdClass $instance): void {
    global $CFG, $DB;
    require_once($CFG->dirroot . '/calendar/lib.php');
    $events = $DB->get_records('event', ['modulename' => 'testhive', 'instance' => $instance->id]);
    foreach ($events as $event) {
        calendar_event::load($event)->delete();
    }
}

/**
 * Plain-language label for a Testhive test status.
 *
 * @param string $status
 * @return string
 */
function testhive_status_label(string $status): string {
    $known = ['active', 'upcoming', 'should_start', 'ended', 'cancelled'];
    $key = str_replace('-', '_', strtolower($status));
    if (in_array($key, $known, true)) {
        return get_string('status_' . $key, 'mod_testhive');
    }
    if ($status === '' || $status === '-') {
        return get_string('status_unknown', 'mod_testhive', '-');
    }
    return get_string('status_unknown', 'mod_testhive', $status);
}

/** @var string Site-wide wizard mutex cache key */
const TESTHIVE_WIZARD_MUTEX_KEY = 'active';

/** @var string Lock resource for Core wizard API calls */
const TESTHIVE_WIZARD_LOCK_RESOURCE = 'mod_testhive_wizard';

/**
 * @return \core_cache\cache
 */
function testhive_wizard_mutex_cache(): \core_cache\cache {
    return \core_cache\cache::make('mod_testhive', 'wizardmutex');
}

/**
 * User id holding the site wizard mutex, or null.
 *
 * @return int|null
 */
function testhive_wizard_mutex_owner(): ?int {
    $value = testhive_wizard_mutex_cache()->get(TESTHIVE_WIZARD_MUTEX_KEY);
    if ($value === false || $value === null) {
        return null;
    }
    return (int) $value;
}

/**
 * @param int $userid
 */
function testhive_wizard_mutex_acquire(int $userid): void {
    testhive_wizard_mutex_cache()->set(TESTHIVE_WIZARD_MUTEX_KEY, $userid);
}

/**
 */
function testhive_wizard_mutex_release(): void {
    testhive_wizard_mutex_cache()->delete(TESTHIVE_WIZARD_MUTEX_KEY);
}

/**
 * Run a callback while holding the Moodle lock for wizard API calls.
 *
 * @param callable $callback
 * @return mixed
 */
function testhive_wizard_with_lock(callable $callback) {
    $factory = \core\lock\lock_config::get_lock_factory('mod_testhive');
    $lock = $factory->get_lock(TESTHIVE_WIZARD_LOCK_RESOURCE, 30, 1800);
    if (!$lock) {
        throw new moodle_exception('wizard_inprogress', 'mod_testhive');
    }
    try {
        return $callback();
    } finally {
        $lock->release();
    }
}

/**
 * @param int $userid
 * @throws moodle_exception
 */
function testhive_wizard_assert_mutex_owner(int $userid): void {
    $owner = testhive_wizard_mutex_owner();
    if ($owner === null) {
        throw new moodle_exception('wizard_notstarted', 'mod_testhive');
    }
    if ($owner !== $userid) {
        throw new moodle_exception('wizard_inprogress', 'mod_testhive');
    }
}

/**
 * Resolve the course id on the activity add/edit form (global $COURSE is authoritative).
 *
 * @param stdClass $course
 * @param stdClass|null $cm
 * @param context|null $context
 * @return int
 */
function testhive_mod_form_course_id(stdClass $course, $cm = null, $context = null): int {
    global $COURSE, $PAGE;

    $fromurl = optional_param('course', 0, PARAM_INT);
    if ($fromurl > 0) {
        return $fromurl;
    }
    if (!empty($PAGE->course->id)) {
        return (int) $PAGE->course->id;
    }
    if (!empty($COURSE->id)) {
        return (int) $COURSE->id;
    }
    if (!empty($course->id)) {
        return (int) $course->id;
    }
    if ($cm && !empty($cm->course)) {
        return (int) $cm->course;
    }
    if ($context instanceof context && (int) $context->contextlevel === CONTEXT_COURSE) {
        return (int) $context->instanceid;
    }
    return 0;
}

/**
 * Strip answers and rubric fields from wizard questions for teacher preview.
 *
 * @param array $questions
 * @return array<int, array{index: int, stem: string, options: string[]}>
 */
function testhive_sanitize_wizard_preview_questions(array $questions): array {
    $out = [];
    $index = 0;
    foreach ($questions as $question) {
        if (!is_array($question)) {
            continue;
        }
        $index++;
        $stem = trim((string) ($question['question'] ?? ''));
        $options = [];
        if (!empty($question['options']) && is_array($question['options'])) {
            foreach ($question['options'] as $option) {
                if (is_array($option) && array_key_exists('text', $option)) {
                    $options[] = trim((string) $option['text']);
                } else if (is_string($option)) {
                    $options[] = trim($option);
                }
            }
        }
        $out[] = [
            'index' => $index,
            'stem' => $stem,
            'options' => array_values(array_filter($options, static function (string $text): bool {
                return $text !== '';
            })),
        ];
    }
    return $out;
}

/**
 * @param array $questions raw wizard questions from Core state
 * @return string HTML preview list
 */
function testhive_format_wizard_preview_html(array $questions): string {
    $rows = testhive_sanitize_wizard_preview_questions($questions);
    if (count($rows) === 0) {
        return html_writer::div(get_string('createquestionset_preview_empty', 'mod_testhive'), 'text-muted');
    }
    $items = [];
    foreach ($rows as $row) {
        $title = html_writer::tag('strong', $row['index'] . '. ') . format_string($row['stem']);
        $body = $title;
        if (count($row['options']) > 0) {
            $optionitems = [];
            foreach ($row['options'] as $optiontext) {
                $optionitems[] = html_writer::tag('li', format_string($optiontext));
            }
            $body .= html_writer::tag('ul', implode('', $optionitems), ['class' => 'mb-0 ms-3 small']);
        }
        $items[] = html_writer::tag('li', $body, ['class' => 'mb-2']);
    }
    return html_writer::tag('ol', implode('', $items), ['class' => 'testhive-wizard-preview-list mb-0']);
}

/**
 * @return int
 */
function testhive_wizard_expected_question_count(): int {
    global $SESSION;
    if (!empty($SESSION->mod_testhive_wizard->numquestions)) {
        return max(1, (int) $SESSION->mod_testhive_wizard->numquestions);
    }
    return 0;
}
