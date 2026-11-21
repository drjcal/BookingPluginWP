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

	/** Form value of the select option that stands for a whole cycle: "cycle-<id>". */
	public static function cycle_id_from( string $target ): int {
		return preg_match( '/^cycle-(\d+)$/', $target, $m ) ? (int) $m[1] : 0;
	}

	/**
	 * @param array $input Raw request values.
	 * @return array{values: array<string,string>, errors: array<string,string>, cycle: ?array}
	 *         cycle: the cycle (with its sessions) when the submission is for a whole cycle.
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
		$cycle_id             = self::cycle_id_from( $values['session_id'] );
		$cycle                = $cycle_id ? Catalog_Repository::get_cycle( $cycle_id ) : null;
		$session              = ( ! $cycle_id && ctype_digit( $values['session_id'] ) ) ? Catalog_Repository::get_session( (int) $values['session_id'] ) : null;

		if ( $cycle_id ) {
			if ( ! $cycle || ! $cycle['sessions'] ) {
				$cycle                = null;
				$errors['session_id'] = __( 'Kies een datum waarvoor je je wilt inschrijven.', 'gezonder-in-werk-booking' );
			} elseif ( ! Catalog_Repository::is_cycle_bookable( $cycle ) ) {
				$errors['session_id'] = __( 'Inschrijven voor (een deel van) deze cyclus is gesloten.', 'gezonder-in-werk-booking' );
			} else {
				$values['price_cents'] = (string) (int) $cycle['price_cents']; // Snapshot of the bundle price.
				foreach ( $cycle['sessions'] as $cycle_session ) {
					if ( ! isset( $errors['email'] ) && Enrolment_Repository::has_active( (int) $cycle_session['id'], $values['email'] ) ) {
						$errors['email'] = __( 'Dit e-mailadres is al ingeschreven voor een of meer webinars van deze cyclus.', 'gezonder-in-werk-booking' );
						break;
					}
				}
			}
		} elseif ( ! $session ) {
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
			'cycle'  => $errors ? null : $cycle,
		);
	}
}
