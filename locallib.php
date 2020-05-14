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
 * @package   tool_transcoder
 * @copyright 2020 Michael Vangelovski <michael.vangelovski@gmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();


require_once($CFG->dirroot . '/admin/tool/transcoder/vendor/autoload.php');
use FFMpeg\FFMpeg;
use FFMpeg\Format\Video\X264;
use FFMpeg\Format\Audio\Mp3;
use PHPHtmlParser\Dom;


define('TRANSCODER_STATUS_READY', 0);
define('TRANSCODER_STATUS_INPROGRESS', 1);
define('TRANSCODER_STATUS_COMPLETED', 2);
define('TRANSCODER_STATUS_FAILED', 3);

/**
 * Searches HTML content for references to a file.
 *
 * @param stdClass $file The file record to search for.
 * @param progress_trace $trace The trace to use to log messages.
 * @param array Array of records with matches.
 */
function find_filename_in_content($file, $trace) {
    $config = get_config('tool_transcoder');
    $searchareas = explode(',', $config->contentareas);

    $matches = array();
    foreach ($searchareas as $contentarea) {
        $component = explode('__', $contentarea)[0];
        // Only look at in the content area that the original file was added to. If the file has 
        // been copied to another area a separate file record will exist for it and it will be
        // checked independently.
        if ($component != $file->component) {
            continue;
        }
        $table = explode('__', $contentarea)[1];
        $col = explode('__', $contentarea)[2];
        $trace->output("Looking for uses within component $component, table $table, col $col.", 2);
        $key = $component . '__' .$table . '__' . $col;
        $matches[$key] = find_filename_in_table_col($file, $table, $col);
    }

    return $matches;
}

function find_filename_in_table_col($file, $table, $col) {
    global $DB;

    $sql = "SELECT * FROM {{$table}} WHERE " . $DB->sql_like($col, ':filename');
    $params = array('filename' => '%' . $file->filename . '%');
    $matches = $DB->get_records_sql($sql, $params);

    return $matches;
}

/**
 * The description should be first, with asterisks laid out exactly
 * like this example. If you want to refer to a another function,
 *
 * @param progress_trace $trace The trace to use to log messages.
 * @param stdClass $file The original file record.
 * @param stdClass $newfile The new file record.
 * @param array $entries Content records such as pages and labels.
 * @param array $table The mod table, e.g. page.
 * @param array $htmlcol The name of the column containing the html content to modify.
 * @param array $htmltag The type of tag (e.g. video/audio) that needs to be modified.
 */
function update_html_source($trace, $file, $newfile, $entries, $table, $htmlcol, $htmltag) {
    global $DB, $CFG;

    // Look for current references to this file in entry content.
    foreach ($entries as $entry) {
        $dom = new Dom;
        $dom->load($entry->$htmlcol);
        // Look for the video/audio in the entry content.
        $tags = $dom->find($htmltag);
        foreach($tags as $tag) {
            // Check whether the file in the entry content is the one we've just transcoded.
            if (strpos($tag, $file->filename) !== false) {
                // Get source elements and remove references to previously transoded files.
                $sources = $tag->find('source');
                foreach ($sources as $source) {
                    $src = str_replace('@@PLUGINFILE@@/', '', $source->getAttribute('src'));
                    if (strpos($src, '_transcoder_') !== false) {
                        $trace->output("Removing an existing transcoded source → $src", 2);
                        $source->delete();
                        unset($source);
                        // Delete the file record from the db.
                        $deletefile = $DB->get_record('files', array('contextid' => $file->contextid, 'filename' => $src));
                        if ($deletefile) {
                            $DB->delete_records('files', array('id' => $deletefile->id));
                        }
                    }
                }

                // Use a throw away dom to create a new source element.
                $trace->output("Adding a new source to the $htmltag element → $newfile->filename", 2);
                $tempdom = new Dom;
                $tempdom->loadStr('<source>');
                $newsource = $tempdom->find('source')[0];
                $newsource->setAttribute('src', "@@PLUGINFILE@@/$newfile->filename");

                // Get the original source element of the video/audio tag.
                $originalsource = $tag->find('source')[0];

                // Insert the new source into the video/audio element and discard the temp dom.
                $tag->insertAfter($newsource, $originalsource->id());
                unset($tempdom);

                // Update the entry content with the new html.
                $entry->$htmlcol = $dom->outerHtml;
                $DB->update_record($table, $entry);

                // Get the course id.
                $courseid = 0;
                if (isset($entry->course)) {
                    $courseid = $entry->course;
                }
                if ($table == 'course') {
                    $courseid = $entry->id;
                }

                // Attempt to log a URL to the content for convenience.
                $moduleid = $DB->get_field('modules', 'id', array('name' => $table));
                if ($moduleid && $courseid) {
                    $coursemoduleid = $DB->get_field('course_modules', 'id', array('course' => $entry->course, 'instance' => $entry->id, 'module' => $moduleid));
                    $entryurl = $CFG->wwwroot . '/mod/' . $table . '/view.php?id=' . $coursemoduleid;
                    $trace->output("Updated html for $table entry $entry->id `$entry->name` → $entryurl", 2);
                } else {
                    $trace->output("Updated html for $table entry $entry->id", 2);
                }

                // Attempt to rebuild course cache so that new sources are displayed. https://moodle.org/mod/forum/discuss.php?d=191773.
                if ($courseid) {
                    $trace->output("Rebuilding cache for course $entry->course", 2);
                    rebuild_course_cache($courseid, true);
                }

            }
        }
    }
}


function transcode_video_using_ffmpeg($dir, $filename, $newphysicalname) {
	$config = get_config('tool_transcoder');

    // Create an instance of FFMpeg.
    $ffmpeg = FFMpeg::create([
        'ffmpeg.binaries'  => $config->ffmpegbinary, // the path to the FFMpeg binary.
        'ffprobe.binaries' => $config->ffprobebinary, // the path to the FFProbe binary.
        'timeout'          => $config->ffmpegtimeout, // the timeout for the underlying process.
        'ffmpeg.threads'   => $config->ffmpegthreads, // the number of threads that FFMpeg should use.
    ]);

    // Open the video providing the absolute path.
    $video = $ffmpeg->open($dir . $filename);

    // Configure the new mp4 format (x264).
    $format = new X264();

    // Fix for error "Encoding failed : Can't save to X264".
    // See: https://github.com/PHP-FFMpeg/PHP-FFMpeg/issues/310.
    $format->setAudioCodec($config->ffmpegaudiocodec); // Default setting is libmp3lame.

    // Additional parameters.
    $params = json_decode($config->ffmpegadditionalparamsvideo);
    if ($params) {
	    $commands = array();
	    foreach ($params as $key => $value) {
	        $commands[] = $key;
	        $commands[] = $value;
	    }
	    $format->setAdditionalParameters($commands);
	}

    // Save the video in the same directory with the new format.
    $video->save($format, $dir . $newphysicalname);
}


function transcode_audio_using_ffmpeg($dir, $filename, $newphysicalname) {
	$config = get_config('tool_transcoder');

    // Create an instance of FFMpeg.
    $ffmpeg = FFMpeg::create([
        'ffmpeg.binaries'  => $config->ffmpegbinary, // the path to the FFMpeg binary.
        'ffprobe.binaries' => $config->ffprobebinary, // the path to the FFProbe binary.
        'timeout'          => $config->ffmpegtimeout, // the timeout for the underlying process.
        'ffmpeg.threads'   => $config->ffmpegthreads, // the number of threads that FFMpeg should use.
    ]);

    // Open the audio file using the absolute path.
    $audio = $ffmpeg->open($dir . $filename);
    
    // Configure an instance of the Mp3 format. Default and only available codec is libmp3lame.
    $format = new Mp3();
    $format->setAudioKiloBitrate($config->ffmpegaudiokilobitrate);
    $format->setAudioChannels($config->ffmpegaudiochannels);

    // Save the video in the same directory with the new format.
    $audio->save($format, $dir . $newphysicalname);
}

function check_required_fields() {
    $config = get_config('tool_transcoder');

    if (empty($config->concurrencylimit) ||
        empty($config->ffmpegbinary) ||
        empty($config->ffprobebinary) ||
        empty($config->ffmpegtimeout) ||
        empty($config->ffmpegthreads) ||
        empty($config->ffmpegaudiocodec) ||
        empty($config->ffmpegaudiokilobitrate) ||
        empty($config->ffmpegaudiochannels) ||
        empty($config->mimetypes) ||
        empty($config->contentareas) ||
        empty($config->processexpiry) ||
        empty($config->retries)
    ) {
        return false;
    }

    return true;
}