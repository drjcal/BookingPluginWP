<?php
namespace GezonderInWerk\Booking;

defined( 'ABSPATH' ) || exit;

/**
 * Enrolment wording and formatting in one place: status, session, date and price labels.
 * No dependency on WP admin, so the admin pages, CSV export, mailer and public form share it.
 */
class Labels {

	public static function status( string $status ): string {
		if ( Installer::STATUS_CANCELLED === $status ) {
			return __( 'Geannuleerd', 'gezonder-in-werk-booking' );
		}
		if ( Installer::STATUS_WAITLISTED === $status ) {
			return __( 'Wachtlijst', 'gezonder-in-werk-booking' );
		}
		return __( 'Bevestigd', 'gezonder-in-werk-booking' );
	}

	/** "Ingeschreven op" cell: enrolment timestamp in site time. */
	public static function created( array $row ): string {
		return mysql2date( 'd-m-Y H:i', get_date_from_gmt( $row['created_at'] ) );
	}

	/** Session cell: course and date, or a placeholder when the session no longer matches. */
	public static function enrolment_session( array $row ): string {
		if ( empty( $row['course_title'] ) ) {
			return __( 'Onbekende sessie', 'gezonder-in-werk-booking' );
		}
		return sprintf( '%s – %s, %s', $row['course_title'], mysql2date( 'd-m-Y', $row['session_date'] ), $row['location'] );
	}

	public static function price( int $cents ): string {
		return '€ ' . number_format( $cents / 100, 2, ',', '.' );
	}

	public static function cycle( array $cycle ): string {
		return sprintf(
			/* translators: 1: cycle title, 2: number of webinars, 3: bundle price */
			_n( '%1$s – hele cyclus (%2$d webinar) – %3$s excl. BTW', '%1$s – hele cyclus (%2$d webinars) – %3$s excl. BTW', count( $cycle['sessions'] ), 'gezonder-in-werk-booking' ),
			$cycle['title'],
			count( $cycle['sessions'] ),
			self::price( (int) $cycle['price_cents'] )
		);
	}

	public static function session( array $session ): string {
		return sprintf(
			/* translators: 1: course title, 2: date, 3: start time, 4: end time, 5: location, 6: price */
			__( '%1$s – %2$s, %3$s–%4$s, %5$s – %6$s excl. BTW', 'gezonder-in-werk-booking' ),
			$session['title'],
			mysql2date( 'd-m-Y', $session['session_date'] ),
			substr( $session['start_time'], 0, 5 ),
			substr( $session['end_time'], 0, 5 ),
			$session['location'],
			self::price( (int) $session['price_cents'] )
		);
	}
}
