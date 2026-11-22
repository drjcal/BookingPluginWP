<?php
namespace GezonderInWerk\Booking;

defined( 'ABSPATH' ) || exit;

/**
 * Branded HTML e-mails with plain-text alternative after a submission, sent through a Mail_Adapter:
 * one confirmation to the enrolee and one notification to the admin address, also for cycles and promotions.
 */
class Enrolment_Mailer {

	/**
	 * @param Mail_Adapter         $mail       Where the mails go.
	 * @param array<string,string> $values     Validated submission values.
	 * @param array[]              $enrolments The created enrolments (several for a cycle), with session and course.
	 * @param ?array               $cycle      The cycle when the submission was for a whole cycle.
	 */
	public static function send_for_submission( Mail_Adapter $mail, array $values, array $enrolments, ?array $cycle ): void {
		$rows = array_values( $enrolments );
		if ( ! $rows ) {
			return;
		}
		$waitlisted   = (bool) array_filter( $rows, static fn( array $r ) => Installer::STATUS_WAITLISTED === $r['status'] );
		$name         = trim( ( $values['first_name'] ?? '' ) . ' ' . ( $values['last_name'] ?? '' ) );
		$expectations = class_exists( Expectation_Texts::class ) ? Expectation_Texts::for_enrolments( $rows, null !== $cycle ) : array();

		$subject = $waitlisted
			? __( 'Je staat op de wachtlijst', 'gezonder-in-werk-booking' )
			: __( 'Je inschrijving is bevestigd', 'gezonder-in-werk-booking' );

		$html  = Email_Template::render_confirmation_html( $values, $rows, $cycle, $expectations );
		$plain = Email_Template::render_confirmation_plain( $values, $rows, $cycle, $expectations );
		$mail->send( $values['email'], $subject, $html, $plain );

		$admin_url     = add_query_arg( array( 'page' => Enrolments_Admin::SLUG, 'enrolment' => $rows[0]['id'] ), admin_url( 'admin.php' ) );
		$admin_subject = sprintf( __( 'Nieuwe inschrijving: %s', 'gezonder-in-werk-booking' ), $name );
		$admin_html    = Email_Template::render_admin_notification_html( $values, $rows, $cycle, $admin_url );
		$admin_plain   = Email_Template::render_admin_notification_plain( $values, $rows, $cycle, $admin_url );

		$mail->send(
			Settings_Page::notification_email(),
			$admin_subject,
			$admin_html,
			$admin_plain
		);
	}

	/** Sent when staff promote a waitlisted enrolment: only now does the enrolee get the promise. */
	public static function send_promotion( Mail_Adapter $mail, array $enrolment ): void {
		if ( empty( $enrolment['email'] ) ) {
			return;
		}
		$expectations = class_exists( Expectation_Texts::class ) ? Expectation_Texts::for_enrolments( array( $enrolment ), false ) : array();
		$subject      = __( 'Je inschrijving is bevestigd', 'gezonder-in-werk-booking' );
		$html         = Email_Template::render_confirmation_html( $enrolment, array( $enrolment ), null, $expectations, true );
		$plain        = Email_Template::render_confirmation_plain( $enrolment, array( $enrolment ), null, $expectations, true );
		$mail->send( $enrolment['email'], $subject, $html, $plain );
	}
}

