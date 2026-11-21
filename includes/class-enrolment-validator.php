<?php
namespace GezonderInWerk\Booking;

defined( 'ABSPATH' ) || exit;

/**
 * Server-side validation: authoritative for the enrolment form.
 */
class Enrolment_Validator {

	/** First error for a single field value, or null when it is valid. */
	private static function field_error( array $field, string $value ): ?string {
		if ( '' === $value ) {
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
			$raw   = $input[ $name ] ?? '';
			$value = is_scalar( $raw ) ? trim( sanitize_text_field( (string) $raw ) ) : '';
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
		$session              = ctype_digit( $values['session_id'] ) ? Catalog_Repository::get_session( (int) $values['session_id'] ) : null;

		if ( ! $session ) {
			$errors['session_id'] = __( 'Kies een datum waarvoor je je wilt inschrijven.', 'gezonder-in-werk-booking' );
		} elseif ( ! Catalog_Repository::is_bookable( $session ) ) {
			$errors['session_id'] = __( 'Inschrijven voor deze datum is gesloten.', 'gezonder-in-werk-booking' );
		} else {
			$values['price_cents'] = (string) (int) $session['price_cents']; // Snapshot.
			if ( ! isset( $errors['email'] ) && Enrolment_Repository::has_active( (int) $session['id'], $values['email'] ) ) {
				$errors['email'] = __( 'Dit e-mailadres is al ingeschreven voor deze datum.', 'gezonder-in-werk-booking' );
			}
		}

		return array(
			'values' => $values,
			'errors' => $errors,
		);
	}
}
