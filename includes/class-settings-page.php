<?php
namespace GezonderInWerk\Booking;

defined( 'ABSPATH' ) || exit;

/**
 * Settings screen (capability: manage_options): the address that receives new-enrolment notifications.
 */
class Settings_Page {

	const SLUG                      = 'gzw-settings';
	const OPTION_NOTIFICATION_EMAIL = 'gzw_booking_notification_email';
	const OPTION_RETENTION_MONTHS   = 'gzw_booking_retention_months';
	const OPTION_LOGO_ID            = 'gzw_booking_logo_id';
	const OPTION_LOGO_URL           = 'gzw_booking_logo_url';
	const OPTION_ORG_NAME           = 'gzw_booking_org_name';
	const OPTION_CONTACT_EMAIL      = 'gzw_booking_contact_email';

	const OPTION_SNELSTART_JOURNAL  = 'gzw_booking_snelstart_journal';
	const OPTION_SNELSTART_DEBTORS  = 'gzw_booking_snelstart_debtors';
	const OPTION_SNELSTART_REVENUE  = 'gzw_booking_snelstart_revenue';
	const OPTION_SNELSTART_VAT      = 'gzw_booking_snelstart_vat';

	const DEFAULT_RETENTION_MONTHS = 24;
	const MAX_RETENTION_MONTHS     = 120;

	public static function register_menu(): void {
		add_submenu_page(
			Admin_Page::SLUG,
			__( 'Instellingen', 'gezonder-in-werk-booking' ),
			__( 'Instellingen', 'gezonder-in-werk-booking' ),
			Admin_Page::CAP,
			self::SLUG,
			array( self::class, 'render' )
		);
	}

	public static function enqueue_scripts( string $hook ): void {
		if ( false === strpos( $hook, self::SLUG ) ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_script( 'gzw-booking-settings', plugins_url( 'assets/settings.js', GZW_BOOKING_FILE ), array( 'jquery' ), VERSION, true );
		wp_localize_script(
			'gzw-booking-settings',
			'gzwSettings',
			array(
				'title'  => __( 'Selecteer logo', 'gezonder-in-werk-booking' ),
				'button' => __( 'Gebruik als logo', 'gezonder-in-werk-booking' ),
			)
		);
	}

	public static function register_settings(): void {
		register_setting(
			'gzw_settings',
			self::OPTION_NOTIFICATION_EMAIL,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( self::class, 'sanitize_email' ),
				'default'           => '',
			)
		);
		register_setting(
			'gzw_settings',
			self::OPTION_CONTACT_EMAIL,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( self::class, 'sanitize_email' ),
				'default'           => 'info@gezonderinwerk.nl',
			)
		);
		register_setting(
			'gzw_settings',
			self::OPTION_ORG_NAME,
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);
		register_setting(
			'gzw_settings',
			self::OPTION_LOGO_ID,
			array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'default'           => 0,
			)
		);
		register_setting(
			'gzw_settings',
			self::OPTION_LOGO_URL,
			array(
				'type'              => 'string',
				'sanitize_callback' => 'esc_url_raw',
				'default'           => '',
			)
		);
		register_setting(
			'gzw_settings',
			self::OPTION_RETENTION_MONTHS,
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( self::class, 'sanitize_retention' ),
				'default'           => self::DEFAULT_RETENTION_MONTHS,
			)
		);
		foreach ( array_keys( self::snelstart_fields() ) as $option ) {
			register_setting(
				'gzw_settings',
				$option,
				array(
					'type'              => 'string',
					'sanitize_callback' => array( self::class, 'sanitize_ledger_number' ),
					'default'           => '',
				)
			);
		}
		register_setting(
			'gzw_settings',
			Installer::OPTION_REMOVE_ON_UNINSTALL,
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( self::class, 'sanitize_flag' ),
				'default'           => 0,
			)
		);
	}

	/** @return array<string,string> Option name => label of the SnelStart numbers. */
	private static function snelstart_fields(): array {
		return array(
			self::OPTION_SNELSTART_JOURNAL => __( 'SnelStart (experimenteel): dagboek verkoop', 'gezonder-in-werk-booking' ),
			self::OPTION_SNELSTART_DEBTORS => __( 'SnelStart: grootboek debiteuren', 'gezonder-in-werk-booking' ),
			self::OPTION_SNELSTART_REVENUE => __( 'SnelStart: grootboek omzet', 'gezonder-in-werk-booking' ),
			self::OPTION_SNELSTART_VAT     => __( 'SnelStart: grootboek BTW hoog', 'gezonder-in-werk-booking' ),
		);
	}

	/** Digits only, or '' when the value holds anything else. */
	public static function sanitize_ledger_number( $value ): string {
		$value = trim( (string) $value );
		return ctype_digit( $value ) ? $value : '';
	}

	/**
	 * The SnelStart numbers, or null while any of them is missing (no attachment is made then).
	 *
	 * @return ?array{journal:string,debtors:string,revenue:string,vat:string}
	 */
	public static function snelstart_ledgers(): ?array {
		$ledgers = array(
			'journal' => self::sanitize_ledger_number( get_option( self::OPTION_SNELSTART_JOURNAL, '' ) ),
			'debtors' => self::sanitize_ledger_number( get_option( self::OPTION_SNELSTART_DEBTORS, '' ) ),
			'revenue' => self::sanitize_ledger_number( get_option( self::OPTION_SNELSTART_REVENUE, '' ) ),
			'vat'     => self::sanitize_ledger_number( get_option( self::OPTION_SNELSTART_VAT, '' ) ),
		);
		return in_array( '', $ledgers, true ) ? null : $ledgers;
	}

	/** Whole months between 0 (never clean up) and the maximum. */
	public static function sanitize_retention( $value ): int {
		return min( self::MAX_RETENTION_MONTHS, max( 0, (int) $value ) );
	}

	/** A checkbox: 1 when ticked, otherwise 0. */
	public static function sanitize_flag( $value ): int {
		return empty( $value ) ? 0 : 1;
	}

	/** Months after which enrolments are anonymised; 0 means never. */
	public static function retention_months(): int {
		return self::sanitize_retention( get_option( self::OPTION_RETENTION_MONTHS, self::DEFAULT_RETENTION_MONTHS ) );
	}

	/** Trimmed address, or '' when it is not a valid e-mail address. */
	public static function sanitize_email( $value ): string {
		$email = sanitize_email( trim( (string) $value ) );
		return is_email( $email ) ? $email : '';
	}

	/** Where new-enrolment notifications go; the site admin address until one is configured. */
	public static function notification_email(): string {
		$email = self::sanitize_email( get_option( self::OPTION_NOTIFICATION_EMAIL, '' ) );
		return '' !== $email ? $email : (string) get_option( 'admin_email' );
	}

	/** Organisation name shown in email header (fallback) and footer. */
	public static function org_name(): string {
		$name = trim( (string) get_option( self::OPTION_ORG_NAME, '' ) );
		if ( '' !== $name ) {
			return $name;
		}
		$site_name = trim( (string) get_bloginfo( 'name' ) );
		return '' !== $site_name ? $site_name : 'Gezonder in Werk B.V.';
	}

	/** Contact address shown in email footer; defaults to info@gezonderinwerk.nl. */
	public static function contact_email(): string {
		$email = self::sanitize_email( get_option( self::OPTION_CONTACT_EMAIL, '' ) );
		return '' !== $email ? $email : 'info@gezonderinwerk.nl';
	}

	/** Public website URL linked from email header and footer. */
	public static function website_url(): string {
		$url = (string) home_url( '/' );
		return '' !== $url ? $url : 'https://gezonderinwerk.nl';
	}

	/**
	 * Absolute URL to the header logo, or '' when no logo is configured.
	 * Uses the attachment ID when set, otherwise the direct URL setting.
	 */
	public static function logo_url(): string {
		$id = absint( get_option( self::OPTION_LOGO_ID, 0 ) );
		if ( $id > 0 ) {
			// A chosen attachment wins; if it was deleted, show no logo rather than a stale URL.
			return (string) wp_get_attachment_image_url( $id, 'full' );
		}
		$url = trim( (string) get_option( self::OPTION_LOGO_URL, '' ) );
		return '' !== $url ? esc_url_raw( $url ) : '';
	}

	public static function render(): void {
		if ( ! current_user_can( Admin_Page::CAP ) ) {
			wp_die( esc_html__( 'Geen toegang.', 'gezonder-in-werk-booking' ), 403 );
		}
		$logo_url = self::logo_url();
		$logo_id  = absint( get_option( self::OPTION_LOGO_ID, 0 ) );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Instellingen', 'gezonder-in-werk-booking' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'gzw_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="gzw-notification-email"><?php esc_html_e( 'Meldingen naar', 'gezonder-in-werk-booking' ); ?></label></th>
						<td>
							<input type="email" id="gzw-notification-email" class="regular-text" name="<?php echo esc_attr( self::OPTION_NOTIFICATION_EMAIL ); ?>" value="<?php echo esc_attr( (string) get_option( self::OPTION_NOTIFICATION_EMAIL, '' ) ); ?>" placeholder="<?php echo esc_attr( (string) get_option( 'admin_email' ) ); ?>" />
							<p class="description"><?php esc_html_e( 'Dit adres ontvangt een e-mail bij elke nieuwe inschrijving. Leeg laten gebruikt het beheerdersadres van de site.', 'gezonder-in-werk-booking' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="gzw-contact-email"><?php esc_html_e( 'Contact e-mailadres', 'gezonder-in-werk-booking' ); ?></label></th>
						<td>
							<input type="email" id="gzw-contact-email" class="regular-text" name="<?php echo esc_attr( self::OPTION_CONTACT_EMAIL ); ?>" value="<?php echo esc_attr( (string) get_option( self::OPTION_CONTACT_EMAIL, '' ) ); ?>" placeholder="info@gezonderinwerk.nl" />
							<p class="description"><?php esc_html_e( 'Dit adres wordt getoond in de voettekst van bevestigingsmails. Standaard: info@gezonderinwerk.nl.', 'gezonder-in-werk-booking' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="gzw-org-name"><?php esc_html_e( 'Organisatienaam', 'gezonder-in-werk-booking' ); ?></label></th>
						<td>
							<input type="text" id="gzw-org-name" class="regular-text" name="<?php echo esc_attr( self::OPTION_ORG_NAME ); ?>" value="<?php echo esc_attr( (string) get_option( self::OPTION_ORG_NAME, '' ) ); ?>" placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ?: 'Gezonder in Werk B.V.' ); ?>" />
							<p class="description"><?php esc_html_e( 'Wordt getoond in de koptekst (als tekst-terugval zonder logo) en in de voettekst van alle e-mails.', 'gezonder-in-werk-booking' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="gzw-logo-select-button"><?php esc_html_e( 'Logo in e-mails', 'gezonder-in-werk-booking' ); ?></label></th>
						<td>
							<input type="hidden" id="gzw-logo-id" name="<?php echo esc_attr( self::OPTION_LOGO_ID ); ?>" value="<?php echo esc_attr( $logo_id ? (string) $logo_id : '' ); ?>" />
							<input type="hidden" id="gzw-logo-url" name="<?php echo esc_attr( self::OPTION_LOGO_URL ); ?>" value="<?php echo esc_attr( (string) get_option( self::OPTION_LOGO_URL, '' ) ); ?>" />
							<div id="gzw-logo-preview-wrap" style="margin-bottom: 10px;">
								<img id="gzw-logo-preview" src="<?php echo esc_url( $logo_url ); ?>" alt="<?php esc_attr_e( 'Logo voorbeeld', 'gezonder-in-werk-booking' ); ?>" style="max-width: 200px; height: auto; display: <?php echo $logo_url ? 'block' : 'none'; ?>; border: 1px solid #ddd; padding: 4px; background: #fff;" />
							</div>
							<button type="button" id="gzw-logo-select-button" class="button"><?php esc_html_e( 'Selecteer logo uit mediabibliotheek', 'gezonder-in-werk-booking' ); ?></button>
							<button type="button" id="gzw-logo-remove-button" class="button" style="display: <?php echo $logo_url ? 'inline-block' : 'none'; ?>;"><?php esc_html_e( 'Verwijder logo', 'gezonder-in-werk-booking' ); ?></button>
							<p class="description"><?php esc_html_e( 'Kies een logo uit de WordPress mediabibliotheek (ca. 160–200 px breed, bij voorkeur transparante achtergrond). Zonder logo wordt de organisatienaam als tekst getoond.', 'gezonder-in-werk-booking' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="gzw-retention"><?php esc_html_e( 'Bewaartermijn (maanden)', 'gezonder-in-werk-booking' ); ?></label></th>
						<td>
							<input type="number" id="gzw-retention" class="small-text" min="0" max="<?php echo esc_attr( (string) self::MAX_RETENTION_MONTHS ); ?>" name="<?php echo esc_attr( self::OPTION_RETENTION_MONTHS ); ?>" value="<?php echo esc_attr( (string) self::retention_months() ); ?>" />
							<p class="description"><?php esc_html_e( 'Inschrijvingen waarvan de datum zo lang geleden is, worden dagelijks geanonimiseerd: persoonsgegevens worden gewist, prijs en status blijven bewaard. Bij een cyclus gebeurt dat voor alle webinars tegelijk, na het laatste webinar. 0 bewaart alles.', 'gezonder-in-werk-booking' ); ?></p>
						</td>
					</tr>
					<?php foreach ( self::snelstart_fields() as $option => $label ) : ?>
						<tr>
							<th><label for="<?php echo esc_attr( $option ); ?>"><?php echo esc_html( $label ); ?></label></th>
							<td>
								<input type="text" inputmode="numeric" id="<?php echo esc_attr( $option ); ?>" class="small-text" name="<?php echo esc_attr( $option ); ?>" value="<?php echo esc_attr( (string) get_option( $option, '' ) ); ?>" />
								<?php if ( self::OPTION_SNELSTART_VAT === $option ) : ?>
									<p class="description"><?php esc_html_e( 'Experimentele functie (bèta): de uitvoer is nog niet getoetst in een echte SnelStart-import. Met alle vier de nummers ingevuld krijgt de melding per e-mail een SnelStart-boekingsbestand (CSV) als bijlage. Klantcode en factuurnummer vult u zelf in voor het importeren. Leeg laten betekent geen bijlage.', 'gezonder-in-werk-booking' ); ?></p>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					<tr>
						<th><?php esc_html_e( 'Verwijderen van de plugin', 'gezonder-in-werk-booking' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( Installer::OPTION_REMOVE_ON_UNINSTALL ); ?>" value="1" <?php checked( 1, (int) get_option( Installer::OPTION_REMOVE_ON_UNINSTALL, 0 ) ); ?> />
								<?php esc_html_e( 'Verwijder alle gegevens (inschrijvingen, cursussen, instellingen) wanneer de plugin wordt verwijderd', 'gezonder-in-werk-booking' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Standaard blijven de inschrijvingen bewaard, omdat het persoonsgegevens zijn.', 'gezonder-in-werk-booking' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
