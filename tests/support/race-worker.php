<?php
/**
 * Worker for RealLockRaceTest: a separate PHP process, so it has its own database connection and
 * takes the real MySQL named lock. Loads WordPress and the plugin against the test database.
 *
 * Usage: php race-worker.php setup
 *        php race-worker.php submit <session_id> <email> <go-file>
 *        php race-worker.php rows
 *        php race-worker.php teardown
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$tests_dir = getenv( 'WP_TESTS_DIR' ) ?: '/wordpress-phpunit';
require_once $tests_dir . '/wp-tests-config.php';

$_SERVER['HTTP_HOST']      = WP_TESTS_DOMAIN;
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR']    = '127.0.0.1';
define( 'WP_USE_THEMES', false );
require_once ABSPATH . 'wp-settings.php';
require_once dirname( __DIR__, 2 ) . '/gezonder-in-werk-booking.php';
require_once dirname( __DIR__ ) . '/helpers.php';

use GezonderInWerk\Booking\Catalog_Repository;
use GezonderInWerk\Booking\Enrolment_Intake;
use GezonderInWerk\Booking\Installer;

global $wpdb;
$wpdb->hide_errors();
add_filter( 'gzw_booking_rate_limit', static fn() => array( 1000, 60 ) );
Enrolment_Intake::use_mail_adapter( new Gzw_Mail_Recorder() );

// Widen the window between "is a seat free?" and "insert", as a slow database would, so a missing
// lock shows up reliably instead of by luck.
add_filter(
	'query',
	static function ( $sql ) {
		if ( 0 === strpos( ltrim( $sql ), 'INSERT INTO `' . Installer::table_name() . '`' ) ) {
			usleep( 200000 );
		}
		return $sql;
	}
);

$tables = array( Installer::table_name(), Installer::cycle_sessions_table(), Installer::cycles_table(), Installer::sessions_table(), Installer::courses_table() );

switch ( $argv[1] ?? '' ) {
	case 'setup':
		Installer::maybe_upgrade(); // The test bootstrap normally created the real tables already.
		foreach ( $tables as $table ) {
			$wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
		$course = Catalog_Repository::save_course( array( 'title' => 'Race', 'price_cents' => 9900 ) );
		$base   = array( 'course_id' => $course, 'session_date' => '2099-03-15', 'start_time' => '09:30', 'end_time' => '16:30', 'location' => 'Online', 'is_open' => 1 );
		echo wp_json_encode(
			array(
				'seat'      => Catalog_Repository::save_session( $base + array( 'capacity' => 1 ) ),
				'duplicate' => Catalog_Repository::save_session( $base ),
			)
		);
		break;

	case 'submit':
		list( , , $session, $email, $go ) = $argv;
		while ( ! file_exists( $go ) ) {
			usleep( 1000 ); // Start line: every worker has loaded WordPress before any submits.
		}
		$response = Gzw_Submission::post(
			array(
				'session_id'       => $session,
				'first_name'       => 'Race',
				'last_name'        => 'Runner',
				'email'            => $email,
				'employer'         => 'X',
				'invoice_name'     => 'X B.V.',
				'invoice_street'   => 'Kerkstraat 1',
				'invoice_postcode' => '5611 AB',
				'invoice_city'     => 'Eindhoven',
			)
		);
		echo wp_json_encode( array( 'http' => $response->get_status(), 'data' => $response->get_data() ) );
		break;

	case 'rows':
		echo wp_json_encode( $wpdb->get_results( 'SELECT session_id, email, status FROM ' . Installer::table_name(), ARRAY_A ) );
		break;

	case 'teardown':
		foreach ( $tables as $table ) {
			$wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
		echo '[]';
		break;
}
