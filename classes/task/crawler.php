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
 * This is the main scheduled task that queues videos for conversion.
 * The actual conversion effort is divided into independent adhoc tasks.
 *
 * @package   tool_transcoder
 * @copyright 2020 Michael Vangelovski 
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_transcoder\task;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/admin/tool/transcoder/locallib.php');
require_once($CFG->dirroot . '/lib/adminlib.php');

class crawler extends \core\task\scheduled_task {

    // Use the logging trait to get some nice, juicy, logging.
    use \core\task\logging_trait;

    /**
     * Get a descriptive name for this task (shown to admins).
     *
     * @return string
     */
    public function get_name() {
        return get_string('crawler', 'tool_transcoder');
    }

    /**
     * Crawls through files and content to find video and audio files requiring conversion.
     */
    public function execute() {
        global $DB;

        $this->log_start("Starting crawler task.");

        // Check required settings.
        if (!has_required_settings()) {
            $this->log_finish("Error → Missing required settings. See README.");
            return;
        }

        // Load the settings.
        $config = get_config('tool_transcoder');

        $timefrom = $config->filesfromtime ? $config->filesfromtime : 0;
        $this->log("Looking for files created after $timefrom (unix timestamp).", 1);

        // Limit file search to components with HTML fields we'll be checking.
        $searchareas = explode(',', $config->contentareas);
        $componentsqlarr = array();
        $componentparams = array();
        foreach ($searchareas as $contentarea) {
            $componentsqlarr[] = 'component = ?';
            $componentparams[] = explode('__', $contentarea)[0];
        }
        $componentsql = implode(' OR ', $componentsqlarr);

        // Get selected mimetypes.
        $mimetypes = explode(',', $config->mimetypes);
        list($mimesql, $mimeparams) = $DB->get_in_or_equal($mimetypes);

        // HEIC files do not have a mimetype in mdl_files therefore we have to use the filename.
        $sqlmimebyfilename = '';
        if (in_array('image/heic', $mimetypes)) {
            $sqlmimebyfilename .= " OR filename LIKE '%.heic' ";
            $sqlmimebyfilename .= " OR filename LIKE '%.HEIC' ";
        }

        // Build the SQL.
        $sql = "SELECT *
                FROM {files}
                WHERE timemodified > ?
                AND ($componentsql) 
                AND (
                    (mimetype $mimesql) 
                    $sqlmimebyfilename
                )
                ORDER BY id ASC";
        $params = array();
        $params[] = $timefrom - 60*60*24; // Look back 24 hours just in case.
        $params = array_merge($params, $componentparams, $mimeparams);

        // Update filesfromtime setting to now so that we only crawl new files.
        // This is intentionally done before querying the file store to ensure no file is ever missed.
        set_config('filesfromtime', time(), 'tool_transcoder');

        // Crawl the file store.
        $files = $DB->get_records_sql($sql, $params);
        foreach ($files as $file) {
            // Skip if a task for this video has already been created.
            if ($task = $DB->get_record('transcoder_tasks', array('fileid' => $file->id))) {
                continue;
            }
            $this->log("Candidate file found → $file->id ($file->filename)", 1);

            $this->log("Searching for content references to $file->filename", 2);
            $results = array_filter(find_filename_in_content($file, $this->get_trace()));

            // If this video is not referenced anywhere, no need to transcode it.
            if (empty($results)) {
                //$this->log("Skipping as file was not referenced in any content.", 2);
                //continue;
                $this->log("File was not referenced in any content but transcoding in case used in attachments.", 2);
            } else {
                $this->log("Content references found. Queuing file for transcoding.", 2);
            }

            // Add a new task to the custom table.
            $DB->insert_record('transcoder_tasks', array('fileid' => $file->id, 'timequeued' => time()));
        }

        // If using Moodle's cron system, queue up the next set of adhoc tasks.
        if ( ! $config->disablecron) {
            $this->log("Using Moodle's cron system. Queuing next set of adhoc transcoder tasks.", 1);
            // Get number of adhoc tasks already queued.
            $count = $DB->count_records('task_adhoc', array('classname' => '\tool_transcoder\task\transcoder'));
            // Get waiting tasks.
            $tasks = $DB->get_records('transcoder_tasks', array('status' => TRANSCODER_STATUS_READY), 'timequeued ASC');
            foreach ($tasks as $task) {
                if ($count >= $config->concurrencylimit) {
                    $this->log("Concurrency limit of reached. $count transcoding adhoc task(s) currently queued.", 1);
                    break;
                }
                // Create an adhoc task to handle the transcoding for this video.
                $this->log("Queuing adhoc tasks for transcoder_tasks $task->id.", 1);
                $transcodetask = new \tool_transcoder\task\transcoder();
                $transcodetask->set_custom_data($task->id);
                $transcodetask->set_component('tool_transcoder');
                \core\task\manager::queue_adhoc_task($transcodetask);
                $count++;
            }
        }

        $this->log_finish('Crawler task finished.');
        return 0;
    }

}
