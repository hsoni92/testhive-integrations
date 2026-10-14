<?php
// This file is part of Moodle - http://moodle.org/

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Testhive assessment';
$string['modulename'] = 'Testhive assessment';
$string['modulenameplural'] = 'Testhive assessments';
$string['modulename_help'] = 'Schedule a Testhive test for enrolled learners and pass grades back to the gradebook.';
$string['testhive:addinstance'] = 'Add a Testhive assessment';
$string['testhive:view'] = 'View Testhive assessment';
$string['setting_api_base_url'] = 'Testhive Core API base URL';
$string['setting_api_base_url_desc'] = 'Example: https://your-site.example/api/core';
$string['setting_api_key'] = 'Testhive service API key';
$string['setting_api_key_desc'] = 'Organization service key (sk-...) from Testhive.';
$string['questionset'] = 'Question set';
$string['questionset_help'] = 'Question set created in Testhive for your organization.';
$string['availability'] = 'Availability window';
$string['timeopen'] = 'Opens';
$string['timeclose'] = 'Closes';
$string['error_apinotconfigured'] = 'Testhive API is not configured. Ask a site administrator to set the API URL and key.';
$string['error_apirequest'] = 'Testhive API request failed: {$a}';
$string['error_noquestionsets'] = 'No question sets were returned from Testhive.';
$string['teacher_status'] = 'Testhive test ID: {$a->testid}. Status: {$a->status}';
$string['task_sync_grades'] = 'Sync Testhive grades';
$string['privacy:metadata:mod_testhive'] = 'The Testhive activity sends learner email addresses and Moodle user IDs to the configured Testhive Core API to schedule tests, launch sessions, and retrieve grades.';
$string['privacy:metadata:email'] = 'Learner email address used to schedule and launch the test.';
$string['privacy:metadata:externalid'] = 'Moodle user ID sent as externalId for grade passback.';
