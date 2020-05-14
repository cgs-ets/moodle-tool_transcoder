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
 * Cleaner task for transcoder
 *
 * @package   tool_transcoder
 * @copyright 2020 Michael Vangelovski <michael.vangelovski@gmail.com>
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

        $config = get_config('tool_transcoder');

        $this->log_start("Starting cleaner task.");

        // Look for tasks that have been in-progress for more than 24 hours. These are likely failed conversions.
        $this->log("Cleaning old in-progress tasks.", 1);
        $yesterday = strtotime('-1 day');

        // If retried 3 times just move to failed state.
        $sql = "UPDATE {transcoder_tasks} 
        		   SET status = ?
                 WHERE status = ?
                   AND timestarted <= ?
                   AND retries > ?";
        $params = array();
        $params[] = TRANSCODER_STATUS_FAILED;
        $params[] = TRANSCODER_STATUS_INPROGRESS;
        $params[] = $yesterday;
        $params[] = TRANSCODER_MAX_RETRIES;
        $DB->execute($sql, $params);

        // Retry up to 3 times.
        $sql = "UPDATE {transcoder_tasks} 
        		   SET status = ?, 
        		       retries = (retries + 1)
                 WHERE status = ?
                   AND timestarted <= ?
                   AND retries <= ?";
        $params = array();
        $params[] = TRANSCODER_STATUS_READY;
        $params[] = TRANSCODER_STATUS_INPROGRESS;
        $params[] = $yesterday;
        $params[] = TRANSCODER_MAX_RETRIES;
        $DB->execute($sql, $params);

        // Check references to transcoded files still exist.
        // Get list of completed transcoded files.
        $sql = "SELECT *
                  FROM {transcoder_tasks} 
                 WHERE status = ?
              ORDER BY timequeued ASC, id ASC";
        $tasks = $DB->get_records_sql($sql, array(TRANSCODER_STATUS_COMPLETED));
        foreach ($tasks as $task) {
            $file = $DB->get_record('files', array('id' => $task->fileid));
            if (empty($file)) {
                $this->log("Deleting transcoded files as the original file was not found.", 2);
                $this->delete_newfile_from_task($task);
                continue;
            }

            $newfile = $DB->get_record('files', array('id' => $task->newfileid));
            if (empty($newfile)) {
                continue;
            }

            // Find content references to the files.
            $found1 = array_filter(find_filename_in_content($file));
            $found2 = array_filter(find_filename_in_content($newfile));

            // Check whether there are any references to the original and transcoded files.
            if (empty($found1) && empty($found2)) {
                $this->log("Deleting transcoded files as neither the original file nor the transcoded files were referenced in any content.", 2);
                $this->delete_newfile_from_task($task);
                continue;
            }

            // If the transcoded file is not referenced where the original file is, pop a reference into the html.
            $htmltag = explode('/', $file->mimetype)[0];
            foreach ($found1 as $tablecol => $entries) {
                if (isset($found2[$tablecol])) {
                    $ids = array_diff(array_keys($entries), array_keys($found2[$tablecol]));
                    if ($ids) {
                        $table = explode('__', $tablecol)[1];
                        $col = explode('__', $tablecol)[2];
                        $entries = array_filter($entries, function ($key) use ($ids) { return in_array($key, $ids); }, ARRAY_FILTER_USE_KEY );
                        $this->log("Transcoded file $task->newfileid was missing in $table entries " . json_encode($ids) . ", adding back in.", 1);
                        update_html_source($this->get_trace(), $file, $newfile, $entries, $table, $col, $htmltag);
                    }
                }
            }
        }

        $this->log_finish('Cleaner task finished.');
        return 0;
    }

    private function delete_newfile_from_task($task) {
        global $DB, $CFG;

        $deletefile = $DB->get_record('files', array('id' => $task->newfileid));
        if ($deletefile) {
            // Delete the file record.
            $DB->delete_records('files', array('id' => $deletefile->id));

            $dir = str_replace('\\\\', '\\', $CFG->dataroot) . 
            '\filedir\\' . substr($deletefile->contenthash, 0, 2) . 
            '\\' . substr($deletefile->contenthash, 2, 2) . 
            '\\';

            // Delete the physical files.
            $trashdir = str_replace('\\\\', '\\', $CFG->dataroot) . '\trashdir\\';
            rename($dir . $deletefile->contenthash, $trashdir . $deletefile->contenthash);
        }

        // Delete the transcoder_tasks record.
        $DB->delete_records('transcoder_tasks', array('id' => $task->id));
    }

}
