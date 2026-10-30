<?php
// This file is part of Moodle - http://moodle.org/

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

use mod_testhive\local\api_client;

/**
 * Form to create a Testhive question set via the Core wizard.
 */
class mod_testhive_createquestionset_form extends moodleform {
    /** @var bool */
    protected $generating = false;

    /** @var bool */
    protected $readytosave = false;

    /** @var string */
    protected $previewhtml = '';

    /**
     * @param bool $generating generation started; poll until questions exist
     * @param bool $readytosave questions are ready; show save button
     * @param string|null $action form action URL
     * @param array|null $customdata courseid, optional previewhtml
     * @param string $method
     */
    public function __construct(
        bool $generating = false,
        bool $readytosave = false,
        $action = null,
        $customdata = null,
        $method = 'post'
    ) {
        $this->generating = $generating;
        $this->readytosave = $readytosave;
        $this->previewhtml = (string) ($customdata['previewhtml'] ?? '');
        parent::__construct($action, $customdata, $method);
    }

    /**
     * @param bool $ready
     */
    protected function add_wizard_progress_elements(bool $ready): void {
        $mform = $this->_form;
        $progressdefault = $ready
            ? get_string('createquestionset_ready', 'mod_testhive')
            : get_string('createquestionset_generating', 'mod_testhive');
        $mform->addElement('static', 'generatingstatus', get_string('createquestionset_status', 'mod_testhive'),
            html_writer::div($progressdefault, '', ['id' => 'testhive-wizard-progress']));
        $mform->addElement('static', 'wizardpreview', get_string('createquestionset_preview_heading', 'mod_testhive'),
            html_writer::div($this->previewhtml, '', ['id' => 'testhive-wizard-preview']));
        $saveattrs = ['class' => 'testhive-wizard-save'];
        if (!$ready) {
            $saveattrs['disabled'] = 'disabled';
        }
        $mform->addElement('submit', 'finalizebutton', get_string('createquestionset_save', 'mod_testhive'), $saveattrs);
        $mform->addElement('submit', 'cancelwizard', get_string('createquestionset_cancel', 'mod_testhive'));
    }

    protected function definition() {
        $mform = $this->_form;
        $courseid = (int) $this->_customdata['courseid'];

        $mform->addElement('hidden', 'courseid', $courseid);
        $mform->setType('courseid', PARAM_INT);

        if ($this->generating || $this->readytosave) {
            $this->add_wizard_progress_elements($this->readytosave);
            return;
        }

        $mform->addElement('text', 'qsname', get_string('createquestionset_name', 'mod_testhive'), ['size' => 64]);
        $mform->setType('qsname', PARAM_TEXT);
        $mform->addRule('qsname', null, 'required', null, 'client');
        $mform->addHelpButton('qsname', 'createquestionset_name', 'mod_testhive');

        $typeoptions = [];
        foreach (api_client::questionset_type_options() as $type) {
            $typeoptions[$type] = get_string('questionsettype_' . $type, 'mod_testhive');
        }
        $mform->addElement('select', 'answeringmode', get_string('createquestionset_type', 'mod_testhive'), $typeoptions);
        $mform->addHelpButton('answeringmode', 'createquestionset_type', 'mod_testhive');
        $mform->setDefault('answeringmode', 'mcq');

        $mform->addElement('text', 'numquestions', get_string('createquestionset_count', 'mod_testhive'), ['size' => 4]);
        $mform->setType('numquestions', PARAM_INT);
        $mform->addRule('numquestions', null, 'required', null, 'client');
        $mform->setDefault('numquestions', 10);
        $mform->addHelpButton('numquestions', 'createquestionset_count', 'mod_testhive');

        $mform->addElement('select', 'sourcetype', get_string('createquestionset_source_type', 'mod_testhive'), [
            'text' => get_string('createquestionset_source_text', 'mod_testhive'),
            'url' => get_string('createquestionset_source_url', 'mod_testhive'),
        ]);
        $mform->addHelpButton('sourcetype', 'createquestionset_source_type', 'mod_testhive');

        $mform->addElement('textarea', 'sourcevalue', get_string('createquestionset_source_value', 'mod_testhive'),
            ['rows' => 8, 'cols' => 70]);
        $mform->setType('sourcevalue', PARAM_RAW);
        $mform->addRule('sourcevalue', null, 'required', null, 'client');
        $mform->addHelpButton('sourcevalue', 'createquestionset_source_value', 'mod_testhive');

        $this->add_action_buttons(true, get_string('createquestionset_start', 'mod_testhive'));
    }

    /**
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if ($this->generating || $this->readytosave) {
            return $errors;
        }
        $count = (int) ($data['numquestions'] ?? 0);
        if ($count < 1 || $count > 100) {
            $errors['numquestions'] = get_string('createquestionset_count_range', 'mod_testhive');
        }
        $sourcetype = $data['sourcetype'] ?? 'text';
        $value = trim((string) ($data['sourcevalue'] ?? ''));
        if ($value === '') {
            $errors['sourcevalue'] = get_string('required');
        } else if ($sourcetype === 'url') {
            if (!filter_var($value, FILTER_VALIDATE_URL)) {
                $errors['sourcevalue'] = get_string('createquestionset_invalid_url', 'mod_testhive');
            }
        } else if (core_text::strlen($value) > 100000) {
            $errors['sourcevalue'] = get_string('createquestionset_source_too_long', 'mod_testhive');
        }
        return $errors;
    }
}
