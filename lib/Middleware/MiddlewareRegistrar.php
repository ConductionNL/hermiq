<?php

/**
 * Hermiq MiddlewareRegistrar
 *
 * The middleware Hermiq registers, in the two places of the chain they need.
 *
 * @category Middleware
 * @package  OCA\Hermiq\Middleware
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-09-06-agent-schedule-dispatcher/tasks.md#task-1-2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Middleware;

use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * The Middleware component registers its own middleware, so the bootstrap does not name it.
 *
 * Middleware order is part of the contract, so the bootstrap calls registerFirst()
 * before every other registration and registerLast() after them.
 *
 * @spec openspec/changes/archive/2026-09-06-agent-schedule-dispatcher/tasks.md#task-1-2
 */
final class MiddlewareRegistrar {

	/**
	 * Register the middleware that must come before every other registration.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-09-06-agent-schedule-dispatcher/tasks.md#task-1-2
	 */
	public function registerFirst(IRegistrationContext $context): void {
		// The API contract (version header, deprecation of unversioned paths,
		// problem details). Registered first: Nextcloud runs afterController in
		// reverse order, so it sees the final response of every other middleware.
		$context->registerMiddleware(class: ApiContractMiddleware::class);
	}//end registerFirst()

	/**
	 * Register the middleware that must come after every other registration.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-09-06-agent-schedule-dispatcher/tasks.md#task-1-2
	 */
	public function registerLast(IRegistrationContext $context): void {
		// Every /api/ response names the answering API version (NL API Design Rules, /core/version-header).
		$context->registerMiddleware(ApiVersionMiddleware::class);
	}//end registerLast()
}//end class
