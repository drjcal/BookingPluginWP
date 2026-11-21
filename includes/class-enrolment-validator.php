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

		return array(
			'values' => $values,
			'errors' => $errors,
		);
	}
}
