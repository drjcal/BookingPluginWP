<?php
namespace GezonderInWerk\Booking;

defined( 'ABSPATH' ) || exit;

/**
 * Plain-text e-mails after a submission, sent with wp_mail() so site SMTP plugins apply:
 * one confirmation to the enrolee and one notification to the admin address, also for cycles.
 */
class Enrolment_Mailer {

	/**
	 * @param array<string,string> $values  Validated submission values.
	 * @param int[]                $ids     Created enrolment ids (several for a cycle).
	 * @param ?array               $cycle   The cycle when the submission was for a whole cycle.
	 */
	public static function send_for_submission( array $values, array $ids, ?array $cycle ): void {
		$rows = array_values( array_filter( array_map( array( Enrolment_Repository::class, 'get' ), $ids ) ) );
		if ( ! $rows ) {
			return;
		}
		$waitlisted = (bool) array_filter( $rows, static fn( array $r ) => Installer::STATUS_WAITLISTED === $r['status'] );
		$name       = trim( $values['first_name'] . ' ' . $values['last_name'] );
		$summary    = self::summary( $rows, $cycle );

		$subject = $waitlisted
			? __( 'Je staat op de wachtlijst', 'gezonder-in-werk-booking' )
			: __( 'Je inschrijving is bevestigd', 'gezonder-in-werk-booking' );
		$body    = sprintf( __( 'Beste %s,', 'gezonder-in-werk-booking' ), $values['first_name'] ) . "\n\n"
			. Enrolment_Messages::outcome( null !== $cycle, $waitlisted ) . "\n\n"
			. $summary . "\n"
			. __( 'Met vriendelijke groet,', 'gezonder-in-werk-booking' ) . "\n" . get_bloginfo( 'name' ) . "\n";
		wp_mail( $values['email'], $subject, $body );

		$admin_body = sprintf( __( 'Nieuwe inschrijving van %1$s (%2$s), %3$s.', 'gezonder-in-werk-booking' ), $name, $values['email'], $values['employer'] ) . "\n\n"
			. $summary . "\n"
			. sprintf( __( 'Bekijk de inschrijving: %s', 'gezonder-in-werk-booking' ), add_query_arg( array( 'page' => Enrolments_Admin::SLUG, 'enrolment' => $rows[0]['id'] ), admin_url( 'admin.php' ) ) ) . "\n";
		wp_mail(
			Settings_Page::notification_email(),
			sprintf( __( 'Nieuwe inschrijving: %s', 'gezonder-in-werk-booking' ), $name ),
			$admin_body
		);
	}

	/** One line per webinar/session with its own status, plus the price (bundle total for a cycle). */
	private static function summary( array $rows, ?array $cycle ): string {
		$lines = array();
		if ( $cycle ) {
			$lines[] = $cycle['title'];
		}
		$total = 0;
		foreach ( $rows as $row ) {
			$total  += (int) $row['price_cents'];
			$lines[] = sprintf(
				'- %1$s – %2$s, %3$s–%4$s, %5$s – %6$s',
				$row['course_title'],
				mysql2date( 'd-m-Y', $row['session_date'] ),
				substr( $row['start_time'], 0, 5 ),
				substr( $row['end_time'], 0, 5 ),
				$row['location'],
				Labels::status( $row['status'] )
			);
		}
		$lines[] = sprintf(
			/* translators: %s: price */
			$cycle ? __( 'Prijs hele cyclus: %s excl. BTW', 'gezonder-in-werk-booking' ) : __( 'Prijs: %s excl. BTW', 'gezonder-in-werk-booking' ),
			Labels::price( $total )
		);
		return implode( "\n", $lines ) . "\n";
	}
}
