<?php
// This file is part of Moodle - http://moodle.org/

defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\core\event\user_enrolment_created',
        'callback' => '\mod_testhive\observer::course_membership_changed',
    ],
    [
        'eventname' => '\core\event\user_enrolment_deleted',
        'callback' => '\mod_testhive\observer::course_membership_changed',
    ],
    [
        'eventname' => '\core\event\role_assigned',
        'callback' => '\mod_testhive\observer::course_membership_changed',
    ],
    [
        'eventname' => '\core\event\role_unassigned',
        'callback' => '\mod_testhive\observer::course_membership_changed',
    ],
    [
        'eventname' => '\core\event\group_member_added',
        'callback' => '\mod_testhive\observer::course_membership_changed',
    ],
    [
        'eventname' => '\core\event\group_member_removed',
        'callback' => '\mod_testhive\observer::course_membership_changed',
    ],
];
