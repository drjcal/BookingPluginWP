<?php
namespace GezonderInWerk\Booking;

defined( 'ABSPATH' ) || exit;

/**
 * CSV export of the admin enrolment list: same filters, one row per enrolment.
 * Semicolon-delimited with a UTF-8 BOM so Excel (Dutch settings) opens it correctly.
 */
class Enrolments_Export {

	const ACTION = 'gzw_export_enrolments';
	const CAP    = Installer::CAP_MANAGE_ENROLMENTS;
	const BOM    = "\xEF\xBB\xBF";

	/** Nonce-protected download URL carrying the active list filters. */
	public static function url( array $filters ): string {
		$args = array_filter( $filters, static fn( $v ) => '' !== $v && 0 !== $v );
		if ( isset( $args['search'] ) ) {
			$args['s'] = $args['search']; // Same query var as the list.
			unset( $args['search'] );
		}
		$args['action']   = self::ACTION;
		$args['_wpnonce'] = wp_create_nonce( self::ACTION );
		return add_query_arg( $args, admin_url( 'admin-post.php' ) );
	}

	public static function handle_export(): void {
		$response = self::authorised_response();

		nocache_headers();
		foreach ( $response['headers'] as $header ) {
			header( $header );
		}
		echo $response['body']; // phpcs:ignore WordPress.Security.EscapeOutput -- CSV download, not HTML.
		exit;
	}

	/**
	 * Checks capability and nonce, then builds the download for the request's filters.
	 * Dies (403 / nonce failure) when not allowed.
	 *
	 * @return array{headers:string[],body:string}
	 */
	public static function authorised_response(): array {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Geen toegang.', 'gezonder-in-werk-booking' ), 403 );
		}
		check_admin_referer( self::ACTION );

		return array(
			'headers' => array(
				'Content-Type: text/csv; charset=utf-8',
				'Content-Disposition: attachment; filename="inschrijvingen-' . gmdate( 'Y-m-d' ) . '.csv"',
			),
			'body'    => self::build( Enrolments_Admin::filters() ),
		);
	}

	/**
	 * Column definitions in export order: label plus a callable producing the cell from a row.
	 *
	 * @return array<int,array{0:string,1:callable}>
	 */
	private static function columns(): array {
		$columns = array(
			array( __( 'Ingeschreven op', 'gezonder-in-werk-booking' ), array( Enrolments_Admin::class, 'created_label' ) ),
			array( __( 'Cursus', 'gezonder-in-werk-booking' ), static fn( array $row ) => (string) $row['course_title'] ),
			array( __( 'Sessie', 'gezonder-in-werk-booking' ), array( Enrolments_Admin::class, 'session_label' ) ),
		);
		foreach ( Enrolment_Fields::all() as $name => $field ) {
			$columns[] = array( $field['label'], static fn( array $row ) => (string) $row[ $name ] );
		}
		return array_merge(
			$columns,
			array(
				array( __( 'Prijs excl. BTW', 'gezonder-in-werk-booking' ), static fn( array $row ) => Catalog_Repository::format_price( (int) $row['price_cents'] ) ),
				array( __( 'Status', 'gezonder-in-werk-booking' ), static fn( array $row ) => Enrolments_Admin::status_label( $row['status'] ) ),
				array( __( 'Gefactureerd', 'gezonder-in-werk-booking' ), static fn( array $row ) => (int) $row['invoiced'] ? __( 'Ja', 'gezonder-in-werk-booking' ) : __( 'Nee', 'gezonder-in-werk-booking' ) ),
				array( __( 'Interne notitie', 'gezonder-in-werk-booking' ), static fn( array $row ) => (string) $row['internal_note'] ),
			)
		);
	}

	/** The complete CSV (BOM, header row, one row per matching enrolment). */
	public static function build( array $filters ): string {
		$columns = self::columns();

		$out = fopen( 'php://temp', 'r+' );
		self::write_row( $out, array_column( $columns, 0 ) );
		foreach ( Enrolment_Repository::all( $filters ) as $row ) {
			self::write_row( $out, array_map( static fn( array $column ) => (string) $column[1]( $row ), $columns ) );
		}
		rewind( $out );
		$csv = (string) stream_get_contents( $out );
		fclose( $out );

		return self::BOM . $csv;
	}

	/** @param resource $out */
	private static function write_row( $out, array $cells ): void {
		fputcsv( $out, array_map( array( self::class, 'neutralise' ), $cells ), ';', '"', '' );
	}

	/** Prefix cells Excel would read as a formula (also after leading whitespace) with an apostrophe. */
	private static function neutralise( string $value ): string {
		return preg_match( '/^\s*[=+\-@]|^[\t\r]/', $value ) ? "'" . $value : $value;
	}
}
