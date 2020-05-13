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
define('TRANSCODER_MAX_RETRIES', 3);


/**
 * Searches HTML content for references to a file.
 * Tables with HTML fields to check:
 *   assign, intro = Assignment Description
 *   book, intro = Book Introduction
 *   book_chapters, content = Book Chapter
 *   course, summary = Course Summary
 *   folder, intro = Folder Introduction
 *   forum, intro = Forum Introduction
 *   label, intro = Label Content
 *   page, intro = Page Introduction
 *   page, content = Page Content
 *   question, questiontext = Quiz Questions
 *   quiz, intro = Quiz Introduction
 *   url, intro = URL Introduction
 *   wiki, intro = Wiki Introduction
 *   wiki_pages, cachedcontent = Wiki Pages
 *
 * @param stdClass $file The file record to search for.
 * @param array Array of records with matches.
 */
function find_filename_in_content($file) {
    $modcols = array(
        array('mod' => 'assign', 'col' => 'intro'),
        array('mod' => 'book', 'col' => 'intro'),
        array('mod' => 'book_chapters', 'col' => 'content'),
        array('mod' => 'course', 'col' => 'summary'),
        array('mod' => 'folder', 'col' => 'intro'),
        array('mod' => 'forum', 'col' => 'intro'),
        array('mod' => 'label', 'col' => 'intro'),
        array('mod' => 'page', 'col' => 'intro'),
        array('mod' => 'page', 'col' => 'content'),
        array('mod' => 'question', 'col' => 'questiontext'),
        array('mod' => 'quiz', 'col' => 'intro'),
        array('mod' => 'url', 'col' => 'intro'),
        array('mod' => 'wiki', 'col' => 'intro'),
        array('mod' => 'wiki_pages', 'col' => 'cachedcontent'),
    );

    $matches = array();
    foreach ($modcols as $modcol) {
        $mod = $modcol['mod'];
        $col = $modcol['col'];
        $key = $mod . '_' . $col;
        $matches[$key] = find_filename_in_mod_col($file, $mod, $col);
    }

    /*// Pages
    $sql = 'SELECT * FROM {page} WHERE ' . $DB->sql_like('content', ':filename1') . ' OR ' . $DB->sql_like('intro', ':filename2');
    $params = array(
        'filename1' => '%' . $file->filename . '%'
        'filename2' => '%' . $file->filename . '%'
    );
    $pages = $DB->get_records_sql($sql, $params);

    // Labels
    $sql = 'SELECT * FROM {label} WHERE ' . $DB->sql_like('intro', ':filename');
    $params = array('filename' => '%' . $file->filename . '%');
    $labels = $DB->get_records_sql($sql, $params);

    return array($pages, $labels);*/

    var_export($matches);

    return $matches;
}

function find_filename_in_mod_col($file, $mod, $col) {
    global $DB;

    $sql = "SELECT * FROM {{$mod}} WHERE " . $DB->sql_like($col, ':filename');
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
 * @param array $mod The mod type, e.g. page.
 * @param array $htmlcol The name of the column containing the html content to modify.
 * @param array $htmltag The type of tag (e.g. video/audio) that needs to be modified.
 */
function update_html_source($trace, $file, $newfile, $entries, $mod, $htmlcol, $htmltag) {
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
                $DB->update_record($mod, $entry);

                // Log URL of content
                $moduleid = $DB->get_field('modules', 'id', array('name' => $mod));
                $coursemoduleid = $DB->get_field('course_modules', 'id', array('course' => $entry->course, 'instance' => $entry->id, 'module' => $moduleid));
                $entryurl = $CFG->wwwroot . '/mod/' . $mod . '/view.php?id=' . $coursemoduleid;
                $trace->output("Updated html for `$entry->name` → $entryurl", 2);

                // Rebuild course cache so that new content is displayed. https://moodle.org/mod/forum/discuss.php?d=191773
                $trace->output("Rebuilding cache for course $entry->course", 2);
                rebuild_course_cache($entry->course, true);
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