<?php
/**
 * Payment handler result.
 *
 * @package UCPWS
 */

namespace UCPWS\Payments;

defined( 'ABSPATH' ) || exit;

/**
 * Outcome of a payment handler charge attempt.
 */
final class PaymentResult {

	public const STATUS_SUCCESS    = 'success';
	public const STATUS_DECLINED   = 'declined';
	public const STATUS_ESCALATION = 'escalation';

	/**
	 * Result status.
	 *
	 * @var string
	 */
	private $status;

	/**
	 * PSP transaction id (success).
	 *
	 * @var string|null
	 */
	private $transaction_id;

	/**
	 * UCP error code (declined).
	 *
	 * @var string
	 */
	private $error_code = 'payment_failed';

	/**
	 * Human-readable message.
	 *
	 * @var string
	 */
	private $message = '';

	/**
	 * Message severity.
	 *
	 * @var string
	 */
	private $severity = 'requires_buyer_input';

	/**
	 * HTTP status for declines (REST binding).
	 *
	 * @var int
	 */
	private $http_status = 402;

	/**
	 * Escalation continue URL override.
	 *
	 * @var string|null
	 */
	private $continue_url;

	/**
	 * Private constructor; use factories.
	 *
	 * @param string $status Result status.
	 */
	private function __construct( string $status ) {
		$this->status = $status;
	}

	/**
	 * Successful charge.
	 *
	 * @param string|null $transaction_id PSP transaction reference.
	 * @return self
	 */
	public static function success( ?string $transaction_id = null ): self {
		$result                 = new self( self::STATUS_SUCCESS );
		$result->transaction_id = $transaction_id;
		return $result;
	}

	/**
	 * Declined charge.
	 *
	 * @param string $message     Human-readable decline reason (never include credentials).
	 * @param string $error_code  UCP error code (default payment_failed).
	 * @param int    $http_status HTTP status for the REST binding (402 default, 403 for fraud).
	 * @return self
	 */
	public static function declined( string $message, string $error_code = 'payment_failed', int $http_status = 402 ): self {
		$result              = new self( self::STATUS_DECLINED );
		$result->message     = $message;
		$result->error_code  = $error_code;
		$result->http_status = $http_status;
		return $result;
	}

	/**
	 * Charge requires buyer interaction (3DS/SCA, mandate confirmation, ...).
	 *
	 * The checkout responds with `status: requires_escalation` and a
	 * `continue_url` where the buyer can finish the payment.
	 *
	 * @param string      $message      Human-readable reason.
	 * @param string|null $continue_url URL for the buyer handoff (defaults to the order pay URL).
	 * @param string      $error_code   Message code (e.g. requires_3ds).
	 * @return self
	 */
	public static function escalation( string $message, ?string $continue_url = null, string $error_code = 'payment_failed' ): self {
		$result               = new self( self::STATUS_ESCALATION );
		$result->message      = $message;
		$result->continue_url = $continue_url;
		$result->error_code   = $error_code;
		$result->severity     = 'requires_buyer_input';
		return $result;
	}

	/** @return bool */
	public function is_success(): bool {
		return self::STATUS_SUCCESS === $this->status;
	}

	/** @return bool */
	public function is_escalation(): bool {
		return self::STATUS_ESCALATION === $this->status;
	}

	/** @return bool */
	public function is_declined(): bool {
		return self::STATUS_DECLINED === $this->status;
	}

	/** @return string|null */
	public function get_transaction_id(): ?string {
		return $this->transaction_id;
	}

	/** @return string */
	public function get_error_code(): string {
		return $this->error_code;
	}

	/** @return string */
	public function get_message(): string {
		return $this->message;
	}

	/** @return string */
	public function get_severity(): string {
		return $this->severity;
	}

	/** @return int */
	public function get_http_status(): int {
		return $this->http_status;
	}

	/** @return string|null */
	public function get_continue_url(): ?string {
		return $this->continue_url;
	}
}
