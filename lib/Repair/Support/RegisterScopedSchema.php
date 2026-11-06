<?php

/**
 * Hermiq register-scoped schema resolution helper.
 *
 * 🔴 A SCHEMA SLUG IS UNIQUE PER REGISTER, NOT ACROSS THE INSTANCE, and resolving one
 * globally is how an app ends up reading another app's data. Measured on the dev instance
 * 2026-09-07: slug `session` resolves to scholiq's schema id 336, "a scheduled occurrence
 * of a Cohort meeting", and slug `agent` resolves to buildiq's 6-property agent rather
 * than hermiq's 41-property one.
 *
 * Hermiq's own register works around this by prefixing (`agentsession`, `agentskill`,
 * `agentbudget`, `agentwebhook`, `agentaifeature`), but a prefix is a convention rather
 * than a guarantee, so a lookup that has to be right resolves the slug and then checks
 * the answer belongs to hermiq's register.
 *
 * @category Repair
 * @package  OCA\Hermiq\Repair\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/session-data-migration/specs/session-data-migration/spec.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Repair\Support;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves a schema slug to its id, scoped to one register.
 *
 * @spec openspec/changes/session-data-migration/specs/session-data-migration/spec.md
 */
class RegisterScopedSchema {

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Server container for lazy OpenRegister
	 *                                      resolution (it may not be installed).
	 * @param LoggerInterface    $logger    PSR-3 logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The id of a schema slug within one register.
	 *
	 * @param string $registerSlug The register the schema must belong to.
	 * @param string $schemaSlug   The schema slug to resolve.
	 *
	 * @return string|null The schema id, or null when it does not resolve inside that
	 *                     register.
	 *
	 * @spec openspec/changes/session-data-migration/specs/session-data-migration/spec.md
	 */
	public function idFor(string $registerSlug, string $schemaSlug): ?string {
		$ids = $this->schemaIdsOf(registerSlug: $registerSlug);

		try {
			$mapper = $this->container->get(\OCA\OpenRegister\Db\SchemaMapper::class);
			$matches = $mapper->findBySlug(slug: $schemaSlug, limit: 50, _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			$this->logger->error(
				'[hermiq] could not resolve schema slug "' . $schemaSlug . '": ' . $e->getMessage()
			);
			return null;
		}

		foreach ($matches as $schema) {
			$id = (string) $schema->getId();
			if ($ids === [] || in_array($id, $ids, true) === true) {
				return $id;
			}
		}

		return null;
	}//end idFor()

	/**
	 * The schema ids belonging to a register.
	 *
	 * Returning [] means "could not tell", and callers treat that as "do not filter"
	 * rather than "no schemas": refusing to resolve anything would turn an unreadable
	 * register into a silent no-op migration.
	 *
	 * @param string $registerSlug The register slug.
	 *
	 * @return array<int, string> The schema ids, or [] when the register cannot be read.
	 *
	 * @spec exclude Local lookup helper for idFor(); no behavioural spec of its own.
	 */
	private function schemaIdsOf(string $registerSlug): array {
		try {
			$mapper = $this->container->get(\OCA\OpenRegister\Db\RegisterMapper::class);
			// ⚠️ `find()`, not `findBySlug()`. RegisterMapper has no findBySlug — its
			// `find()` takes `string|int` and resolves a slug or an id. Calling the
			// method that does not exist threw, was caught, and turned into a silent
			// "could not resolve", which read as "no filter needed".
			$register = $mapper->find(id: $registerSlug, _rbac: false, _multitenancy: false);

			return array_map(static fn ($id): string => (string) $id, ($register->getSchemas() ?? []));
		} catch (Throwable) {
			return [];
		}
	}//end schemaIdsOf()
}//end class
