<?php
namespace GezonderInWerk\Booking;

defined( 'ABSPATH' ) || exit;

/**
 * Server-side validation: authoritative for the enrolment form.
 */
class Enrolment_Validator {

	/** field => required? */
	const FIELDS = array(
		'first_name'        => true,
		'last_name'         => true,
		'email'             => true,
		'employer'          => true,
		'big_number'        => false,
		'invoice_name'      => true,
		'invoice_street'    => true,
		'invoice_postcode'  => true,
		'invoice_city'      => true,
		'invoice_email'     => false,
		'invoice_reference' => false,
		'invoice_kvk'       => false,
		'invoice_btw'       => false,
	);

	/**
	 * @param array $input Raw request values.
	 * @return array{values: array<string,string>, errors: array<string,string>}
	 */
	public static function validate( array $input ): array {
		$values = array();
		$errors = array();

		foreach ( self::FIELDS as $field => $required ) {
			$raw = $input[ $field ] ?? '';
			$values[ $field ] = is_scalar( $raw ) ? trim( sanitize_text_field( (string) $raw ) ) : '';

			if ( $required && '' === $values[ $field ] ) {
				$errors[ $field ] = __( 'Dit veld is verplicht.', 'gezonder-in-werk-booking' );
			}
		}

		foreach ( $values as $field => $value ) {
			if ( ! isset( $errors[ $field ] ) && mb_strlen( $value ) > 100 ) {
				$errors[ $field ] = __( 'Deze waarde is te lang (maximaal 100 tekens).', 'gezonder-in-werk-booking' );
			}
		}

		foreach ( array( 'email', 'invoice_email' ) as $field ) {
			if ( '' !== $values[ $field ] && ! isset( $errors[ $field ] ) && ! is_email( $values[ $field ] ) ) {
				$errors[ $field ] = __( 'Vul een geldig e-mailadres in.', 'gezonder-in-werk-booking' );
			}
		}

		if ( '' !== $values['big_number'] && ! preg_match( '/^\d{11}$/', $values['big_number'] ) ) {
			$errors['big_number'] = __( 'Een BIG-registratienummer bestaat uit 11 cijfers.', 'gezonder-in-werk-booking' );
		}

		$raw_session          = $input['session_id'] ?? '';
		$values['session_id'] = is_scalar( $raw_session ) ? trim( (string) $raw_session ) : '';
		$session              = ctype_digit( $values['session_id'] ) ? Catalog_Repository::get_session( (int) $values['session_id'] ) : null;

		if ( ! $session ) {
			$errors['session_id'] = __( 'Kies een datum waarvoor je je wilt inschrijven.', 'gezonder-in-werk-booking' );
		} elseif ( ! (int) $session['is_open'] ) {
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
