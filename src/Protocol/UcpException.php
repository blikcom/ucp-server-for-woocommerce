<?php
/**
 * UCP protocol exception.
 *
 * @package UCPWS
 */

namespace UCPWS\Protocol;

defined( 'ABSPATH' ) || exit;

/**
 * Exception carrying UCP error semantics.
 *
 * Two families exist:
 *  - Transport/discovery errors: rendered as a bare `{code, content, continue_url?}`
 *    body with a 4xx/5xx HTTP status (REST) or a JSON-RPC error object (MCP).
 *  - Business outcomes: rendered as an HTTP 200 UCP envelope with `ucp.status =
 *    "error"` and a `messages[]` array. Marked by `is_business_outcome`.
 */
class UcpException extends \Exception {

	/**
	 * UCP error code string (e.g. `profile_unreachable`).
	 *
	 * @var string
	 */
	private $error_code;

	/**
	 * HTTP status code for the REST binding.
	 *
	 * @var int
	 */
	private $http_status;

	/**
	 * Whether this renders as a business outcome (HTTP 200 UCP envelope).
	 *
	 * @var bool
	 */
	private $business_outcome;

	/**
	 * Message severity (business outcomes).
	 *
	 * @var string
	 */
	private $severity;

	/**
	 * Contextually relevant fallback URL.
	 *
	 * @var string|null
	 */
	private $continue_url;

	/**
	 * Extra headers to emit (e.g. Retry-After).
	 *
	 * @var array<string, string>
	 */
	private $headers = array();

	/**
	 * JSONPath of the offending component.
	 *
	 * @var string|null
	 */
	private $path;

	/**
	 * Constructor.
	 *
	 * @param string $error_code  UCP error code.
	 * @param string $content     Human-readable message.
	 * @param int    $http_status HTTP status for the REST binding.
	 */
	public function __construct( string $error_code, string $content, int $http_status ) {
		parent::__construct( $content );
		$this->error_code       = $error_code;
		$this->http_status      = $http_status;
		$this->business_outcome = false;
		$this->severity         = 'unrecoverable';
	}

	/**
	 * Create a transport-level error (bare code/content body).
	 *
	 * @param string $error_code  UCP error code.
	 * @param string $content     Human-readable message.
	 * @param int    $http_status HTTP status.
	 * @return self
	 */
	public static function transport( string $error_code, string $content, int $http_status ): self {
		return new self( $error_code, $content, $http_status );
	}

	/**
	 * Create a business outcome error (HTTP 200 envelope, ucp.status=error).
	 *
	 * @param string $error_code UCP error code.
	 * @param string $content    Human-readable message.
	 * @param string $severity   Message severity.
	 * @return self
	 */
	public static function business( string $error_code, string $content, string $severity = 'unrecoverable' ): self {
		$exception                   = new self( $error_code, $content, 200 );
		$exception->business_outcome = true;
		$exception->severity         = $severity;
		return $exception;
	}

	/**
	 * Attach a continue URL.
	 *
	 * @param string|null $url Fallback URL.
	 * @return $this
	 */
	public function with_continue_url( ?string $url ): self {
		$this->continue_url = $url;
		return $this;
	}

	/**
	 * Attach a JSONPath.
	 *
	 * @param string $path RFC 9535 JSONPath.
	 * @return $this
	 */
	public function with_path( string $path ): self {
		$this->path = $path;
		return $this;
	}

	/**
	 * Attach extra HTTP headers.
	 *
	 * @param array<string, string> $headers Headers.
	 * @return $this
	 */
	public function with_headers( array $headers ): self {
		$this->headers = array_merge( $this->headers, $headers );
		return $this;
	}

	/**
	 * Override the HTTP status.
	 *
	 * @param int $status HTTP status code.
	 * @return $this
	 */
	public function with_http_status( int $status ): self {
		$this->http_status = $status;
		return $this;
	}

	/**
	 * Override the severity.
	 *
	 * @param string $severity Severity value.
	 * @return $this
	 */
	public function with_severity( string $severity ): self {
		$this->severity = $severity;
		return $this;
	}

	/** @return string */
	public function get_error_code(): string {
		return $this->error_code;
	}

	/** @return int */
	public function get_http_status(): int {
		return $this->http_status;
	}

	/** @return bool */
	public function is_business_outcome(): bool {
		return $this->business_outcome;
	}

	/** @return string */
	public function get_severity(): string {
		return $this->severity;
	}

	/** @return string|null */
	public function get_continue_url(): ?string {
		return $this->continue_url;
	}

	/** @return string|null */
	public function get_path(): ?string {
		return $this->path;
	}

	/** @return array<string, string> */
	public function get_headers(): array {
		return $this->headers;
	}

	/**
	 * The message entry for `messages[]`.
	 *
	 * @return array<string, mixed>
	 */
	public function to_message(): array {
		$message = array(
			'type'     => 'error',
			'code'     => $this->error_code,
			'content'  => $this->getMessage(),
			'severity' => $this->severity,
		);
		if ( null !== $this->path ) {
			$message['path'] = $this->path;
		}
		return $message;
	}

	/**
	 * JSON-RPC error code for the MCP binding.
	 *
	 * @return int
	 */
	public function get_jsonrpc_code(): int {
		if ( in_array( $this->error_code, ErrorCodes::DISCOVERY_CODES, true ) ) {
			return -32001;
		}
		if ( in_array( $this->error_code, array( ErrorCodes::DIGEST_MISMATCH, ErrorCodes::ALGORITHM_UNSUPPORTED ), true ) ) {
			return -32600;
		}
		if ( 500 === $this->http_status ) {
			return -32603;
		}
		return -32000;
	}
}
