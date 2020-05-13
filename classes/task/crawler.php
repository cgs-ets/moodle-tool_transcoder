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
 * @copyright 2020 Michael Vangelovski <michael.vangelovski@gmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_transcoder\task;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/admin/tool/transcoder/locallib.php');

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

        $config = get_config('tool_transcoder');

        // Check required settings.
        if (empty($config->concurrencylimit) ||
            empty($config->ffmpegbinary) ||
            empty($config->ffprobebinary) ||
            empty($config->ffmpegtimeout) ||
            empty($config->ffmpegthreads) ||
            empty($config->ffmpegaudiocodec) ||
            empty($config->ffmpegaudiokilobitrate) ||
            empty($config->ffmpegaudiochannels) ) {
                $this->log_start("Error → Missing required settings. See README.");
                return;
        }

        $this->log_start("Starting crawler task.");

        // Look for video and audio files in page content.
        $sql = "SELECT *
                FROM {files}
                WHERE (
                    mimetype = 'video/webm' OR
                    mimetype = 'audio/ogg'
                ) AND (
                    (component = 'mod_page' AND filearea = 'content') OR 
                    (component = 'mod_label' AND filearea = 'intro')
                ) ORDER BY id ASC";
        $files = $DB->get_records_sql($sql);
        foreach ($files as $file) {
            // Skip if a task for this video has already been created.
            if ($task = $DB->get_record('transcoder_tasks', array('fileid' => $file->id))) {
                continue;
            }
            $this->log("Candidate file found → $file->id ($file->filename)", 1);

            $this->log("Searching for content references to $file->filename", 2);
            $results = find_filename_in_content($file);
            $found = array_filter($results);

            // If this video is not referenced anywhere, no need to transcode it.
            if (empty($found)) {
                $this->log("Skipping as file was not referenced in any content.", 2);
                continue;
            }
            $this->log("Content references found. Queuing file for transcoding.", 2);

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
