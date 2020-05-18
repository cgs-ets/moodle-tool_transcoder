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
 * Checker task for transcoder. This task should every minute.
 *
 * @package   tool_transcoder
 * @copyright 2020 Michael Vangelovski 
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_transcoder\task;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/admin/tool/transcoder/locallib.php');

class checker extends \core\task\scheduled_task {

    // Use the logging trait to get some nice, juicy, logging.
    use \core\task\logging_trait;

    /**
     * Get a descriptive name for this task (shown to admins).
     *
     * @return string
     */
    public function get_name() {
        return get_string('checker', 'tool_transcoder');
    }

    /**
     * Clean up task for the plugin.
     */
    public function execute() {
        global $DB;

        $this->log_start("Starting checker task.");

        // Check required settings.
        if (!has_required_settings()) {
            $this->log_finish("Error → Missing required settings. See README.");
            return;
        }

        // Load the settings.
        $config = get_config('tool_transcoder');

        // Look for tasks that have been in-progress for more than 24 hours. These are likely failed conversions.
        $this->log("Cleaning old in-progress tasks.", 1);
        $expiry = time() - $config->processexpiry * 60;

        // If retried 3 times just move to failed state.
        $this->log("Moving transcoding tasks that have been retried $config->retries times to a permanent failed state.", 1);
        $sql = "UPDATE {transcoder_tasks} 
        		   SET status = ?
                 WHERE status = ?
                   AND timestarted <= ?
                   AND retries > ?";
        $params = array();
        $params[] = TRANSCODER_STATUS_FAILED;
        $params[] = TRANSCODER_STATUS_INPROGRESS;
        $params[] = $expiry;
        $params[] = $config->retries;
        $DB->execute($sql, $params);

        // Retry up to 3 times.
        $this->log("Retrying failed/expired transcoding tasks (up to $config->retries times).", 1);
        $sql = "UPDATE {transcoder_tasks} 
        		   SET status = ?, 
        		       retries = (retries + 1)
                 WHERE status = ?
                   AND timestarted <= ?
                   AND retries <= ?";
        $params = array();
        $params[] = TRANSCODER_STATUS_READY;
        $params[] = TRANSCODER_STATUS_INPROGRESS;
        $params[] = $expiry;
        $params[] = $config->retries;
        $DB->execute($sql, $params);

    
        // Check references to transcoded files still exist. 
        // There is a rare chance that a user edits content while a file is transcoding and saves the 
        // content after the transcoding has finished, causing the new html to be overwriten with the old.
        // The following code checks that references to the new source are still present for 30 minutes from the 
        // time that transcoding completed.
        if ( $config->refchecktime ) {
            $refchecktime = time() - ($config->refchecktime * 60);
            $this->log("Checking that references to transcoded files still exist in content for tasks completed in the last $config->refchecktime minutes.", 1);
            $sql = "SELECT *
                      FROM {transcoder_tasks}
                     WHERE status = ?
                       AND timefinished >= ?
                  ORDER BY timequeued ASC, id ASC";
            $tasks = $DB->get_records_sql($sql, array(TRANSCODER_STATUS_COMPLETED, $refchecktime));
            foreach ($tasks as $task) {
                // Load the original and new file records.
                $file = $DB->get_record('files', array('id' => $task->fileid));
                if (empty($file)) {
                    continue;
                }
                $newfile = $DB->get_record('files', array('id' => $task->newfileid));
                if (empty($newfile)) {
                    continue;
                }
                $this->log("File `$file->filename` found. Checking for references.", 2);

                // Find content references to the files.
                $found1 = array_filter(find_filename_in_content($file, $this->get_trace()));
                $found2 = array_filter(find_filename_in_content($newfile, $this->get_trace()));

                // Check whether there are any references to the original and transcoded files.
                if (empty($found1) && empty($found2)) {
                    $this->log("Deleting transcoded files as neither the original file nor the transcoded files were referenced in any content.", 2);
                    delete_newfile_from_task($task);
                    continue;
                }

                // If the transcoded file is not referenced where the original file is, pop a reference into the html.
                $htmltag = explode('/', $file->mimetype)[0];
                foreach ($found1 as $tablecol => $entries) {
                    if (isset($found2[$tablecol])) {
                        $ids = array_diff(array_keys($entries), array_keys($found2[$tablecol]));
                        if ($ids) {
                            $table = explode('__', $tablecol)[2];
                            $col = explode('__', $tablecol)[3];
                            $entries = array_filter($entries, function ($key) use ($ids) { return in_array($key, $ids); }, ARRAY_FILTER_USE_KEY );
                            $this->log("Transcoded file $task->newfileid was missing in $table entries " . json_encode($ids) . ", adding back in.", 2);
                            update_html_source($this->get_trace(), $file, $newfile, $entries, $table, $col, $htmltag);
                        }
                    }
                }
            }
        }
        
        $this->log_finish('Checker task finished.');
        return 0;
    }

}
