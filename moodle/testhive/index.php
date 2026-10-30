<?php
// This file is part of Moodle - http://moodle.org/

require_once('../../config.php');

$id = required_param('id', PARAM_INT);

$course = $DB->get_record('course', ['id' => $id], '*', MUST_EXIST);
require_login($course);
$context = context_course::instance($course->id);
require_capability('mod/testhive:view', $context);

$PAGE->set_url('/mod/testhive/index.php', ['id' => $id]);
$PAGE->set_title(get_string('modulenameplural', 'mod_testhive'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

$instances = get_all_instances_in_course('testhive', $course);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('modulenameplural', 'mod_testhive'));

if (!$instances) {
    echo $OUTPUT->notification(get_string('none', 'mod_testhive'), 'info');
    echo $OUTPUT->footer();
    exit;
}

$table = new html_table();
$table->head = [
    get_string('name'),
    get_string('timeopen', 'mod_testhive'),
    get_string('timeclose', 'mod_testhive'),
];
$table->attributes['class'] = 'generaltable';

foreach ($instances as $instance) {
    $url = new moodle_url('/mod/testhive/view.php', ['id' => $instance->coursemodule]);
    $table->data[] = [
        html_writer::link($url, format_string($instance->name)),
        $instance->timeopen ? userdate($instance->timeopen) : '-',
        $instance->timeclose ? userdate($instance->timeclose) : '-',
    ];
}

echo html_writer::table($table);
echo $OUTPUT->footer();
