<?php
/*
 * Plugin Name: Link Checker
 * Plugin URI:  https://cp-psource.github.io/psource-link-checker/
 * Description: Überprüft Deine Seite auf fehlerhafte Links und fehlende Bilder und benachrichtigt Dich im Dashboard, falls gefunden.
 * Version:     1.0.7
 * Author:      PSOURCE
 * Author URI:  https://github.com/cp-psource
 * Text Domain: psource-link-checker
 * Domain Path: languages
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
*/

/*
Broken Link Checker is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 2 of the License, or
any later version.

Broken Link Checker is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with Broken Link Checker. If not, see https://www.gnu.org/licenses/gpl-2.0.html.
*/

/**
 * @@@@@@@@@@@@@@@@@ PS UPDATER 1.3 @@@@@@@@@@@
 **/
require 'psource/psource-plugin-update/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;
 
$myUpdateChecker = PucFactory::buildUpdateChecker(
	'https://github.com/cp-psource/psource-link-checker',
	__FILE__,
	'psource-link-checker'
);
 
//Set the branch that contains the stable release.
$myUpdateChecker->setBranch('master');

/**
 * @@@@@@@@@@@@@@@@@ ENDE PS UPDATER 1.3 @@@@@@@@@@@
 **/


//Path to this file
if ( !defined('BLC_PLUGIN_FILE') ){
	define('BLC_PLUGIN_FILE', __FILE__);
}

//Path to the plugin's directory
if ( !defined('BLC_DIRECTORY') ){
	define('BLC_DIRECTORY', dirname(__FILE__));
}

//Load the actual plugin
require 'core/init.php';


