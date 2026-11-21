<?php
namespace GezonderInWerk\Booking;

defined( 'ABSPATH' ) || exit;

/**
 * WP admin: define webinar cycles (a set of sessions sold together at a bundle price).
 * Submenu of Cursussen; same capability.
 */
class Cycles_Admin {

	const SLUG = 'gzw-cycles';

	public static function register_menu(): void {
		add_submenu_page(
			Admin_Page::SLUG,
			__( 'Cycli', 'gezonder-in-werk-booking' ),
			__( 'Cycli', 'gezonder-in-werk-booking' ),
			Admin_Page::CAP,
			self::SLUG,
			array( self::class, 'render' )
		);
	}

	private static function back( string $notice, int $cycle_id = 0 ): void {
		$args = array( 'page' => self::SLUG, 'gzw_notice' => $notice );
		if ( $cycle_id ) {
			$args['cycle'] = $cycle_id;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function handle_save(): void {
		Admin_Page::guard( 'gzw_save_cycle' );
		$id    = absint( Admin_Page::post( 'id' ) );
		$title = Admin_Page::post( 'title' );
		$cents = Admin_Page::euros_to_cents( Admin_Page::post( 'price' ) );
		// phpcs:ignore WordPress.Security.NonceVerification -- checked in guard().
		$raw_ids     = isset( $_POST['session_ids'] ) && is_array( $_POST['session_ids'] ) ? array_map( 'absint', wp_unslash( $_POST['session_ids'] ) ) : array();
		$known       = array_map( static fn( array $s ) => (int) $s['id'], Catalog_Repository::all_sessions() );
		$session_ids = array_values( array_intersect( array_unique( $raw_ids ), $known ) );

		if ( '' === $title || null === $cents || count( $session_ids ) < 2 || ( $id && ! Catalog_Repository::get_cycle( $id ) ) ) {
			self::back( 'invalid_cycle', $id );
		}
		$saved = Catalog_Repository::save_cycle( array( 'title' => $title, 'price_cents' => $cents, 'session_ids' => $session_ids ), $id );
		self::back( $saved ? 'saved' : 'error', $saved );
	}

	public static function handle_delete(): void {
		Admin_Page::guard( 'gzw_delete_cycle' );
		$ok = Catalog_Repository::delete_cycle( absint( Admin_Page::post( 'id' ) ) );
		self::back( $ok ? 'deleted' : 'error' );
	}

	private static function notice(): void {
		$messages = array(
			'saved'         => array( 'success', __( 'Opgeslagen.', 'gezonder-in-werk-booking' ) ),
			'deleted'       => array( 'success', __( 'Verwijderd.', 'gezonder-in-werk-booking' ) ),
			'invalid_cycle' => array( 'error', __( 'Vul een titel, een geldige bundelprijs (bijv. 249,00) en minimaal twee datums in.', 'gezonder-in-werk-booking' ) ),
			'error'         => array( 'error', __( 'Opslaan mislukt.', 'gezonder-in-werk-booking' ) ),
		);
		$key = isset( $_GET['gzw_notice'] ) ? sanitize_key( wp_unslash( $_GET['gzw_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( isset( $messages[ $key ] ) ) {
			printf( '<div class="notice notice-%s"><p>%s</p></div>', esc_attr( $messages[ $key ][0] ), esc_html( $messages[ $key ][1] ) );
		}
	}

	public static function render(): void {
		if ( ! current_user_can( Admin_Page::CAP ) ) {
			wp_die( esc_html__( 'Geen toegang.', 'gezonder-in-werk-booking' ), 403 );
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'Cycli', 'gezonder-in-werk-booking' ) . '</h1>';
		self::notice();

		if ( isset( $_GET['cycle'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$id    = absint( $_GET['cycle'] ); // phpcs:ignore WordPress.Security.NonceVerification
			$cycle = $id ? Catalog_Repository::get_cycle( $id ) : array();
			if ( null === $cycle ) {
				echo '<p>' . esc_html__( 'Cyclus niet gevonden.', 'gezonder-in-werk-booking' ) . '</p>';
			} else {
				self::render_form( $cycle ?: array(), $id );
			}
		} else {
			self::render_list();
		}
		echo '</div>';
	}

	private static function render_list(): void {
		$new = add_query_arg( array( 'page' => self::SLUG, 'cycle' => 0 ), admin_url( 'admin.php' ) );
		echo '<a class="page-title-action" href="' . esc_url( $new ) . '">' . esc_html__( 'Nieuwe cyclus', 'gezonder-in-werk-booking' ) . '</a>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Titel', 'gezonder-in-werk-booking' ) . '</th><th>' . esc_html__( 'Bundelprijs excl. BTW', 'gezonder-in-werk-booking' ) . '</th><th>' . esc_html__( 'Datums', 'gezonder-in-werk-booking' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( Catalog_Repository::cycles() as $row ) {
			$cycle = Catalog_Repository::get_cycle( (int) $row['id'] );
			$edit  = add_query_arg( array( 'page' => self::SLUG, 'cycle' => $row['id'] ), admin_url( 'admin.php' ) );
			echo '<tr><td><a href="' . esc_url( $edit ) . '">' . esc_html( $row['title'] ) . '</a></td><td>' . esc_html( Catalog_Repository::format_price( (int) $row['price_cents'] ) ) . '</td><td>' . esc_html( (string) count( $cycle['sessions'] ) ) . '</td><td>';
			Admin_Page::action_form( 'gzw_delete_cycle', array( 'id' => $row['id'] ), __( 'Verwijderen', 'gezonder-in-werk-booking' ), 'button-link-delete' );
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function render_form( array $cycle, int $id ): void {
		$cycle   += array( 'title' => '', 'price_cents' => 0, 'sessions' => array() );
		$selected = array_map( static fn( array $s ) => (int) $s['id'], $cycle['sessions'] );
		?>
		<h2><?php echo $id ? esc_html__( 'Cyclus bewerken', 'gezonder-in-werk-booking' ) : esc_html__( 'Nieuwe cyclus', 'gezonder-in-werk-booking' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="gzw_save_cycle" />
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $id ); ?>" />
			<?php wp_nonce_field( 'gzw_save_cycle' ); ?>
			<table class="form-table" role="presentation">
				<tr><th><label for="gzw-y-title"><?php esc_html_e( 'Titel', 'gezonder-in-werk-booking' ); ?></label></th>
					<td><input id="gzw-y-title" name="title" class="regular-text" required value="<?php echo esc_attr( $cycle['title'] ); ?>" /></td></tr>
				<tr><th><label for="gzw-y-price"><?php esc_html_e( 'Bundelprijs excl. BTW (€)', 'gezonder-in-werk-booking' ); ?></label></th>
					<td><input id="gzw-y-price" name="price" inputmode="decimal" required value="<?php echo esc_attr( number_format( ( (int) $cycle['price_cents'] ) / 100, 2, ',', '' ) ); ?>" /></td></tr>
				<tr><th><?php esc_html_e( 'Datums in de cyclus', 'gezonder-in-werk-booking' ); ?></th>
					<td><fieldset>
						<?php foreach ( array_reverse( Catalog_Repository::all_sessions() ) as $session ) : ?>
							<label style="display:block"><input type="checkbox" name="session_ids[]" value="<?php echo esc_attr( (string) $session['id'] ); ?>" <?php checked( in_array( (int) $session['id'], $selected, true ) ); ?> />
								<?php echo esc_html( $session['title'] . ' – ' . mysql2date( 'd-m-Y', $session['session_date'] ) . ', ' . $session['location'] ); ?></label>
						<?php endforeach; ?>
					</fieldset></td></tr>
			</table>
			<?php submit_button( __( 'Opslaan', 'gezonder-in-werk-booking' ) ); ?>
		</form>
		<?php
	}
}
