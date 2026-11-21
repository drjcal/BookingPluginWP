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

		$id = Enrolment_Repository::insert( $result['values'] );
		if ( ! $id ) {
			return new \WP_Error(
				'storage_failed',
				__( 'Je inschrijving kon niet worden opgeslagen. Probeer het later opnieuw.', 'gezonder-in-werk-booking' ),
				array( 'status' => 500 )
			);
		}

		return new \WP_REST_Response(
			array(
				'id'      => $id,
				'message' => __( 'Bedankt! Je inschrijving is ontvangen.', 'gezonder-in-werk-booking' ),
			),
			201
		);
	}
}
