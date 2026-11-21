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

		$person  = array(
			'first_name' => array( __( 'Voornaam', 'gezonder-in-werk-booking' ), 'text', 'given-name', true ),
			'last_name'  => array( __( 'Achternaam', 'gezonder-in-werk-booking' ), 'text', 'family-name', true ),
			'email'      => array( __( 'E-mailadres', 'gezonder-in-werk-booking' ), 'email', 'email', true ),
			'employer'   => array( __( 'Werkzaam bij', 'gezonder-in-werk-booking' ), 'text', 'organization', true ),
			'big_number' => array( __( 'BIG-registratienummer (optioneel)', 'gezonder-in-werk-booking' ), 'text', 'off', false ),
		);
		$billing = array(
			'invoice_name'      => array( __( 'Factuurnaam', 'gezonder-in-werk-booking' ), 'text', 'organization', true ),
			'invoice_street'    => array( __( 'Straat en huisnummer', 'gezonder-in-werk-booking' ), 'text', 'street-address', true ),
			'invoice_postcode'  => array( __( 'Postcode', 'gezonder-in-werk-booking' ), 'text', 'postal-code', true ),
			'invoice_city'      => array( __( 'Plaats', 'gezonder-in-werk-booking' ), 'text', 'address-level2', true ),
			'invoice_email'     => array( __( 'Factuur e-mailadres (optioneel)', 'gezonder-in-werk-booking' ), 'email', 'off', false ),
			'invoice_reference' => array( __( 'PO- of referentienummer (optioneel)', 'gezonder-in-werk-booking' ), 'text', 'off', false ),
			'invoice_kvk'       => array( __( 'KvK-nummer (optioneel)', 'gezonder-in-werk-booking' ), 'text', 'off', false ),
			'invoice_btw'       => array( __( 'BTW-nummer (optioneel)', 'gezonder-in-werk-booking' ), 'text', 'off', false ),
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
						<?php foreach ( Catalog_Repository::open_sessions() as $session ) : ?>
							<option value="<?php echo esc_attr( $session['id'] ); ?>"><?php echo esc_html( Catalog_Repository::format_session( $session ) ); ?></option>
						<?php endforeach; ?>
					</select>
					<span class="gzw-form__error" id="gzw-session_id-error" data-gzw-error="session_id"></span>
				</p>
			</fieldset>
			<fieldset>
				<legend><?php esc_html_e( 'Jouw gegevens', 'gezonder-in-werk-booking' ); ?></legend>
				<?php self::fields( $person ); ?>
				<p class="gzw-form__hint" id="gzw-big-hint">
					<?php esc_html_e( 'We gebruiken je BIG-nummer alleen om accreditatiepunten voor je te registreren.', 'gezonder-in-werk-booking' ); ?>
				</p>
			</fieldset>
			<fieldset>
				<legend><?php esc_html_e( 'Factuurgegevens', 'gezonder-in-werk-booking' ); ?></legend>
				<?php self::fields( $billing ); ?>
			</fieldset>
			<button type="submit"><?php esc_html_e( 'Inschrijven', 'gezonder-in-werk-booking' ); ?></button>
		</form>
		<?php
		return (string) ob_get_clean();
	}

	private static function fields( array $fields ): void {
		foreach ( $fields as $name => list( $label, $type, $autocomplete, $required ) ) {
			$id = 'gzw-' . $name;
			?>
			<p class="gzw-form__field">
				<label for="<?php echo esc_attr( $id ); ?>">
					<?php echo esc_html( $label ); ?>
					<?php if ( $required ) : ?><span aria-hidden="true"> *</span><?php endif; ?>
				</label>
				<input
					type="<?php echo esc_attr( $type ); ?>"
					id="<?php echo esc_attr( $id ); ?>"
					name="<?php echo esc_attr( $name ); ?>"
					autocomplete="<?php echo esc_attr( $autocomplete ); ?>"
					<?php echo $required ? 'required aria-required="true"' : ''; ?>
					<?php echo 'big_number' === $name ? 'inputmode="numeric" maxlength="11" aria-describedby="gzw-big-hint"' : ''; ?>
				/>
				<span class="gzw-form__error" id="<?php echo esc_attr( $id . '-error' ); ?>" data-gzw-error="<?php echo esc_attr( $name ); ?>"></span>
			</p>
			<?php
		}
	}
}
