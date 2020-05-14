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
 * Task to transcode a video or audio file.
 *
 * @package   tool_transcoder
 * @copyright 2020 Michael Vangelovski <michael.vangelovski@gmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_transcoder\task;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/admin/tool/transcoder/locallib.php');

class transcoder extends \core\task\adhoc_task {

    // Use the logging trait to get some nice, juicy, logging.
    use \core\task\logging_trait;

    /**
     * @var stdClass config for this plugin
     */
    protected $config;


    /**
     * Get a descriptive name for this task (shown to admins).
     *
     * @return string
     */
    public function get_name() {
        return get_string('transcoder', 'tool_transcoder');
    }

    /**
     * Performs the transcoding.
     */
    public function execute() {
        global $DB, $CFG;

        $this->log_start("Starting transcode task.");

        // Check required settings.
        if (!check_required_fields()) {
            $this->log_finish("Error → Missing required settings. See README.");
            return;
        }

        // Load the settings.
        $this->config = get_config('tool_transcoder');

        // Check whether we are using Moodle's cron system and transcoding a specific adhoc task
        // or whether we are running this from cli and transcoding the next in line.
        $taskid = $this->get_custom_data();
        if (empty($taskid) && $this->config->disablecron) {
            // Check concurrency limit.
            $count = $DB->count_records('transcoder_tasks', array('status' => TRANSCODER_STATUS_INPROGRESS));
            if ($count >= $this->config->concurrencylimit) {
                $this->log_finish("Exiting → $count transcoding task(s) currently in-progress. Concurrency limit is $this->config->concurrencylimit.");
                return;
            }
            $sql = "SELECT id
                      FROM {transcoder_tasks} 
                     WHERE status = ? 
                  ORDER BY timequeued ASC, id ASC";
            $taskid = $DB->get_field_sql($sql, array(TRANSCODER_STATUS_READY), IGNORE_MULTIPLE);
        }

        // Check if there is a task id to process.
        if (empty($taskid)) {
            $this->log_finish('Exiting → No tasks to process. If you are running this from cli, ensure that the disablecron setting is selected.');
            return;
        }

        // Load the task details.
        $task = $DB->get_record('transcoder_tasks', array('id' => $taskid));
        if (empty($task)) {
            $this->log_finish("Exiting → Failed to find transcoder task record $taskid.");
            return;
        }

        // Double check the status as this could have been called from cron and cli.
        if ($task->status == TRANSCODER_STATUS_INPROGRESS) {
            $this->log_finish("Exiting → Task $taskid is already in-progress.");
            return;
        }
        if ($task->status == TRANSCODER_STATUS_COMPLETED) {
            $this->log_finish("Exiting → Task $taskid is already completed.");
            return;
        }

        // Update the status to in-progress.
        $task->status = TRANSCODER_STATUS_INPROGRESS;
        $task->timestarted = time();
        $DB->update_record('transcoder_tasks', $task);

        // Load the file record.
        $file = $DB->get_record('files', array('id' => $task->fileid));
        if (empty($file)) {
            $this->log_finish("Exiting → Failed to find file record $task->fileid");
            return;
        }

        // At this point, we'll be going ahead with the transcoding.
        // We may need a lot of memory here.
        \core_php_time_limit::raise();
        raise_memory_limit(MEMORY_HUGE);

        // Extract directory and filename of the permanent file.
        $dir = str_replace('\\\\', '\\', $CFG->dataroot) . 
                    '\filedir\\' . substr($file->contenthash, 0, 2) . 
                    '\\' . substr($file->contenthash, 2, 2) . 
                    '\\';
        $physicalpath = $dir . $file->contenthash;
        $this->log_start("Transcoding $file->filename → $physicalpath");

        // Transcode the file.
        $htmltag = 'video';
        $newmimetype = 'video/mp4';
        $fileextension = '.mp4';
        $datestamp = date("YmdHis", time());
        $tempphysicalname = $file->contenthash . '_transcoder_' . $datestamp . $fileextension; // File ext required for ffmpeg
        switch ($file->mimetype) {
          case "video/webm":
            transcode_video_using_ffmpeg($dir, $file->contenthash, $tempphysicalname);
            break;
          case "audio/ogg":
            $htmltag = 'audio';
            $newmimetype = 'audio/mp3';
            $fileextension = '.mp3';
            $tempphysicalname = $file->contenthash . '_transcoder_' . $datestamp . $fileextension; // File ext required for ffmpeg
            transcode_audio_using_ffmpeg($dir, $file->contenthash, $tempphysicalname);
            break;
          default:
            $this->log("Exiting → Unhandled mimetype $file->mimetype.", 1);
            return;
        }
        $this->log('Transcoding finished.', 1);

        // Set up a new file record for the db. Remove the id so that a new record is inserted.
        $newfile = clone $file;
        unset($newfile->id);
        // Generate a content hash for the file. To keep the file contained within the same directory structure
        // as the original file replace the first 4 chars with the first 4 chars of the original contenthash.
        $contenthash = sha1_file($dir . $tempphysicalname);
        $contenthash = substr_replace($contenthash, substr($file->contenthash, 0, 4), 0, 4);
        $newfile->contenthash = $contenthash;
        // Rename the file to the new content hash value.
        $this->log("Renaming the transcoded file to the contenthash → $newfile->contenthash", 1);
        rename($dir . $tempphysicalname, $dir . $newfile->contenthash);
        // Set other file values.
        $info = pathinfo($file->filename);
        $newfile->filename = $newfile->source = $info['filename'] . '_transcoder_' . $datestamp . $fileextension;
        $contentpath = "/$file->contextid/$file->component/$file->filearea/$file->itemid/$newfile->filename";
        $newfile->pathnamehash = sha1($contentpath);
        $newfile->filesize = filesize($dir . $newfile->contenthash);
        $newfile->mimetype = $newmimetype;
        $newfile->timecreated = time();
        $newfile->timemodified = time();

        // Save the file to the db.
        $this->log('Adding a new file record to the db.', 1);
        $task->newfileid = $DB->insert_record('files', $newfile);
        $DB->update_record('transcoder_tasks', $task);

        // Update the HTML references to the file.
        $this->log('Searching for HTML references to update.', 1);
        $found = array_filter(find_filename_in_content($file, $this->get_trace()));
        foreach ($found as $tablecol => $entries) {
            $table = explode('__', $tablecol)[1];
            $col = explode('__', $tablecol)[2];
            $this->log("Adding transcoded source $task->newfileid into $table entries " . json_encode(array_keys($entries)), 1);
            update_html_source($this->get_trace(), $file, $newfile, $entries, $table, $col, $htmltag);
        }

        $task->status = TRANSCODER_STATUS_COMPLETED;
        $task->timefinished = time();
        $DB->update_record('transcoder_tasks', $task);

        $timeelapsed = $task->timefinished - $task->timestarted;
        $this->log_finish("Transcode task finished. Time elapsed → $timeelapsed seconds");
        return 0;
    }

}