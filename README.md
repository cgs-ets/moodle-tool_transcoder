# Transcoder plugin for Moodle.
An admin tool created to improve the browser compatibility of WebRTC based video and audio recordings. WebRTC recording is a feature of the Atto editor that allows users to record direct from their webcams and microphones. Behind the scenes, the file formats produced are webm for video and ogg for audio which do not have native support in Safari and iDevices. The transcoder plugin automatically crawls Moodle's file store and a number of content types (e.g. pages, forums, assignments, labels) for webm video and ogg audio files. It then uses FFmpeg to transcode them to H.264 AAC mp4 for video and mp3 for audio. The new file is stored with the original file, and the `video` or `audio` HTML5 tag within the activity HTML is automatically updated to include the transcoded file as an additional source. The original format is preserved as the first source. The default or example settings are optimised so that the transcoded files play natively within browsers on iDevices.

## Author
[Michael Vangelovski](https://github.com/michaelvangelovski/)

## How it works
 - A crawler cron task runs every minute to look for video and audio files. Files that have already been transcoded are skipped.
 - A check is performed to see whether the file is visible on the site. Files that are not used/referenced in html content are skipped.
 - The files are then added to a queue in a custom table.
 - If Moodle's cron handler is used (see `disablecron` setting), the crawler creates the next set of adhoc transcoder tasks, limited to the `concurrenylimit`
 - The actual transcoding is performed by a separate transcoder task. By default the plugin does not use Moodle's cron to handle this (see `disablecron` setting) and assumes you will run the transcoder task via a custom scheduler that executes the cli command.
 - The transcoder task picks up the file specified by the adhoc task, or the next in line from the queue if running by cli.
 - The file is transcoded using FFmpeg with the options specified in the plugin settings.
 - The new mp4 file is added to the Moodle file store.
 - A new `<source>` tag is appended to the `<video>` tag in any HTML content that references the video file. If the video had previously been transcoded by this plugin, the previous sources are removed from the html.
 - A cleaner task regularly runs via Moodle's cron system that:
   - Looks for tasks that have been in-progress for more than 24 hours. These are likely failed conversions. These are moved back into a ready state and retried up to 3 times.
   - If the task has been retried 3 times it is moved into a failed state and no loner attempted.
   - If the original file record is not found, the transcoded file is deleted from the file store.
   - If neither the original file nor the transcoded files are referenced in any content, the transcoded files are deleted from the file store.
   - If the original file is referenced in some html, but the transcoded source is not, the transcoded source is added back into the html. This can occur if content is over written, e.g. it is edited during transcoding and saved after transcoding has completed, overwriting the new html.

## System Requirements
 - FFmpeg must be installed on the system.

## Settings
 - `disablecron` → Whether to use Moodle's core cron system to handle file transcoding. Transcoding can take a long time and has the potential to block other background tasks. Selecting this option will disable transcoding via Moodle's cron system. The "crawler" task that queues videos will continue to be run by Moodle's cron system. Transcoding can be executed by implement your own scheduled task (e.g. Task Scheduler on Windows or Crontab on Unix) that executes the `\transcoder\cli\transcode.php` cli script. Run this script every minute to begin encoding videos that are waiting in the queue.
 - `concurrencylimit` → (Required) The maximum number of concurrent transcoding tasks allowed. (Default: 1)
 - `ffmpegbinary` → (Required) Path to the ffmpeg binary on the system, e.g. `C:/ffmpeg/bin/ffmpeg.exe`)
 - `ffprobebinary` → (Required) Path to the ffprobe binary on the system, e.g. `C:/ffmpeg/bin/ffprobe.exe`)
 - `ffmpegtimeout` → (Required) The timeout for the underlying process. (Default: 3600)
 - `ffmpegthreads` → (Required) The number of threads that FFMpeg should use. (Default: 2)
 - `ffmpegaudiocodec` → (Required) Sets the audio codec for video transcoding. For audio transcoding, the default and only available codec is libmp3lame.
 - `ffmpegadditionalparamsvideo` → You can specify additional parameters to be added to video encoding tasks. This must be entered in valid json format, e.g. `{"-vf": "scale=-1:720", "-r": "30", "-vprofile": "main", "-level": "3.1", "-b:a": "160k", "-ar": "48000", "-ac": "2", "-movflags": "+faststart"}`
 - `ffmpegadditionalparamsaudio` → You can specify additional parameters to be added to audio encoding tasks. This must be entered in valid json format, e.g. `{"-vf": "scale=-1:720", "-r": "30", "-vprofile": "main", "-level": "3.1", "-b:a": "160k", "-ar": "48000", "-ac": "2", "-movflags": "+faststart"}`
 - `ffmpegaudiokilobitrate` → (Required) The bitrate in kilobytes to be used for audio encoding tasks. (Default: 48000)
 - `ffmpegaudiochannels` → (Required) The number of channels to be used for audio encoding tasks. (Default: 2)
 - `contentareas` → (Required) The activity types and fields to look into when looking for references to video/audio files. (Default: all)
 - `mimetypes` → (Required) The formats to detect and transcode. (Default: video/webm, audio/ogg)
 - `processexpiry` → (Required) The number of minutes to allow an in-progress transcoding task to run before it is retried. Set this value high enough to give files enough time to transcode. This will depend on your length, upload and concurrency limits, as well as how well your server is resourced. At the same time, try to keep this value is low as possible so that multiple failed in-progress tasks do not block new tasks due to the concurrency limit. (Default: 30)
 - `retries` → (Required) The number of times to retry failed transcoding tasks. (Default: 2)
 - `filesfromtime` → Files created after this time (unix timestamp) will be crawled. This setting is automatically updated to the current time each time the crawler task runs so that only new files are crawled. (Default: 0)

## Development
Uses Composer package manager for library dependencies, however the vendor folder has been added to this repository to allow for simple installation.
