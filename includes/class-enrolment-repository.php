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
			'status'     => Installer::STATUS_DEFAULT,
			'created_at' => current_time( 'mysql', true ),
		);

		$ok = $wpdb->insert( Installer::table_name(), $row );
		if ( false === $ok ) {
			return 0;
		}
		return (int) $wpdb->insert_id;
	}

	/** An enrolment is active unless cancelled. */
	public static function has_active( int $session_id, string $email ): bool {
		global $wpdb;
		$table = Installer::table_name();
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE session_id = %d AND email = %s AND status <> %s",
				$session_id,
				$email,
				Installer::STATUS_CANCELLED
			)
		);
	}
}
