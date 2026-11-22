<?php
namespace GezonderInWerk\Booking;

defined( 'ABSPATH' ) || exit;

/**
 * Server-side field validation: authoritative for the enrolment form. Pure: it reads no database;
 * what the chosen session or cycle allows is decided by Enrolment_Intake.
 */
class Enrolment_Validator {

	/** First error for a single field value, or null when it is valid. */
	private static function field_error( array $field, string $value ): ?string {
		if ( '' === $value ) {
			if ( 'checkbox' === $field['type'] ) {
				return $field['required'] ? __( 'Je moet hiermee akkoord gaan om je in te schrijven.', 'gezonder-in-werk-booking' ) : null;
			}
			return $field['required'] ? __( 'Dit veld is verplicht.', 'gezonder-in-werk-booking' ) : null;
		}
		$max = Enrolment_Fields::max_length( $field );
		if ( mb_strlen( $value ) > $max ) {
			/* translators: %d: maximum number of characters */
			return sprintf( __( 'Deze waarde is te lang (maximaal %d tekens).', 'gezonder-in-werk-booking' ), $max );
		}
		if ( 'email' === $field['type'] && ! is_email( $value ) ) {
			return __( 'Vul een geldig e-mailadres in.', 'gezonder-in-werk-booking' );
		}
		if ( isset( $field['pattern'] ) && ! preg_match( $field['pattern'], $value ) ) {
			return $field['pattern_message'];
		}
		return null;
	}

	/**
	 * @param array $input Raw request values.
	 * @return array{values: array<string,string>, errors: array<string,string>}
	 */
	public static function validate( array $input ): array {
		$values = array();
		$errors = array();

		foreach ( Enrolment_Fields::all() as $name => $field ) {
			if ( ! Enrolment_Fields::is_input( $field ) ) {
				continue; // Set by the system, never taken from the request.
			}
			$raw   = $input[ $name ] ?? '';
			$value = is_scalar( $raw ) ? trim( sanitize_text_field( (string) $raw ) ) : '';
			if ( 'checkbox' === $field['type'] ) {
				$value = in_array( strtolower( $value ), array( '1', 'on', 'true', 'yes' ), true ) ? '1' : '';
			}
			if ( isset( $field['normalise'] ) ) {
				$value = (string) call_user_func( $field['normalise'], $value );
			}
			$values[ $name ] = $value;

			$error = self::field_error( $field, $value );
			if ( null !== $error ) {
				$errors[ $name ] = $error;
			}
		}

		$raw_session          = $input['session_id'] ?? '';
		$values['session_id'] = is_scalar( $raw_session ) ? trim( (string) $raw_session ) : '';

		return array(
			'values' => $values,
			'errors' => $errors,
		);
	}
}
