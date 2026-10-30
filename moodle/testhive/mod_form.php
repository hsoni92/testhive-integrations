<?php
// This file is part of Moodle - http://moodle.org/

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');

use mod_testhive\local\api_client;

class mod_testhive_mod_form extends moodleform_mod {
    protected function definition() {
        global $OUTPUT;

        $mform = $this->_form;

        $mform->addElement('text', 'name', get_string('name'), ['size' => '64']);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');

        $this->standard_intro_elements();

        $options = [];
        $loaderror = '';
        if (!api_client::is_configured()) {
            $loaderror = get_string('error_apinotconfigured', 'mod_testhive');
        } else {
            try {
                foreach (api_client::list_questionsets() as $item) {
                    $options[$item['id']] = api_client::questionset_label($item);
                }
            } catch (moodle_exception $e) {
                $loaderror = $e->getMessage();
            }
        }
        if (!empty($this->current->questionset) && !array_key_exists($this->current->questionset, $options)) {
            $options[$this->current->questionset] = $this->current->questionset;
        }

        if ($loaderror !== '' && count($options) === 0) {
            $mform->addElement('static', 'questionsetwarning', get_string('questionset', 'mod_testhive'),
                $OUTPUT->notification($loaderror, 'warning'));
        } else if (count($options) === 0) {
            $mform->addElement('static', 'questionsetwarning', get_string('questionset', 'mod_testhive'),
                $OUTPUT->notification(get_string('error_noquestionsets', 'mod_testhive'), 'warning'));
        } else {
            $mform->addElement('autocomplete', 'questionset', get_string('questionset', 'mod_testhive'), $options, [
                'noselectionstring' => get_string('questionset_noselection', 'mod_testhive'),
            ]);
            $mform->addHelpButton('questionset', 'questionset', 'mod_testhive');
            $mform->addRule('questionset', null, 'required', null, 'client');
            if ($loaderror !== '') {
                $mform->addElement('static', 'questionsetwarning', '', $OUTPUT->notification($loaderror, 'warning'));
            }
        }

        if (api_client::is_configured() && has_capability('mod/testhive:addinstance', $this->get_context())) {
            $formcourseid = testhive_mod_form_course_id($this->_course, $this->_cm ?? null, $this->get_context());
            if ($formcourseid > 0) {
                $createurl = new moodle_url('/mod/testhive/createquestionset.php', ['courseid' => $formcourseid]);
                $link = html_writer::link($createurl, get_string('createquestionset_link', 'mod_testhive'),
                    ['target' => '_blank']);
                $mform->addElement('static', 'createquestionsetlink', '', $link);
                $mform->addHelpButton('createquestionsetlink', 'createquestionset_link', 'mod_testhive');
            }
        }

        $mform->addElement('header', 'availabilityhdr', get_string('availability', 'mod_testhive'));
        $mform->addElement('date_time_selector', 'timeopen', get_string('timeopen', 'mod_testhive'));
        $mform->addHelpButton('timeopen', 'timeopen', 'mod_testhive');
        $mform->setDefault('timeopen', time());
        $mform->addElement('date_time_selector', 'timeclose', get_string('timeclose', 'mod_testhive'));
        $mform->addHelpButton('timeclose', 'timeclose', 'mod_testhive');
        $mform->setDefault('timeclose', time() + WEEKSECS);

        $mform->addElement('header', 'testsettingshdr', get_string('testsettings', 'mod_testhive'));
        $mform->addElement('advcheckbox', 'proctorenabled', get_string('proctorenabled', 'mod_testhive'));
        $mform->addHelpButton('proctorenabled', 'proctorenabled', 'mod_testhive');
        $mform->addElement('text', 'sessionduration', get_string('sessionduration', 'mod_testhive'), ['size' => '6']);
        $mform->setType('sessionduration', PARAM_INT);
        $mform->setDefault('sessionduration', 0);
        $mform->addHelpButton('sessionduration', 'sessionduration', 'mod_testhive');
        $mform->addElement('advcheckbox', 'timebound', get_string('timebound', 'mod_testhive'));
        $mform->addHelpButton('timebound', 'timebound', 'mod_testhive');
        $mform->addElement('textarea', 'instructions', get_string('instructions', 'mod_testhive'), ['rows' => 4, 'cols' => 60]);
        $mform->setType('instructions', PARAM_TEXT);
        $mform->addHelpButton('instructions', 'instructions', 'mod_testhive');

        $this->standard_grading_coursemodule_elements();

        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }

    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if (!api_client::is_configured()) {
            $errors['questionset'] = get_string('error_apinotconfigured', 'mod_testhive');
        } else if (empty($data['questionset'])) {
            $errors['questionset'] = get_string('error_noquestionsets', 'mod_testhive');
        }
        if (!empty($data['timeopen']) && !empty($data['timeclose']) && $data['timeclose'] <= $data['timeopen']) {
            $errors['timeclose'] = get_string('error_timeclose', 'mod_testhive');
        }
        if (isset($data['sessionduration']) && (int) $data['sessionduration'] < 0) {
            $errors['sessionduration'] = get_string('error_sessionduration', 'mod_testhive');
        }
        return $errors;
    }
}
