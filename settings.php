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
 * Plugin settings and presets.
 *
 * @package   tool_transcoder
 * @copyright 2020 Michael Vangelovski <michael.vangelovski@gmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();


if ($hassiteconfig) {

	// Add a new category under tools.
    $ADMIN->add('tools',
        new admin_category('tool_transcoder', get_string('pluginname', 'tool_transcoder')));

    $settings = new admin_settingpage('tool_transcoder_settings', new lang_string('settings', 'tool_transcoder'),
        'moodle/site:config', false);

    // Add the settings page.
    $ADMIN->add('tool_transcoder', $settings);

    $settings->add(new admin_setting_configcheckbox('tool_transcoder/disablecron',
        get_string('disablecron', 'tool_transcoder'),
        get_string('disablecron_desc', 'tool_transcoder'), 1));

    $settings->add(new admin_setting_configtext('tool_transcoder/concurrencylimit',
        get_string('concurrencylimit', 'tool_transcoder'),
        get_string('concurrencylimit_desc', 'tool_transcoder'), 1, PARAM_INT));

    $settings->add(new admin_setting_configtext('tool_transcoder/ffmpegbinary',
        get_string('ffmpegbinary', 'tool_transcoder'),
        get_string('ffmpegbinary_desc', 'tool_transcoder'), '', PARAM_RAW));

    $settings->add(new admin_setting_configtext('tool_transcoder/ffprobebinary',
        get_string('ffprobebinary', 'tool_transcoder'),
        get_string('ffprobebinary_desc', 'tool_transcoder'), '', PARAM_RAW));

    $settings->add(new admin_setting_configtext('tool_transcoder/ffmpegtimeout',
        get_string('ffmpegtimeout', 'tool_transcoder'),
        get_string('ffmpegtimeout_desc', 'tool_transcoder'), 3600, PARAM_INT));

    $settings->add(new admin_setting_configtext('tool_transcoder/ffmpegthreads',
        get_string('ffmpegthreads', 'tool_transcoder'),
        get_string('ffmpegthreads_desc', 'tool_transcoder'), 12, PARAM_INT));

    $settings->add(new admin_setting_configtext('tool_transcoder/ffmpegaudiocodec',
        get_string('ffmpegaudiocodec', 'tool_transcoder'),
        get_string('ffmpegaudiocodec_desc', 'tool_transcoder'), 'libmp3lame', PARAM_RAW));

    $settings->add(new admin_setting_configtext('tool_transcoder/ffmpegadditionalparamsvideo',
        get_string('ffmpegadditionalparamsvideo', 'tool_transcoder'),
        get_string('ffmpegadditionalparamsvideo_desc', 'tool_transcoder'), '', PARAM_RAW));

    $settings->add(new admin_setting_configtext('tool_transcoder/ffmpegaudiokilobitrate',
        get_string('ffmpegaudiokilobitrate', 'tool_transcoder'),
        get_string('ffmpegaudiokilobitrate_desc', 'tool_transcoder'), 48000, PARAM_INT));

    $settings->add(new admin_setting_configtext('tool_transcoder/ffmpegaudiochannels',
        get_string('ffmpegaudiochannels', 'tool_transcoder'),
        get_string('ffmpegaudiochannels_desc', 'tool_transcoder'), 2, PARAM_INT));

    /*
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
     */
    $name = 'tool_transcoder/contentareas';
    $visiblename = get_string('contentareas', 'tool_transcoder');
    $description = get_string('contentareas_desc', 'tool_transcoder');
    $defaultsetting = $choices = array(
        'mod_assign__assign__intro' => 'Assignment Description',
        'mod_book__book__intro' => 'Book Introduction',
        'mod_book__book_chapters__content' => 'Book Chapter',
        'course__course__summary' => 'Course Summary',
        'mod_folder__folder__intro' => 'Folder Introduction',
        'mod_forum__forum__intro' => 'Forum Introduction',
        'mod_label__label__intro' => 'Label Content',
        'mod_page__page__intro' => 'Page Introduction',
        'mod_page__page__content' => 'Page Content',
        'question__question__questiontext' => 'Quiz Questions',
        'mod_quiz__quiz__intro' => 'Quiz Introduction',
        'mod_url__url__intro' => 'URL Introduction',
        'mod_wiki__wiki__intro' => 'Wiki Introduction',
        'mod_wiki__wiki_pages__cachedcontent' => 'Wiki Pages'
    );
    $contentareas = new admin_setting_configmulticheckbox($name, $visiblename, $description, $defaultsetting, $choices);
    $settings->add($contentareas);

    // Transcode mime types.
    $name = 'tool_transcoder/mimetypes';
    $visiblename = get_string('mimetypes', 'tool_transcoder');
    $description = get_string('mimetypes_desc', 'tool_transcoder');
    $defaultsetting = $choices = array('video/webm' => 'video/webm', 'audio/ogg' => 'audio/ogg');
    $transcodeformats = new admin_setting_configmulticheckbox($name, $visiblename, $description, $defaultsetting, $choices);
    $settings->add($transcodeformats);
}
