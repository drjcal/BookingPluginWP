<?php
/**
 * Uninstall behaviour: enrolment data is personal data, so it is KEPT by default.
 * It is only dropped when the site owner opted in with the option
 * `gzw_booking_remove_data_on_uninstall` (set to 1) before deleting the plugin.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/class-installer.php';
require_once __DIR__ . '/includes/class-settings-page.php';

if ( get_option( \GezonderInWerk\Booking\Installer::OPTION_REMOVE_ON_UNINSTALL ) ) {
	\GezonderInWerk\Booking\Installer::uninstall();
}
