<?php

defined('MOODLE_INTERNAL') || die();

class restore_testhive_activity_structure_step extends restore_activity_structure_step {

    protected function define_structure() {
        $paths = [];
        $paths[] = new restore_path_element('testhive', '/activity/testhive');
        return $this->prepare_activity_structure($paths);
    }

    protected function process_testhive($data) {
        global $DB;

        $data = (object) $data;
        $data->course = $this->get_courseid();
        unset($data->id);
        $data->testhive_test_id = null;

        $newitemid = $DB->insert_record('testhive', $data);
        $this->apply_activity_instance($newitemid);
    }

    protected function after_execute() {
        $this->add_related_files('mod_testhive', 'intro', null);
    }
}
