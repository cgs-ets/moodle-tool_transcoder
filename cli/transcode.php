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
 * CLI sync for transcoder transcorder.
 *
 *
 * @package   tool_transcoder
 * @copyright 2020 Michael Vangelovski 
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__.'/../../../../config.php');
require_once("$CFG->libdir/clilib.php");

// Now get cli options.
list($options, $unrecognized) = cli_get_params(array('verbose' => false, 'help' => false),
                                               array('v' => 'verbose', 'h' => 'help'));

if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognized));
}

if ($options['help']) {
    $help = "Transcoder tool.

Options:
-h, --help            Print out this help

How to run this script:
\$ /path/to/php /path/to/transcoder/cli/transcode.php

Examples:
\$ C:/XAMPP73/php/php.exe C:/XAMPP73/apps/moodle/htdocs/admin/tool/transcoder/cli/transcode.php
\$ sudo -u www-data /usr/bin/php admin/tool/transcoder/cli/transcode.php
";

    echo $help;
    die;
}

$result = 0;
$transcoder = new tool_transcoder\task\transcoder();

$result = $result | $transcoder->execute();

exit($result);
