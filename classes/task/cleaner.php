<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Cleaner task for transcoder. This task should run daily.
 *
 * @package   tool_transcoder
 * @copyright 2020 Michael Vangelovski 
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_transcoder\task;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/admin/tool/transcoder/locallib.php');

class cleaner extends \core\task\scheduled_task {

    // Use the logging trait to get some nice, juicy, logging.
    use \core\task\logging_trait;

    /**
     * Get a descriptive name for this task (shown to admins).
     *
     * @return string
     */
    public function get_name() {
        return get_string('cleaner', 'tool_transcoder');
    }

    /**
     * Clean up task for the plugin.
     */
    public function execute() {
        global $DB;

        $this->log_start("Starting cleaner task.");

        // Check required settings.
        if (!has_required_settings()) {
            $this->log_finish("Error → Missing required settings. See README.");
            return;
        }

        // Load the settings.
        $config = get_config('tool_transcoder');

        // Delete transcoded files if original file deleted.
        $sql = "SELECT *
                  FROM {transcoder_tasks}
                 WHERE status = ?
              ORDER BY timequeued ASC, id ASC";
        $tasks = $DB->get_records_sql($sql, array(TRANSCODER_STATUS_COMPLETED));
        foreach ($tasks as $task) {
            if ( ! $DB->record_exists('files', array('id' => $task->fileid))) {
                $this->log("Deleting transcoded files as the original file was not found.", 2);
                delete_newfile_from_task($task);
                continue;
            }
        }

        $this->log_finish('Cleaner task finished.');
        return 0;
    }

}
