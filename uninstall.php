<?php
/**
 * Uninstall behaviour: enrolment data is personal data, so it is KEPT by default.
 * It is only dropped when the site owner opted in with the option
 * `gzw_booking_remove_data_on_uninstall` (set to 1) before deleting the plugin.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

if ( get_option( 'gzw_booking_remove_data_on_uninstall' ) ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}gzw_enrolments" ); // phpcs:ignore WordPress.DB.PreparedSQL
	delete_option( 'gzw_booking_db_version' );
	delete_option( 'gzw_booking_remove_data_on_uninstall' );
}
