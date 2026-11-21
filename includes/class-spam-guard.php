<?php
namespace GezonderInWerk\Booking;

defined( 'ABSPATH' ) || exit;

/**
 * Spam protection for the public form: a nonce, a honeypot field and a per-IP rate limit.
 *
 * Every attempt counts towards the rate limit, also failed validations, but a person fixing a
 * few mistakes stays well below it. Tune with the `gzw_booking_rate_limit` filter
 * (array: attempts, seconds).
 */
class Spam_Guard {

	const NONCE_ACTION   = 'gzw_enrol';
	const NONCE_FIELD    = 'gzw_nonce';
	const HONEYPOT_FIELD = 'gzw_website';

	const RATE_ATTEMPTS = 10;
	const RATE_WINDOW   = 600;

	/** @return string Nonce for the public form. Note: a page cache older than the nonce lifetime serves a stale one. */
	public static function nonce(): string {
		return wp_create_nonce( self::NONCE_ACTION );
	}

	/** Why the request is rejected (nonce | honeypot | rate), or null when it passes. */
	public static function check( array $params ): ?string {
		if ( self::rate_limited() ) {
			return 'rate';
		}
		$nonce = $params[ self::NONCE_FIELD ] ?? '';
		if ( ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return 'nonce';
		}
		$trap = $params[ self::HONEYPOT_FIELD ] ?? '';
		if ( ! is_scalar( $trap ) || '' !== trim( (string) $trap ) ) {
			return 'honeypot';
		}
		return null;
	}

	/** Counts this attempt and tells whether the client is over the limit. */
	private static function rate_limited(): bool {
		list( $attempts, $window ) = apply_filters( 'gzw_booking_rate_limit', array( self::RATE_ATTEMPTS, self::RATE_WINDOW ) );
		$key   = 'gzw_rate_' . md5( self::client_ip() );
		$count = (int) get_transient( $key ) + 1;
		set_transient( $key, $count, (int) $window );
		return $count > (int) $attempts;
	}

	private static function client_ip(): string {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}
}
