<?php
namespace GezonderInWerk\Booking;

defined( 'ABSPATH' ) || exit;

/**
 * Public submission endpoint: POST /wp-json/gezonder-in-werk/v1/enrolments
 */
class Rest_Controller {

	const NAMESPACE = 'gezonder-in-werk/v1';

	public static function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/enrolments',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'create' ),
				'permission_callback' => '__return_true', // Public form; validation is server-side.
			)
		);
	}

	public static function create( \WP_REST_Request $request ) {
		$result = Enrolment_Validator::validate( $request->get_params() );

		if ( $result['errors'] ) {
			return new \WP_REST_Response(
				array(
					'code'    => 'validation_failed',
					'message' => __( 'Controleer de gemarkeerde velden.', 'gezonder-in-werk-booking' ),
					'errors'  => $result['errors'],
					'values'  => $result['values'],
				),
				422
			);
		}

		$cycle  = $result['cycle'];
		$stored = $cycle ? Enrolment_Repository::enrol_cycle( $result['values'], $cycle ) : Enrolment_Repository::enrol( $result['values'] );
		if ( 'closed' === $stored['error'] ) {
			return self::rejected( 'session_id', $cycle ? __( 'Inschrijven voor (een deel van) deze cyclus is gesloten.', 'gezonder-in-werk-booking' ) : __( 'Inschrijven voor deze datum is gesloten.', 'gezonder-in-werk-booking' ), $result['values'] );
		}
		if ( 'duplicate' === $stored['error'] ) {
			return self::rejected( 'email', $cycle ? __( 'Dit e-mailadres is al ingeschreven voor een of meer webinars van deze cyclus.', 'gezonder-in-werk-booking' ) : __( 'Dit e-mailadres is al ingeschreven voor deze datum.', 'gezonder-in-werk-booking' ), $result['values'] );
		}
		if ( $stored['error'] ) {
			return new \WP_Error(
				'storage_failed',
				__( 'Je inschrijving kon niet worden opgeslagen. Probeer het later opnieuw.', 'gezonder-in-werk-booking' ),
				array( 'status' => 500 )
			);
		}

		$waitlisted = Installer::STATUS_WAITLISTED === $stored['status'];
		if ( $cycle ) {
			return new \WP_REST_Response(
				array(
					'id'              => $stored['id'],
					'ids'             => $stored['ids'],
					'group_reference' => $stored['group_reference'],
					'status'          => $stored['status'],
					'message'         => $waitlisted
						? __( 'Bedankt! Voor een of meer webinars van de cyclus is het vol: daarvoor sta je op de wachtlijst en die inschrijving is nog niet bevestigd. De overige webinars zijn bevestigd. We nemen contact met je op als er een plek vrijkomt.', 'gezonder-in-werk-booking' )
						: __( 'Bedankt! Je inschrijving voor de hele cyclus is bevestigd.', 'gezonder-in-werk-booking' ),
				),
				201
			);
		}
		return new \WP_REST_Response(
			array(
				'id'      => $stored['id'],
				'status'  => $stored['status'],
				'message' => $waitlisted
					? __( 'Bedankt! Deze datum is vol: je staat op de wachtlijst en je inschrijving is nog niet bevestigd. We nemen contact met je op als er een plek vrijkomt.', 'gezonder-in-werk-booking' )
					: __( 'Bedankt! Je inschrijving is bevestigd.', 'gezonder-in-werk-booking' ),
			),
			201
		);
	}

	private static function rejected( string $field, string $message, array $values ): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'code'    => 'validation_failed',
				'message' => __( 'Controleer de gemarkeerde velden.', 'gezonder-in-werk-booking' ),
				'errors'  => array( $field => $message ),
				'values'  => $values,
			),
			422
		);
	}
}
