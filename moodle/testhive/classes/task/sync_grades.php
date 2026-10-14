<?php
// This file is part of Moodle - http://moodle.org/

namespace mod_testhive\task;

defined('MOODLE_INTERNAL') || die();

use mod_testhive\local\api_client;

/**
 * Scheduled task: sync grades from Testhive Core into the Moodle gradebook.
 */
class sync_grades extends \core\task\scheduled_task {
    public function get_name() {
        return get_string('task_sync_grades', 'mod_testhive');
    }

    public function execute() {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/mod/testhive/lib.php');

        if (!api_client::is_configured()) {
            return;
        }

        $instances = $DB->get_records('testhive', null, '', 'id, course, name, questionset, testhive_test_id, timeopen, timeclose, grade');
        foreach ($instances as $instance) {
            try {
                testhive_sync_remote_test($instance);
                testhive_update_grades($instance);
            } catch (\moodle_exception $e) {
                mtrace('mod_testhive: instance ' . $instance->id . ': ' . $e->getMessage());
            }
        }
    }
}
