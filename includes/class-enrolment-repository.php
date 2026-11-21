<?php
namespace GezonderInWerk\Booking;

defined( 'ABSPATH' ) || exit;

class Enrolment_Repository {

	/**
	 * @param array<string,string> $values Validated values.
	 * @return int New enrolment id.
	 */
	public static function insert( array $values ): int {
		global $wpdb;

		$row = $values + array(
			'status'     => 'confirmed',
			'created_at' => current_time( 'mysql', true ),
		);

		$ok = $wpdb->insert( Installer::table_name(), $row );
		if ( false === $ok ) {
			return 0;
		}
		return (int) $wpdb->insert_id;
	}
}
