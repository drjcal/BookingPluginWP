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
			self::OPTION_RETENTION_MONTHS,
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( self::class, 'sanitize_retention' ),
				'default'           => self::DEFAULT_RETENTION_MONTHS,
			)
		);
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

	public static function render(): void {
		if ( ! current_user_can( Admin_Page::CAP ) ) {
			wp_die( esc_html__( 'Geen toegang.', 'gezonder-in-werk-booking' ), 403 );
		}
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
						<th><label for="gzw-retention"><?php esc_html_e( 'Bewaartermijn (maanden)', 'gezonder-in-werk-booking' ); ?></label></th>
						<td>
							<input type="number" id="gzw-retention" class="small-text" min="0" max="<?php echo esc_attr( (string) self::MAX_RETENTION_MONTHS ); ?>" name="<?php echo esc_attr( self::OPTION_RETENTION_MONTHS ); ?>" value="<?php echo esc_attr( (string) self::retention_months() ); ?>" />
							<p class="description"><?php esc_html_e( 'Inschrijvingen waarvan de datum zo lang geleden is, worden dagelijks geanonimiseerd: persoonsgegevens worden gewist, prijs en status blijven bewaard. Bij een cyclus gebeurt dat voor alle webinars tegelijk, na het laatste webinar. 0 bewaart alles.', 'gezonder-in-werk-booking' ); ?></p>
						</td>
					</tr>
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
