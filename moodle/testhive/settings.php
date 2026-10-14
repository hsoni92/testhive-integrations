<?php
// This file is part of Moodle - http://moodle.org/

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('modsettingtesthive', get_string('pluginname', 'mod_testhive'));

    $settings->add(new admin_setting_configtext(
        'mod_testhive/api_base_url',
        get_string('setting_api_base_url', 'mod_testhive'),
        get_string('setting_api_base_url_desc', 'mod_testhive'),
        'https://testhiveapp.com/api/core',
        PARAM_URL,
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'mod_testhive/api_key',
        get_string('setting_api_key', 'mod_testhive'),
        get_string('setting_api_key_desc', 'mod_testhive'),
        '',
    ));

    $ADMIN->add('modsettings', $settings);
}
