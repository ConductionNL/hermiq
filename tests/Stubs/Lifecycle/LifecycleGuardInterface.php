<?php

/**
 * Test stub for OpenRegister LifecycleGuardInterface.
 *
 * Stands in for OCA\OpenRegister\Lifecycle\LifecycleGuardInterface when OpenRegister is
 * not installed (standalone CI: php:8.3-cli + OCP stubs). Mirrors the real public
 * contract exactly: `check(array $object, string $action, string $userId): GuardResult`.
 * The real interface ships with OpenRegister at runtime, where the lifecycle validation
 * listener resolves a transition's `requires` FQCN and calls check() before persisting.
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
 * Minimal LifecycleGuardInterface stub for standalone unit runs.
 */
interface LifecycleGuardInterface {
	/**
	 * Authorise (or deny) a transition.
	 *
	 * @param array<string, mixed> $object The loaded object payload at its current state.
	 * @param string $action The transition action being applied.
	 * @param string $userId The uid of the caller.
	 *
	 * @return GuardResult Allow or deny + optional message.
	 */
	public function check(array $object, string $action, string $userId): GuardResult;
}//end interface
