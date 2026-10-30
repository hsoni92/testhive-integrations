<?php

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/testhive/backup/moodle2/backup_testhive_stepslib.php');

class backup_testhive_activity_task extends backup_activity_task {

    protected function define_my_settings() {
    }

    protected function define_my_steps() {
        $this->add_step(new backup_testhive_activity_structure_step('testhive_structure', 'testhive.xml'));
    }

    public static function encode_content_links($content) {
        global $CFG;

        $base = preg_quote($CFG->wwwroot . '/mod/testhive', '#');
        $pattern = '#(' . $base . '/index\.php\?id=)([0-9]+)#';
        $content = preg_replace($pattern, '$@TESTHIVEINDEX*$2@$', $content);
        $pattern = '#(' . $base . '/view\.php\?id=)([0-9]+)#';
        $content = preg_replace($pattern, '$@TESTHIVEVIEW*$2@$', $content);

        return $content;
    }
}
