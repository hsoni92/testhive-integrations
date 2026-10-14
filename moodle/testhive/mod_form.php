<?php
// This file is part of Moodle - http://moodle.org/

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');

use mod_testhive\local\api_client;

class mod_testhive_mod_form extends moodleform_mod {
    protected function definition() {
        global $CFG;

        $mform = $this->_form;

        $mform->addElement('text', 'name', get_string('name'), ['size' => '64']);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');

        $this->standard_intro_elements();

        $options = [];
        if (api_client::is_configured()) {
            try {
                foreach (api_client::list_questionsets() as $item) {
                    $options[$item['id']] = $item['name'];
                }
            } catch (moodle_exception $e) {
                $options[''] = $e->getMessage();
            }
        }
        if (count($options) === 0) {
            $options[''] = get_string('error_noquestionsets', 'mod_testhive');
        }

        $mform->addElement('select', 'questionset', get_string('questionset', 'mod_testhive'), $options);
        $mform->addHelpButton('questionset', 'questionset', 'mod_testhive');
        $mform->addRule('questionset', null, 'required', null, 'client');

        $mform->addElement('header', 'availabilityhdr', get_string('availability', 'mod_testhive'));
        $mform->addElement('date_time_selector', 'timeopen', get_string('timeopen', 'mod_testhive'));
        $mform->addElement('date_time_selector', 'timeclose', get_string('timeclose', 'mod_testhive'));
        $mform->addRule('timeclose', null, 'required', null, 'client');

        $mform->addElement('text', 'grade', get_string('grademax', 'grades'), ['size' => '5']);
        $mform->setType('grade', PARAM_INT);
        $mform->setDefault('grade', 100);
        $mform->addRule('grade', null, 'required', null, 'client');

        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }

    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if (!api_client::is_configured()) {
            $errors['questionset'] = get_string('error_apinotconfigured', 'mod_testhive');
        }
        if (!empty($data['timeopen']) && !empty($data['timeclose']) && $data['timeclose'] <= $data['timeopen']) {
            $errors['timeclose'] = get_string('error_apirequest', 'mod_testhive', 'Close time must be after open time');
        }
        return $errors;
    }
}
