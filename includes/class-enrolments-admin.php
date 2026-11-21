<?php
namespace GezonderInWerk\Booking;

defined( 'ABSPATH' ) || exit;

/**
 * WP admin: enrolment list and detail view (capability: gzw_manage_enrolments).
 * Columns and detail rows are read from Enrolment_Fields.
 */
class Enrolments_Admin {

	const SLUG     = 'gzw-enrolments';
	const CAP      = Installer::CAP_MANAGE_ENROLMENTS;
	const PER_PAGE = 20;

	public static function register_menu(): void {
		add_menu_page(
			__( 'Inschrijvingen', 'gezonder-in-werk-booking' ),
			__( 'Inschrijvingen', 'gezonder-in-werk-booking' ),
			self::CAP,
			self::SLUG,
			array( self::class, 'render' ),
			'dashicons-groups'
		);
	}

	private static function deny(): void {
		wp_die( esc_html__( 'Geen toegang.', 'gezonder-in-werk-booking' ), 403 );
	}

	public static function status_label( string $status ): string {
		return Installer::STATUS_CANCELLED === $status
			? __( 'Geannuleerd', 'gezonder-in-werk-booking' )
			: __( 'Bevestigd', 'gezonder-in-werk-booking' );
	}

	/** "Ingeschreven op" cell: enrolment timestamp in site time. */
	public static function created_label( array $row ): string {
		return mysql2date( 'd-m-Y H:i', get_date_from_gmt( $row['created_at'] ) );
	}

	/** Session cell: course and date, or a placeholder when the session no longer matches. */
	public static function session_label( array $row ): string {
		if ( empty( $row['course_title'] ) ) {
			return __( 'Onbekende sessie', 'gezonder-in-werk-booking' );
		}
		return sprintf( '%s – %s, %s', $row['course_title'], mysql2date( 'd-m-Y', $row['session_date'] ), $row['location'] );
	}

	public static function handle_update(): void {
		if ( ! current_user_can( self::CAP ) ) {
			self::deny();
		}
		check_admin_referer( 'gzw_update_enrolment' );

		// phpcs:disable WordPress.Security.NonceVerification -- checked above.
		$id     = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( (string) $_POST['status'] ) ) : '';
		$note   = isset( $_POST['internal_note'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['internal_note'] ) ) : '';
		$flag   = isset( $_POST['invoiced'] ) && '1' === $_POST['invoiced'];
		// phpcs:enable

		$ok = $id && in_array( $status, Enrolment_Repository::STATUSES, true ) && Enrolment_Repository::get( $id )
			&& Enrolment_Repository::update_admin( $id, $status, $flag, $note );
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => self::SLUG,
					'enrolment'  => $id,
					'gzw_notice' => $ok ? 'saved' : 'error',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			self::deny();
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'Inschrijvingen', 'gezonder-in-werk-booking' ) . '</h1>';
		$notice = isset( $_GET['gzw_notice'] ) ? sanitize_key( wp_unslash( $_GET['gzw_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( 'saved' === $notice ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Opgeslagen.', 'gezonder-in-werk-booking' ) . '</p></div>';
		} elseif ( 'error' === $notice ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Opslaan mislukt.', 'gezonder-in-werk-booking' ) . '</p></div>';
		}

		if ( isset( $_GET['enrolment'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			self::render_detail( absint( $_GET['enrolment'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		} else {
			self::render_list();
		}
		echo '</div>';
	}

	/** Active list filters from the request; shared with the CSV export. */
	public static function filters(): array {
		// phpcs:disable WordPress.Security.NonceVerification -- read-only filters.
		return array(
			'course'  => isset( $_GET['course'] ) ? absint( $_GET['course'] ) : 0,
			'session' => isset( $_GET['session'] ) ? absint( $_GET['session'] ) : 0,
			'status'  => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '',
			'search'  => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
		);
		// phpcs:enable
	}

	private static function render_list(): void {
		$filters = self::filters();
		$page    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification
		$result  = Enrolment_Repository::query( $filters, $page, self::PER_PAGE );

		self::render_filter_form( $filters );
		echo '<p><a class="button" href="' . esc_url( Enrolments_Export::url( $filters ) ) . '">' . esc_html__( 'Exporteer naar CSV', 'gezonder-in-werk-booking' ) . '</a></p>';

		$columns = Enrolment_Fields::list_fields();
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( $columns as $field ) {
			echo '<th>' . esc_html( $field['label'] ) . '</th>';
		}
		foreach ( array( __( 'Sessie', 'gezonder-in-werk-booking' ), __( 'Status', 'gezonder-in-werk-booking' ), __( 'Ingeschreven op', 'gezonder-in-werk-booking' ) ) as $heading ) {
			echo '<th>' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		if ( ! $result['rows'] ) {
			echo '<tr><td colspan="' . esc_attr( (string) ( count( $columns ) + 3 ) ) . '">' . esc_html__( 'Geen inschrijvingen gevonden.', 'gezonder-in-werk-booking' ) . '</td></tr>';
		}
		$first = array_key_first( $columns );
		foreach ( $result['rows'] as $row ) {
			$detail = add_query_arg( array( 'page' => self::SLUG, 'enrolment' => $row['id'] ), admin_url( 'admin.php' ) );
			echo '<tr>';
			foreach ( $columns as $name => $field ) {
				$value = esc_html( (string) $row[ $name ] );
				echo '<td>' . ( $name === $first ? '<a href="' . esc_url( $detail ) . '">' . $value . '</a>' : $value ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
			}
			echo '<td>' . esc_html( self::session_label( $row ) ) . '</td>';
			echo '<td>' . esc_html( self::status_label( $row['status'] ) ) . '</td>';
			echo '<td>' . esc_html( self::created_label( $row ) ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		$pages = (int) ceil( $result['total'] / self::PER_PAGE );
		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post(
				(string) paginate_links(
					array(
						'base'    => add_query_arg( 'paged', '%#%' ),
						'format'  => '',
						'current' => $page,
						'total'   => $pages,
					)
				)
			) . '</div></div>';
		}
	}

	private static function render_filter_form( array $filters ): void {
		echo '<form method="get" style="margin:12px 0;display:flex;gap:8px;flex-wrap:wrap;align-items:center">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '" />';

		echo '<select name="course" aria-label="' . esc_attr__( 'Cursus', 'gezonder-in-werk-booking' ) . '"><option value="">' . esc_html__( 'Alle cursussen', 'gezonder-in-werk-booking' ) . '</option>';
		foreach ( Catalog_Repository::courses() as $course ) {
			printf( '<option value="%d"%s>%s</option>', (int) $course['id'], selected( $filters['course'], (int) $course['id'], false ), esc_html( $course['title'] ) );
		}
		echo '</select>';

		echo '<select name="session" aria-label="' . esc_attr__( 'Sessie', 'gezonder-in-werk-booking' ) . '"><option value="">' . esc_html__( 'Alle sessies', 'gezonder-in-werk-booking' ) . '</option>';
		foreach ( Catalog_Repository::all_sessions() as $session ) {
			printf( '<option value="%d"%s>%s</option>', (int) $session['id'], selected( $filters['session'], (int) $session['id'], false ), esc_html( $session['title'] . ' – ' . mysql2date( 'd-m-Y', $session['session_date'] ) . ', ' . $session['location'] ) );
		}
		echo '</select>';

		echo '<select name="status" aria-label="' . esc_attr__( 'Status', 'gezonder-in-werk-booking' ) . '"><option value="">' . esc_html__( 'Alle statussen', 'gezonder-in-werk-booking' ) . '</option>';
		foreach ( Enrolment_Repository::STATUSES as $status ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $status ), selected( $filters['status'], $status, false ), esc_html( self::status_label( $status ) ) );
		}
		echo '</select>';

		echo '<input type="search" name="s" value="' . esc_attr( $filters['search'] ) . '" placeholder="' . esc_attr__( 'Zoek op naam, e-mail of werkgever', 'gezonder-in-werk-booking' ) . '" />';
		echo '<button type="submit" class="button">' . esc_html__( 'Filteren', 'gezonder-in-werk-booking' ) . '</button></form>';
	}

	private static function render_detail( int $id ): void {
		$row  = $id ? Enrolment_Repository::get( $id ) : null;
		$back = add_query_arg( array( 'page' => self::SLUG ), admin_url( 'admin.php' ) );
		echo '<p><a href="' . esc_url( $back ) . '">&larr; ' . esc_html__( 'Terug naar de lijst', 'gezonder-in-werk-booking' ) . '</a></p>';
		if ( ! $row ) {
			echo '<p>' . esc_html__( 'Inschrijving niet gevonden.', 'gezonder-in-werk-booking' ) . '</p>';
			return;
		}

		$groups = array(
			Enrolment_Fields::GROUP_PERSON  => __( 'Deelnemer', 'gezonder-in-werk-booking' ),
			Enrolment_Fields::GROUP_BILLING => __( 'Factuurgegevens', 'gezonder-in-werk-booking' ),
		);
		echo '<h2>' . esc_html__( 'Inschrijving', 'gezonder-in-werk-booking' ) . '</h2><table class="widefat striped"><tbody>';
		echo '<tr><th>' . esc_html__( 'Sessie', 'gezonder-in-werk-booking' ) . '</th><td>' . esc_html( self::session_label( $row ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Prijs excl. BTW', 'gezonder-in-werk-booking' ) . '</th><td>' . esc_html( Catalog_Repository::format_price( (int) $row['price_cents'] ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Ingeschreven op', 'gezonder-in-werk-booking' ) . '</th><td>' . esc_html( self::created_label( $row ) ) . '</td></tr>';
		foreach ( $groups as $group => $heading ) {
			echo '<tr><th colspan="2">' . esc_html( $heading ) . '</th></tr>';
			foreach ( Enrolment_Fields::in_group( $group ) as $name => $field ) {
				echo '<tr><th>' . esc_html( $field['label'] ) . '</th><td>' . esc_html( (string) $row[ $name ] ) . '</td></tr>';
			}
		}
		echo '</tbody></table>';
		?>
		<h2><?php esc_html_e( 'Beheer', 'gezonder-in-werk-booking' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="gzw_update_enrolment" />
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $id ); ?>" />
			<?php wp_nonce_field( 'gzw_update_enrolment' ); ?>
			<table class="form-table" role="presentation">
				<tr><th><label for="gzw-e-status"><?php esc_html_e( 'Status', 'gezonder-in-werk-booking' ); ?></label></th>
					<td><select id="gzw-e-status" name="status">
						<?php foreach ( Enrolment_Repository::STATUSES as $status ) : ?>
							<option value="<?php echo esc_attr( $status ); ?>" <?php selected( $row['status'], $status ); ?>><?php echo esc_html( self::status_label( $status ) ); ?></option>
						<?php endforeach; ?>
					</select></td></tr>
				<tr><th><?php esc_html_e( 'Gefactureerd', 'gezonder-in-werk-booking' ); ?></th>
					<td><label><input type="checkbox" name="invoiced" value="1" <?php checked( (int) $row['invoiced'], 1 ); ?> /> <?php esc_html_e( 'Factuur is verstuurd', 'gezonder-in-werk-booking' ); ?></label></td></tr>
				<tr><th><label for="gzw-e-note"><?php esc_html_e( 'Interne notitie', 'gezonder-in-werk-booking' ); ?></label></th>
					<td><textarea id="gzw-e-note" name="internal_note" class="large-text" rows="4"><?php echo esc_textarea( $row['internal_note'] ); ?></textarea></td></tr>
			</table>
			<?php submit_button( __( 'Opslaan', 'gezonder-in-werk-booking' ) ); ?>
		</form>
		<?php
	}
}
