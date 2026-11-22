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

		$outcome = Enrolment_Intake::submit( $request->get_params() );

		if ( Intake_Outcome::REJECTED === $outcome->kind ) {
			return new \WP_REST_Response(
				array(
					'code'    => 'validation_failed',
					'message' => __( 'Controleer de gemarkeerde velden.', 'gezonder-in-werk-booking' ),
					'errors'  => $outcome->errors,
					'values'  => $outcome->values,
				),
				422
			);
		}
		if ( Intake_Outcome::BUSY === $outcome->kind ) {
			$response = new \WP_REST_Response(
				array(
					'code'    => 'busy',
					'message' => Enrolment_Messages::busy(),
				),
				503
			);
			$response->header( 'Retry-After', '5' );
			return $response;
		}
		if ( Intake_Outcome::STORAGE_FAILED === $outcome->kind ) {
			return new \WP_Error(
				'storage_failed',
				__( 'Je inschrijving kon niet worden opgeslagen. Probeer het later opnieuw.', 'gezonder-in-werk-booking' ),
				array( 'status' => 500 )
			);
		}

		$body = array(
			'id'     => $outcome->ids()[0],
			'status' => $outcome->status(),
		);
		if ( $outcome->is_cycle ) {
			$body['ids']             = $outcome->ids();
			$body['group_reference'] = $outcome->group_reference;
		}
		$body['message']      = $outcome->message;
		$body['expectations'] = $outcome->expectations;
		return new \WP_REST_Response( $body, 201 );
	}
}
