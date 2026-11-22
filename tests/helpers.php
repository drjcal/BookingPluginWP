<?php
/**
 * Shared test helper: posts to the public enrolment endpoint like the real form does.
 */
class Gzw_Submission {

	/** Consent ticked and a valid nonce, unless the caller overrides them (e.g. consent_privacy => ''). */
	public static function defaults(): array {
		return array(
			'consent_cancellation'                       => '1',
			'consent_privacy'                            => '1',
			\GezonderInWerk\Booking\Spam_Guard::NONCE_FIELD => \GezonderInWerk\Booking\Spam_Guard::nonce(),
		);
	}

	/** Submit with the defaults; values in $params win. */
	public static function post( array $params ): WP_REST_Response {
		return self::post_raw( $params + self::defaults() );
	}

	/** Submit exactly $params. */
	public static function post_raw( array $params ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/gezonder-in-werk/v1/enrolments' );
		$request->set_body_params( $params );
		return rest_do_request( $request );
	}
}

/** Test mail adapter: records instead of sending. Install with Enrolment_Intake::use_mail_adapter(). */
class Gzw_Mail_Recorder implements \GezonderInWerk\Booking\Mail_Adapter {

	/** @var array<int,array{to:string,subject:string,html:string,plain:string,body:string}> */
	public array $sent = array();

	public function send( string $to, string $subject, string $html, string $plain = '' ): void {
		$body = $html;
		$this->sent[] = compact( 'to', 'subject', 'html', 'plain', 'body' );
	}
}

/**
 * Test lock adapter: named locks held in memory, re-entrant like a MySQL lock on one connection.
 * hold_elsewhere() makes a lock look held by another connection, so acquiring it times out at once.
 * Install with Session_Lock::use_adapter().
 */
class Gzw_Memory_Lock implements \GezonderInWerk\Booking\Lock_Adapter {

	/** @var array<string,int> Hold counts per lock name. */
	private array $held = array();
	/** @var array<string,true> */
	private array $elsewhere = array();

	public function hold_elsewhere( string $name ): void {
		$this->elsewhere[ $name ] = true;
	}

	public function acquire( string $name, int $timeout ): bool {
		if ( isset( $this->elsewhere[ $name ] ) ) {
			return false;
		}
		$this->held[ $name ] = ( $this->held[ $name ] ?? 0 ) + 1;
		return true;
	}

	public function release( string $name ): void {
		$this->held[ $name ] = max( 0, ( $this->held[ $name ] ?? 0 ) - 1 );
	}

	public function is_held( string $name ): bool {
		return ! empty( $this->held[ $name ] );
	}
}
