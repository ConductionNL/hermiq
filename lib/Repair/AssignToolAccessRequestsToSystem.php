<?php

/**
 * Hermiq AssignToolAccessRequestsToSystem
 *
 * Hands every stored tool-access request to the system owner.
 *
 * A request used to be owned by the user it was raised for, and OpenRegister
 * admits an object's owner before every rule, so that user could delete a
 * refused request and let the agent raise a fresh one. New requests are saved
 * system-owned; this step does the same for the requests stored before that.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Repair
 * @package  OCA\Hermiq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-11-12-tool-discovery-and-access-requests/specs/tool-discovery-and-access-requests/spec.md#requirement-only-the-agents-owner-must-be-able-to-grant-a-request
 */

declare(strict_types=1);

namespace OCA\Hermiq\Repair;

use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sets the system owner on every tool-access request another owner holds.
 *
 * OpenRegister's save path stamps the owner only when it creates an object,
 * so an existing object's owner is changed through its MagicMapper, as
 * MigrateConversationsToSessions already reads through it. Idempotent: a
 * request the system already owns is not written.
 *
 * @spec openspec/changes/archive/2026-11-12-tool-discovery-and-access-requests/specs/tool-discovery-and-access-requests/spec.md#requirement-only-the-agents-owner-must-be-able-to-grant-a-request
 */
class AssignToolAccessRequestsToSystem implements IRepairStep {

	private const REGISTER_SLUG = 'hermiq';

	private const REQUEST_SCHEMA = 'toolAccessRequest';

	private const MAGIC_MAPPER = 'OCA\\OpenRegister\\Db\\MagicMapper';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container OpenRegister's services are resolved from here.
	 * @param LoggerInterface    $logger    PSR logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The step's name.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/archive/2026-11-12-tool-discovery-and-access-requests/specs/tool-discovery-and-access-requests/spec.md#requirement-only-the-agents-owner-must-be-able-to-grant-a-request
	 */
	public function getName(): string {
		return 'Give every tool-access request the system owner';
	}//end getName()

	/**
	 * Hand every request another owner holds to the system.
	 *
	 * @param IOutput $output Repair output channel.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-11-12-tool-discovery-and-access-requests/specs/tool-discovery-and-access-requests/spec.md#requirement-only-the-agents-owner-must-be-able-to-grant-a-request
	 */
	public function run(IOutput $output): void {
		$run = $this->prepare(output: $output);
		if ($run === null) {
			return;
		}

		$output->info(sprintf('%d tool-access request(s) given the system owner.', $run->handOverAll()));
	}//end run()

	/**
	 * Read every tool-access request with the system owner and the mapper, or warn and answer null.
	 *
	 * @param IOutput $output The repair output.
	 *
	 * @return RequestOwnerRun|null
	 *
	 * @spec openspec/changes/archive/2026-11-12-tool-discovery-and-access-requests/specs/tool-discovery-and-access-requests/spec.md#requirement-only-the-agents-owner-must-be-able-to-grant-a-request
	 */
	private function prepare(IOutput $output): ?RequestOwnerRun {
		try {
			$objects = $this->container->get(ObjectService::class);
			$system = $this->container->get(OrganisationService::class)->getSystemUserId();
			$mapper = $this->container->get(self::MAGIC_MAPPER);
			$requests = $objects->setRegister(self::REGISTER_SLUG)
				->setSchema(self::REQUEST_SCHEMA)
				->findAll(config: ['limit' => 100000], _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			$output->warning('Tool-access requests could not be read, their owners were not checked.');
			$this->logger->warning('[hermiq] tool-access request owner repair skipped: ' . $e->getMessage());
			return null;
		}

		return new RequestOwnerRun(requests: $requests, system: $system, mapper: $mapper, output: $output);
	}//end prepare()
}//end class
