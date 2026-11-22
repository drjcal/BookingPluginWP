<?php
namespace GezonderInWerk\Booking;

defined( 'ABSPATH' ) || exit;

/**
 * [gezonder_inschrijfformulier] renders the enrolment form.
 * assets/form.js submits it to the REST endpoint and shows inline errors.
 */
class Form_Shortcode {

	const TAG = 'gezonder_inschrijfformulier';

	public static function register(): void {
		add_shortcode( self::TAG, array( self::class, 'render' ) );
	}

	public static function render(): string {
		$url = plugins_url( 'assets/', GZW_BOOKING_FILE );
		wp_enqueue_style( 'gzw-booking-form', $url . 'form.css', array(), VERSION );
		wp_enqueue_script( 'gzw-booking-form', $url . 'form.js', array(), VERSION, true );
		wp_localize_script(
			'gzw-booking-form',
			'gzwBooking',
			array( 'endpoint' => esc_url_raw( rest_url( Rest_Controller::NAMESPACE . '/enrolments' ) ) )
		);

		ob_start();
		?>
		<form class="gzw-form" method="post" novalidate data-gzw-form>
			<div class="gzw-form__status" role="status" aria-live="polite" tabindex="-1" data-gzw-status></div>
			<fieldset>
				<legend><?php esc_html_e( 'Kies een datum', 'gezonder-in-werk-booking' ); ?></legend>
				<p class="gzw-form__field">
					<label for="gzw-session_id"><?php esc_html_e( 'Cursus en datum', 'gezonder-in-werk-booking' ); ?> <span aria-hidden="true">*</span></label>
					<select id="gzw-session_id" name="session_id" required aria-required="true">
						<option value=""><?php esc_html_e( 'Maak een keuze', 'gezonder-in-werk-booking' ); ?></option>
						<?php $cycles = Catalog_Repository::open_cycles(); ?>
						<?php if ( $cycles ) : ?>
							<optgroup label="<?php esc_attr_e( 'Hele cyclus', 'gezonder-in-werk-booking' ); ?>">
								<?php foreach ( $cycles as $cycle ) : ?>
									<option value="<?php echo esc_attr( 'cycle-' . $cycle['id'] ); ?>"><?php echo esc_html( Labels::cycle( $cycle ) ); ?></option>
								<?php endforeach; ?>
							</optgroup>
							<optgroup label="<?php esc_attr_e( 'Losse webinars', 'gezonder-in-werk-booking' ); ?>">
						<?php endif; ?>
						<?php foreach ( Catalog_Repository::open_sessions() as $session ) : ?>
							<option value="<?php echo esc_attr( $session['id'] ); ?>"><?php echo esc_html( Labels::session( $session ) ); ?></option>
						<?php endforeach; ?>
						<?php if ( $cycles ) : ?>
							</optgroup>
						<?php endif; ?>
					</select>
					<span class="gzw-form__error" id="gzw-session_id-error" data-gzw-error="session_id"></span>
				</p>
			</fieldset>
			<fieldset>
				<legend><?php esc_html_e( 'Jouw gegevens', 'gezonder-in-werk-booking' ); ?></legend>
				<?php self::fields( Enrolment_Fields::in_group( Enrolment_Fields::GROUP_PERSON ) ); ?>
				<p class="gzw-form__hint" id="gzw-big-hint">
					<?php esc_html_e( 'We gebruiken je BIG-nummer alleen om accreditatiepunten voor je te registreren.', 'gezonder-in-werk-booking' ); ?>
				</p>
			</fieldset>
			<fieldset>
				<legend><?php esc_html_e( 'Factuurgegevens', 'gezonder-in-werk-booking' ); ?></legend>
				<?php self::fields( Enrolment_Fields::in_group( Enrolment_Fields::GROUP_BILLING ) ); ?>
			</fieldset>
			<fieldset>
				<legend><?php echo esc_html( Enrolment_Fields::groups()[ Enrolment_Fields::GROUP_CONSENT ] ); ?></legend>
				<?php self::fields( Enrolment_Fields::in_group( Enrolment_Fields::GROUP_CONSENT ) ); ?>
			</fieldset>
			<input type="hidden" name="<?php echo esc_attr( Spam_Guard::NONCE_FIELD ); ?>" value="<?php echo esc_attr( Spam_Guard::nonce() ); ?>" />
			<div class="gzw-form__trap" aria-hidden="true">
				<label for="gzw-trap"><?php esc_html_e( 'Laat dit veld leeg', 'gezonder-in-werk-booking' ); ?></label>
				<input type="text" id="gzw-trap" name="<?php echo esc_attr( Spam_Guard::HONEYPOT_FIELD ); ?>" value="" tabindex="-1" autocomplete="off" />
			</div>
			<button type="submit"><?php esc_html_e( 'Inschrijven', 'gezonder-in-werk-booking' ); ?></button>
		</form>
		<?php
		return (string) ob_get_clean();
	}

	/** @param array<string,array<string,mixed>> $fields Field definitions keyed by name. */
	private static function fields( array $fields ): void {
		foreach ( $fields as $name => $field ) {
			if ( ! Enrolment_Fields::is_input( $field ) ) {
				continue;
			}
			$id    = 'gzw-' . $name;
			$label = $field['label'] . ( $field['required'] ? '' : ' ' . __( '(optioneel)', 'gezonder-in-werk-booking' ) );
			if ( 'checkbox' === $field['type'] ) {
				?>
			<p class="gzw-form__field gzw-form__field--checkbox">
				<label for="<?php echo esc_attr( $id ); ?>">
					<input type="checkbox" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" value="1" <?php echo $field['required'] ? 'required aria-required="true"' : ''; ?> />
					<?php echo esc_html( $label ); ?>
					<?php if ( $field['required'] ) : ?><span aria-hidden="true"> *</span><?php endif; ?>
				</label>
				<span class="gzw-form__error" id="<?php echo esc_attr( $id . '-error' ); ?>" data-gzw-error="<?php echo esc_attr( $name ); ?>"></span>
			</p>
				<?php
				continue;
			}
			?>
			<p class="gzw-form__field">
				<label for="<?php echo esc_attr( $id ); ?>">
					<?php echo esc_html( $label ); ?>
					<?php if ( $field['required'] ) : ?><span aria-hidden="true"> *</span><?php endif; ?>
				</label>
				<input
					type="<?php echo esc_attr( $field['type'] ); ?>"
					id="<?php echo esc_attr( $id ); ?>"
					name="<?php echo esc_attr( $name ); ?>"
					autocomplete="<?php echo esc_attr( $field['autocomplete'] ); ?>"
					<?php echo $field['required'] ? 'required aria-required="true"' : ''; ?>
					<?php echo isset( $field['inputmode'] ) ? 'inputmode="' . esc_attr( $field['inputmode'] ) . '"' : ''; ?>
					<?php echo isset( $field['describedby'] ) ? 'aria-describedby="' . esc_attr( $field['describedby'] ) . '"' : ''; ?>
				/>
				<span class="gzw-form__error" id="<?php echo esc_attr( $id . '-error' ); ?>" data-gzw-error="<?php echo esc_attr( $name ); ?>"></span>
			</p>
			<?php
		}
	}
}
