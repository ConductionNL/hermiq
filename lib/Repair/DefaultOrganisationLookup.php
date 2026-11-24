<?php

/**
 * Hermiq DefaultOrganisationLookup.
 *
 * The default organisation's UUID for a repair step, which runs with no session.
 * A flow row with no organisation matches no tenant's organisation filter and is
 * invisible to everyone, while a by-name existence check still finds it, so it
 * blocks its own re-seed forever (hermiq#140). A seeder therefore refuses to write
 * when this returns null. Same two steps as SeedHydraTriageFlow, cheapest first:
 * the configured default, then `ensureDefaultOrganisation()` on a fresh instance.
 *
 * @category Repair
 * @package  OCA\Hermiq\Repair
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
 * @spec openspec/changes/archive/2026-11-24-hermiq-seed-hydra-flows/tasks.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Repair;

use OCA\OpenRegister\Service\OrganisationService;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves the default organisation without a session.
 *
 * @spec openspec/changes/archive/2026-11-24-hermiq-seed-hydra-flows/tasks.md
 */
class DefaultOrganisationLookup {

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Server container (OpenRegister may not be installed).
	 * @param LoggerInterface    $logger    PSR-3 logger.
	 *
	 * @spec openspec/changes/archive/2026-11-24-hermiq-seed-hydra-flows/tasks.md
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The default organisation's UUID, or null when none can be resolved.
	 *
	 * @return string|null The UUID.
	 *
	 * @spec openspec/changes/archive/2026-11-24-hermiq-seed-hydra-flows/tasks.md
	 */
	public function uuid(): ?string {
		try {
			$organisations = $this->container->get(OrganisationService::class);
		} catch (Throwable $e) {
			$this->logger->warning('[hermiq] OrganisationService unavailable for the flow seed: ' . $e->getMessage());
			return null;
		}

		try {
			$uuid = $organisations->getDefaultOrganisationUuid();
			if (is_string($uuid) === true && $uuid !== '') {
				return $uuid;
			}
		} catch (Throwable $e) {
			$this->logger->debug('[hermiq] Default organisation not yet configured: ' . $e->getMessage());
		}

		try {
			$uuid = $organisations->ensureDefaultOrganisation()->getUuid();
			if (is_string($uuid) === true && $uuid !== '') {
				return $uuid;
			}
		} catch (Throwable $e) {
			$this->logger->warning('[hermiq] Could not ensure a default organisation: ' . $e->getMessage());
		}

		return null;
	}//end uuid()
}//end class
