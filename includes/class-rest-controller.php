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
		$rejection = Spam_Guard::check( $request->get_params() );
		if ( null !== $rejection ) {
			return new \WP_REST_Response(
				array(
					'code'    => 'rate' === $rejection ? 'rate_limited' : 'spam_rejected',
					'message' => 'rate' === $rejection
						? __( 'Je hebt te veel pogingen gedaan. Probeer het later opnieuw.', 'gezonder-in-werk-booking' )
						: __( 'Je inschrijving kon niet worden verwerkt. Laad de pagina opnieuw en probeer het nog eens.', 'gezonder-in-werk-booking' ),
				),
				'rate' === $rejection ? 429 : 403
			);
		}

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
		$message    = Enrolment_Messages::outcome( (bool) $cycle, $waitlisted );
		Enrolment_Mailer::send_for_submission( $result['values'], $cycle ? $stored['ids'] : array( $stored['id'] ), $cycle );

		if ( $cycle ) {
			return new \WP_REST_Response(
				array(
					'id'              => $stored['id'],
					'ids'             => $stored['ids'],
					'group_reference' => $stored['group_reference'],
					'status'          => $stored['status'],
					'message'         => $message,
				),
				201
			);
		}
		return new \WP_REST_Response(
			array(
				'id'      => $stored['id'],
				'status'  => $stored['status'],
				'message' => $message,
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
