<?php

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/testhive/backup/moodle2/restore_testhive_stepslib.php');

class restore_testhive_activity_task extends restore_activity_task {

    public static function define_decode_contents() {
        $contents = [];
        $contents[] = new restore_decode_content('testhive', ['intro'], 'testhive');
        return $contents;
    }

    public static function define_decode_rules() {
        $rules = [];
        $rules[] = new restore_decode_rule('TESTHIVEINDEX', '/mod/testhive/index.php?id=', 'course');
        $rules[] = new restore_decode_rule('TESTHIVEVIEW', '/mod/testhive/view.php?id=', 'course_module');
        return $rules;
    }

    protected function define_my_settings() {
    }

    protected function define_my_steps() {
        $this->add_step(new restore_testhive_activity_structure_step('testhive_structure', 'testhive.xml'));
    }
}
