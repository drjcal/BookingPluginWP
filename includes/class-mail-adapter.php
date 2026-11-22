<?php
namespace GezonderInWerk\Booking;

defined( 'ABSPATH' ) || exit;

/** Outgoing mail seam: wp_mail() in production, a recorder in tests. */
interface Mail_Adapter {

	public function send( string $to, string $subject, string $body ): void;
}

/** Sends with wp_mail() so site SMTP plugins apply. */
class Wp_Mail_Adapter implements Mail_Adapter {

	public function send( string $to, string $subject, string $body ): void {
		wp_mail( $to, $subject, $body );
	}
}
