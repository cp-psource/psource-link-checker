<?php
/**
 * @author DerN3rd
 * @copyright 2021
 *
 * Das schreckliche Deinstallationsskript.
 */

if ( defined( 'ABSPATH' ) && defined( 'WP_UNINSTALL_PLUGIN' ) ) {

	// Entferne die Einstellungen und das Installationsprotokoll des Plugins.
	delete_option( 'wsblc_options' );
	delete_option( 'blc_installation_log' );

	// Entferne die Datenbanktabellen.
	$mywpdb = $GLOBALS['wpdb'];
	if( isset( $mywpdb ) ) { /** @var wpdb $mywpdb */
		// EXTERMINATE!
		$mywpdb->query( "DROP TABLE IF EXISTS {$mywpdb->prefix}blc_linkdata, {$mywpdb->prefix}blc_postdata, {$mywpdb->prefix}blc_instances, {$mywpdb->prefix}blc_links, {$mywpdb->prefix}blc_synch, {$mywpdb->prefix}blc_filters" );
	}
}
