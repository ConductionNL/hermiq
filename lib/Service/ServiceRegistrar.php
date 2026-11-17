<?php

/**
 * Hermiq ServiceRegistrar
 *
 * Bind the service contracts to their implementations.
 *
 * @category Service
 * @package  OCA\Hermiq\Service
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

namespace OCA\Hermiq\Service;

use OCA\Hermiq\Service\Llm\DatabaseRunTokenStore;
use OCA\Hermiq\Service\Llm\RunTokenStore;
use OCA\Hermiq\Service\Workspace\ServerSideWorkspaceProvider;
use OCA\Hermiq\Service\Workspace\WorkspaceProvider;
use OCA\OpenRegister\Contract\RegisterSlugResolverInterface;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * The Service component binds its own seams, so the bootstrap does not name the implementations.
 *
 * @spec openspec/changes/archive/2026-09-06-agent-schedule-dispatcher/tasks.md#task-1-2
 */
final class ServiceRegistrar {

	/**
	 * Bind the service contracts to their implementations.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-09-06-agent-schedule-dispatcher/tasks.md#task-1-2
	 */
	public function register(IRegistrationContext $context): void {
		(new ContractBindings())->register(context: $context);

		// The governed workspace seam (hermiq-runner-git-capability): Hermiq's
		// runtime keeps run-keyed checkouts in app data on the governed side.
		$context->registerServiceAlias(
			WorkspaceProvider::class,
			ServerSideWorkspaceProvider::class
		);

		// Run tokens live in the database, the one store every PHP process of the
		// instance shares (hermiq ADR-025). The distributed cache is not that store
		// on an instance without `memcache.distributed`: there it is APCu, per
		// process pool, and a token a cron job minted was invisible to the web
		// server verifying it.
		$context->registerServiceAlias(
			RunTokenStore::class,
			DatabaseRunTokenStore::class
		);

		// The register-slug resolver, published by OpenRegister (ADR-084's shape:
		// one definition owned by the app that implements it).
		//
		// Register slugs live in `openregister_registers`, and nine fleet apps
		// ship a repair step that renames theirs. The step is per instance, so
		// both slugs are live across the estate at once and a literal is wrong on
		// half of it. The old-slug case is the quiet one: OpenRegister finds no
		// register, matches no rows, and returns an empty set that is
		// byte-for-byte what a healthy empty register returns. No exception, no
		// 404, no log line. `CourseRecommendationEngine` read the learner-signal
		// register that way, which for a recommendation engine means it
		// recommends nothing and reports success.
		//
		// Verified against this container rather than assumed: resolving this
		// interface from hermiq's own DIContainer on the running instance returns
		// a working resolver. OpenRegister registers the concrete class in its OWN
		// container and none of that registration reaches here; what reaches here
		// is this alias plus autowiring of the concrete class, whose dependencies
		// are `RegisterMapper` and `LoggerInterface`, both resolvable from a leaf
		// app's container. The one thing lost is OpenRegister's shared-instance
		// registration: a leaf container autowires a fresh resolver per injection
		// point, so the request-scoped memo is per consumer rather than per
		// request. That costs one indexed read per consumer and changes no answer.
		//
		// An ALIAS specifically, and not a factory that touches the class. Apps
		// register in sorted order, so `OCA\OpenRegister\` is not autoloadable at
		// `register()` time on every instance; both sides of an alias are strings
		// and neither triggers an autoload, so the binding is safe whatever the
		// order. `OpenRegisterAutoloader::register()` at the top of register()
		// already removes that hazard here, and the alias would hold without it.
		$context->registerServiceAlias(
			RegisterSlugResolverInterface::class,
			'OCA\\OpenRegister\\Service\\RegisterSlugResolver'
		);
	}//end register()
}//end class
