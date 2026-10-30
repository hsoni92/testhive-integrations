<?php
// This file is part of Moodle - http://moodle.org/

namespace mod_testhive\privacy;

defined('MOODLE_INTERNAL') || die();

use context;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider for mod_testhive.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider {

    public static function get_metadata(collection $collection): collection {
        $collection->add_external_location_link('testhive_core', [
            'email' => 'privacy:metadata:email',
            'externalid' => 'privacy:metadata:externalid',
        ], 'privacy:metadata:mod_testhive');

        return $collection;
    }

    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $sql = "SELECT DISTINCT ctx.id
                  FROM {context} ctx
                  JOIN {course_modules} cm ON cm.id = ctx.instanceid
                  JOIN {modules} m ON m.id = cm.module AND m.name = 'testhive'
                  JOIN {enrol} e ON e.courseid = cm.course
                  JOIN {user_enrolments} ue ON ue.enrolid = e.id AND ue.userid = :userid
                 WHERE ctx.contextlevel = :modulelevel";
        $contextlist->add_from_sql($sql, [
            'userid' => $userid,
            'modulelevel' => CONTEXT_MODULE,
        ]);
        return $contextlist;
    }

    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel !== CONTEXT_MODULE) {
                continue;
            }
            $cm = get_coursemodule_from_id('testhive', $context->instanceid, 0, false, MUST_EXIST);
            $instance = $DB->get_record('testhive', ['id' => $cm->instance], '*', MUST_EXIST);
            writer::with_context($context)->export_data([], (object) [
                'activityname' => format_string($instance->name),
                'testhivetransmission' => get_string('privacy:export:transmission', 'mod_testhive'),
            ]);
        }
    }

    public static function delete_data_for_all_users_in_context(context $context) {
    }

    public static function delete_data_for_user(approved_contextlist $contextlist) {
    }
}
