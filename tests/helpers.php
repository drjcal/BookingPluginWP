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

	/** @var array<int,array{to:string,subject:string,body:string}> */
	public array $sent = array();

	public function send( string $to, string $subject, string $body ): void {
		$this->sent[] = compact( 'to', 'subject', 'body' );
	}
}
