<?php
namespace GezonderInWerk\Booking;

defined( 'ABSPATH' ) || exit;

/**
 * Settings screen (capability: manage_options): the address that receives new-enrolment notifications.
 */
class Settings_Page {

	const SLUG                      = 'gzw-settings';
	const OPTION_NOTIFICATION_EMAIL = 'gzw_booking_notification_email';

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
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
