<?php
namespace GezonderInWerk\Booking;

defined( 'ABSPATH' ) || exit;

/** Outgoing mail seam: wp_mail() in production, a recorder in tests. */
interface Mail_Adapter {

	public function send( string $to, string $subject, string $html, string $plain = '' ): void;
}

/** Sends with wp_mail() so site SMTP plugins apply. */
class Wp_Mail_Adapter implements Mail_Adapter {

	public function send( string $to, string $subject, string $html, string $plain = '' ): void {
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		if ( '' !== $plain ) {
			$set_alt = static function ( $phpmailer ) use ( $plain ) {
				$phpmailer->AltBody = $plain;
			};
			add_action( 'phpmailer_init', $set_alt );
			wp_mail( $to, $subject, $html, $headers );
			remove_action( 'phpmailer_init', $set_alt );
			return;
		}
		wp_mail( $to, $subject, $html, $headers );
	}
}
