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
}
