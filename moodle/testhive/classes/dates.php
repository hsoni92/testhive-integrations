<?php
// This file is part of Moodle - http://moodle.org/

namespace mod_testhive;

use core\activity_dates;

/**
 * Open and close dates shown on the course page.
 */
class dates extends activity_dates {
    protected function get_dates(): array {
        $timeopen = $this->cm->customdata['timeopen'] ?? null;
        $timeclose = $this->cm->customdata['timeclose'] ?? null;
        $now = time();
        $dates = [];

        if ($timeopen) {
            $dates[] = [
                'dataid' => 'timeopen',
                'label' => get_string($timeopen > $now ? 'activitydate:opens' : 'activitydate:opened', 'core_course'),
                'timestamp' => (int) $timeopen,
            ];
        }
        if ($timeclose) {
            $dates[] = [
                'dataid' => 'timeclose',
                'label' => get_string($timeclose > $now ? 'activitydate:closes' : 'activitydate:closed', 'core_course'),
                'timestamp' => (int) $timeclose,
            ];
        }
        return $dates;
    }
}
