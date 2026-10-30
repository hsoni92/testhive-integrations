<?php

defined('MOODLE_INTERNAL') || die();

class backup_testhive_activity_structure_step extends backup_activity_structure_step {

    protected function define_structure() {
        $testhive = new backup_nested_element('testhive', ['id'], [
            'course',
            'name',
            'intro',
            'introformat',
            'questionset',
            'timeopen',
            'timeclose',
            'grade',
            'proctorenabled',
            'sessionduration',
            'timebound',
            'instructions',
            'timemodified',
        ]);

        $testhive->set_source_table('testhive', ['id' => backup::VAR_ACTIVITYID]);
        $testhive->annotate_files('mod_testhive', 'intro', null);

        return $this->prepare_activity_structure($testhive);
    }
}
