<?php
// This file is part of Moodle - http://moodle.org/

namespace mod_testhive;

defined('MOODLE_INTERNAL') || die();

/**
 * Keeps Testhive invitees aligned with course enrolment and groups.
 */
class observer {
    /**
     * @param \core\event\base $event
     */
    public static function course_membership_changed(\core\event\base $event): void {
        $courseid = self::resolve_course_id($event);
        if ($courseid === null) {
            return;
        }
        testhive_resync_course_instances($courseid);
    }

    /**
     * @param \core\event\base $event
     * @return int|null
     */
    private static function resolve_course_id(\core\event\base $event): ?int {
        if (!empty($event->courseid)) {
            return (int) $event->courseid;
        }
        $context = $event->get_context();
        if ($context->contextlevel === CONTEXT_COURSE) {
            return (int) $context->instanceid;
        }
        if ($context->contextlevel === CONTEXT_MODULE) {
            $cm = get_coursemodule_from_id(null, $context->instanceid, 0, false, IGNORE_MISSING);
            return $cm ? (int) $cm->course : null;
        }
        return null;
    }
}
