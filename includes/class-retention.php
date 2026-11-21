<?php
namespace GezonderInWerk\Booking;

defined( 'ABSPATH' ) || exit;

/**
 * Retention: a daily scheduled job anonymises enrolments older than the configured period
 * (Settings_Page::retention_months(); 0 keeps everything).
 */
class Retention {

	const HOOK = 'gzw_booking_retention_cleanup';

	/** Idempotent; called on activation and on every upgrade check so a lost event comes back. */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/** @return int Rows anonymised. */
	public static function run(): int {
		$months = Settings_Page::retention_months();
		if ( $months < 1 ) {
			return 0;
		}
		$cutoff = ( new \DateTimeImmutable( current_time( 'Y-m-d' ) ) )->modify( "-{$months} months" )->format( 'Y-m-d' );
		return Enrolment_Repository::anonymise_expired( $cutoff );
	}
}
