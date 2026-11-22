<?php
namespace GezonderInWerk\Booking;

defined( 'ABSPATH' ) || exit;

/**
 * The outcome wording shown to the enrolee, in one place: the REST response and the confirmation
 * e-mail both read it from here so they cannot drift apart.
 */
class Enrolment_Messages {

	/** @param bool $waitlisted Single session: waitlisted. Cycle: at least one webinar is waitlisted. */
	public static function outcome( bool $is_cycle, bool $waitlisted ): string {
		if ( $is_cycle ) {
			return $waitlisted
				? __( 'Bedankt! Voor een of meer webinars van de cyclus is het vol: daarvoor sta je op de wachtlijst en die inschrijving is nog niet bevestigd. De overige webinars zijn bevestigd. We nemen contact met je op als er een plek vrijkomt.', 'gezonder-in-werk-booking' )
				: __( 'Bedankt! Je inschrijving voor de hele cyclus is bevestigd.', 'gezonder-in-werk-booking' );
		}
		return $waitlisted
			? __( 'Bedankt! Deze datum is vol: je staat op de wachtlijst en je inschrijving is nog niet bevestigd. We nemen contact met je op als er een plek vrijkomt.', 'gezonder-in-werk-booking' )
			: __( 'Bedankt! Je inschrijving is bevestigd.', 'gezonder-in-werk-booking' );
	}

	public static function closed( bool $is_cycle ): string {
		return $is_cycle
			? __( 'Inschrijven voor (een deel van) deze cyclus is gesloten.', 'gezonder-in-werk-booking' )
			: __( 'Inschrijven voor deze datum is gesloten.', 'gezonder-in-werk-booking' );
	}

	public static function duplicate( bool $is_cycle ): string {
		return $is_cycle
			? __( 'Dit e-mailadres is al ingeschreven voor een of meer webinars van deze cyclus.', 'gezonder-in-werk-booking' )
			: __( 'Dit e-mailadres is al ingeschreven voor deze datum.', 'gezonder-in-werk-booking' );
	}

	public static function busy(): string {
		return __( 'Er zijn op dit moment veel inschrijvingen tegelijk. Er is niets opgeslagen: probeer het over een paar seconden opnieuw.', 'gezonder-in-werk-booking' );
	}

	public static function no_session(): string {
		return __( 'Kies een datum waarvoor je je wilt inschrijven.', 'gezonder-in-werk-booking' );
	}

	public static function greeting( string $first_name ): string {
		return sprintf( __( 'Beste %s,', 'gezonder-in-werk-booking' ), $first_name );
	}

	public static function signoff(): string {
		return __( 'Met vriendelijke groet,', 'gezonder-in-werk-booking' );
	}

	/**
	 * Explains what happens next: for confirmed enrolments includes guidance on questions
	 * and cancellation; for waitlisted keeps a neutral tone without promises.
	 */
	public static function what_next( bool $waitlisted ): string {
		return $waitlisted
			? __( 'Zodra er een plaats beschikbaar komt, nemen we direct contact met je op.', 'gezonder-in-werk-booking' )
			: __( 'Heb je vragen over je inschrijving of ben je verhinderd? Bekijk onze annuleringsvoorwaarden of neem contact met ons op via onderstaande contactgegevens.', 'gezonder-in-werk-booking' );
	}

	public static function cancellation_terms_label(): string {
		return __( 'Annuleringsvoorwaarden bekijken', 'gezonder-in-werk-booking' );
	}

	public static function cancellation_terms_url(): string {
		return 'https://gezonderinwerk.nl/wp-content/uploads/2026/01/Algemene-Voorwaarden-Nascholingen-Gezonder-in-Werk-januari-2026.pdf';
	}

	public static function promotion_intro(): string {
		return __( 'Goed nieuws: er is een plek vrijgekomen en je inschrijving is nu bevestigd.', 'gezonder-in-werk-booking' );
	}

	public static function admin_intro( string $name, string $email, string $employer ): string {
		return sprintf( __( 'Nieuwe inschrijving van %1$s (%2$s), %3$s.', 'gezonder-in-werk-booking' ), $name, $email, $employer );
	}

	public static function admin_view_button_label(): string {
		return __( 'Bekijk de inschrijving in WordPress', 'gezonder-in-werk-booking' );
	}

	public static function admin_fields_heading(): string {
		return __( 'Ingezonden gegevens', 'gezonder-in-werk-booking' );
	}

	public static function summary_heading(): string {
		return __( 'Details van je inschrijving', 'gezonder-in-werk-booking' );
	}
}
