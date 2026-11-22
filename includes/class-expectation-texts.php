<?php
namespace GezonderInWerk\Booking;

defined( 'ABSPATH' ) || exit;

/**
 * When the enrolee receives what, in one place: the wording, the send-date rule and the
 * working-day calculation. The form, the confirmation screen and the e-mails all read it from
 * here. Sending the link and the materials itself stays manual.
 *
 * A webinar link goes out 1 working day before the session; practical information for a live
 * training goes out at the latest 1 week before. A working day skips weekends and Dutch public
 * holidays. Good Friday and Bevrijdingsdag count too: skipping an extra day only makes the send date
 * earlier, so the promise stays safe.
 */
class Expectation_Texts {

	const FORMAT_WEBINAR = 'webinar';
	const FORMAT_LIVE    = 'live';

	const DAYS = array( 'zondag', 'maandag', 'dinsdag', 'woensdag', 'donderdag', 'vrijdag', 'zaterdag' );

	const MONTHS = array( 'januari', 'februari', 'maart', 'april', 'mei', 'juni', 'juli', 'augustus', 'september', 'oktober', 'november', 'december' );

	/** @return array<string,string> Format value => admin label. */
	public static function formats(): array {
		return array(
			self::FORMAT_WEBINAR => __( 'Webinar', 'gezonder-in-werk-booking' ),
			self::FORMAT_LIVE    => __( 'Live training', 'gezonder-in-werk-booking' ),
		);
	}

	/** Unknown or missing values fall back to the default (webinar). */
	public static function normalise_format( $format ): string {
		return self::FORMAT_LIVE === $format ? self::FORMAT_LIVE : self::FORMAT_WEBINAR;
	}

	/** Dutch public holidays of a year as Y-m-d => true. */
	public static function holidays( int $year ): array {
		$easter  = self::easter( $year );
		$king    = new \DateTimeImmutable( $year . '-04-27' );
		$days    = array(
			$year . '-01-01',
			$easter->modify( '-2 days' )->format( 'Y-m-d' ),
			$easter->format( 'Y-m-d' ),
			$easter->modify( '+1 day' )->format( 'Y-m-d' ),
			'0' === $king->format( 'w' ) ? $year . '-04-26' : $king->format( 'Y-m-d' ),
			$easter->modify( '+39 days' )->format( 'Y-m-d' ),
			$easter->modify( '+49 days' )->format( 'Y-m-d' ),
			$easter->modify( '+50 days' )->format( 'Y-m-d' ),
			$year . '-05-05',
			$year . '-12-25',
			$year . '-12-26',
		);
		return array_fill_keys( $days, true );
	}

	/** Easter Sunday (anonymous Gregorian algorithm; avoids the optional calendar extension). */
	private static function easter( int $year ): \DateTimeImmutable {
		$a     = $year % 19;
		$b     = intdiv( $year, 100 );
		$c     = $year % 100;
		$d     = intdiv( $b, 4 );
		$e     = $b % 4;
		$f     = intdiv( $b + 8, 25 );
		$g     = intdiv( $b - $f + 1, 3 );
		$h     = ( 19 * $a + $b - $d - $g + 15 ) % 30;
		$i     = intdiv( $c, 4 );
		$k     = $c % 4;
		$l     = ( 32 + 2 * $e + 2 * $i - $h - $k ) % 7;
		$m     = intdiv( $a + 11 * $h + 22 * $l, 451 );
		$month = intdiv( $h + $l - 7 * $m + 114, 31 );
		$day   = ( $h + $l - 7 * $m + 114 ) % 31 + 1;
		return new \DateTimeImmutable( sprintf( '%04d-%02d-%02d', $year, $month, $day ) );
	}

	public static function is_working_day( string $date ): bool {
		$day = new \DateTimeImmutable( $date );
		if ( (int) $day->format( 'N' ) >= 6 ) {
			return false;
		}
		return ! isset( self::holidays( (int) $day->format( 'Y' ) )[ $date ] );
	}

	/** The nearest working day strictly before $date. */
	public static function working_day_before( string $date ): string {
		$day = new \DateTimeImmutable( $date );
		do {
			$day = $day->modify( '-1 day' );
		} while ( ! self::is_working_day( $day->format( 'Y-m-d' ) ) );
		return $day->format( 'Y-m-d' );
	}

	/** Y-m-d by which the information must be sent for a session. */
	public static function send_date( array $session ): string {
		$date = (string) $session['session_date'];
		if ( self::FORMAT_LIVE === self::normalise_format( $session['format'] ?? null ) ) {
			return ( new \DateTimeImmutable( $date ) )->modify( '-7 days' )->format( 'Y-m-d' );
		}
		return self::working_day_before( $date );
	}

	/** "maandag 15 maart" */
	public static function format_date( string $date ): string {
		$day = new \DateTimeImmutable( $date );
		return sprintf( '%s %d %s', self::DAYS[ (int) $day->format( 'w' ) ], (int) $day->format( 'j' ), self::MONTHS[ (int) $day->format( 'n' ) - 1 ] );
	}

	/**
	 * The expectation text for one session. When the send date has already passed (a late
	 * enrolment) the information follows as soon as possible instead.
	 *
	 * @param array       $session Session row (session_date, format).
	 * @param string|null $today   Y-m-d, defaults to today in site time.
	 */
	public static function for_session( array $session, ?string $today = null ): string {
		$today = $today ?? current_time( 'Y-m-d' );
		$send  = self::send_date( $session );
		$live  = self::FORMAT_LIVE === self::normalise_format( $session['format'] ?? null );
		$when  = self::format_date( $send );

		if ( $send < $today ) {
			return $live
				? __( 'Praktische informatie, inclusief werkmaterialen, ontvang je zo snel mogelijk.', 'gezonder-in-werk-booking' )
				: __( 'De link naar het webinar ontvang je zo snel mogelijk.', 'gezonder-in-werk-booking' );
		}
		return $live
			/* translators: %s: send date, e.g. "maandag 15 maart" */
			? sprintf( __( 'Praktische informatie, inclusief werkmaterialen, ontvang je uiterlijk %s (1 week voor de training).', 'gezonder-in-werk-booking' ), $when )
			/* translators: %s: send date, e.g. "maandag 15 maart" */
			: sprintf( __( 'De link naar het webinar ontvang je uiterlijk %s (1 werkdag voor het webinar).', 'gezonder-in-werk-booking' ), $when );
	}

	/**
	 * Lines for the enrolments that are confirmed; a waitlisted enrolment gets no promise. A
	 * single enrolment gets the bare text, a cycle names each webinar with its own date.
	 *
	 * @param array[] $rows     Enrolments with session_date, format and course_title.
	 * @param bool    $is_cycle Whether the rows belong to one cycle submission.
	 * @return string[]
	 */
	public static function for_enrolments( array $rows, bool $is_cycle ): array {
		$lines = array();
		foreach ( $rows as $row ) {
			if ( Installer::STATUS_CONFIRMED !== $row['status'] ) {
				continue;
			}
			$text    = self::for_session( $row );
			$lines[] = $is_cycle ? sprintf( '%s (%s): %s', $row['course_title'], mysql2date( 'd-m-Y', $row['session_date'] ), $text ) : $text;
		}
		return $lines;
	}

	/** Lines for a form target: a session, or every webinar of a cycle. @return string[] */
	public static function for_cycle( array $cycle ): array {
		return array_map(
			static fn( array $s ) => sprintf( '%s (%s): %s', $s['title'], mysql2date( 'd-m-Y', $s['session_date'] ), self::for_session( $s ) ),
			$cycle['sessions']
		);
	}
}
