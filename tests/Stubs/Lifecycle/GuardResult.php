<?php

/**
 * Test stub for OpenRegister Lifecycle GuardResult.
 *
 * Stands in for OCA\OpenRegister\Lifecycle\GuardResult when OpenRegister is not installed
 * (standalone CI). Mirrors the real value object's public surface: the `allow`/`deny`
 * static factories and the `isAllowed`/`getMessage` inspectors. The real class ships with
 * OpenRegister at runtime.
 *
 * @category Test
 * @package  OCA\OpenRegister\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Lifecycle;

/**
 * Minimal GuardResult stub for standalone unit runs.
 */
final class GuardResult {

	/**
	 * Whether the transition is allowed.
	 *
	 * @var boolean
	 */
	private bool $allowed;

	/**
	 * Deny message when allowed=false.
	 *
	 * @var string|null
	 */
	private ?string $message;

	/**
	 * Private constructor — use the static factories.
	 *
	 * @param bool $allowed Whether the transition should be allowed.
	 * @param string|null $message Optional deny message.
	 */
	private function __construct(bool $allowed, ?string $message) {
		$this->allowed = $allowed;
		$this->message = $message;
	}//end __construct()

	/**
	 * Allow the transition.
	 *
	 * @return self Allow verdict instance.
	 */
	public static function allow(): self {
		return new self(allowed: true, message: null);
	}//end allow()

	/**
	 * Deny the transition with a user-visible message.
	 *
	 * @param string $message Human-readable reason.
	 *
	 * @return self Deny verdict instance.
	 */
	public static function deny(string $message): self {
		return new self(allowed: false, message: $message);
	}//end deny()

	/**
	 * Read whether the verdict allows the transition.
	 *
	 * @return bool True when allowed, false when denied.
	 */
	public function isAllowed(): bool {
		return $this->allowed;
	}//end isAllowed()

	/**
	 * Read the deny message, if any.
	 *
	 * @return string|null Deny message, or null when allowed or unset.
	 */
	public function getMessage(): ?string {
		return $this->message;
	}//end getMessage()
}//end class
