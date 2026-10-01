<?php

/**
 * Hermiq CollectAppAgentTemplates repair step.
 *
 * On install and on every upgrade, asks the installed apps for the agent
 * templates they offer for themselves (CollectAgentTemplatesEvent) and imports
 * them quarantined. Runs after SeedAgentTemplates; an unchanged offer is skipped,
 * so running it again creates nothing new.
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
 * @spec openspec/specs/agent-template-gallery/spec.md#requirement-an-installed-app-can-offer-an-agent-template-for-itself-req-appag-005
 */

declare(strict_types=1);

namespace OCA\Hermiq\Repair;

use OCA\Hermiq\Service\AppTemplateOffers;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Import the agent templates installed apps offer for themselves.
 *
 * @spec openspec/specs/agent-template-gallery/spec.md#requirement-an-installed-app-can-offer-an-agent-template-for-itself-req-appag-005
 */
class CollectAppAgentTemplates implements IRepairStep {
	use \OCA\Hermiq\Repair\Support\RunsUnderSystemIdentity;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Lazy resolution: OpenRegister may not be installed yet.
	 * @param LoggerInterface $logger PSR-3 logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Repair-step name.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/agent-template-gallery/spec.md#requirement-an-installed-app-can-offer-an-agent-template-for-itself-req-appag-005
	 */
	public function getName(): string {
		return 'Collect the agent templates installed apps offer (agents-bound-to-their-app)';
	}//end getName()

	/**
	 * Collect and import the offers under a system identity.
	 *
	 * @param IOutput $output Repair output channel.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-template-gallery/spec.md#requirement-an-installed-app-can-offer-an-agent-template-for-itself-req-appag-005
	 */
	public function run(IOutput $output): void {
		try {
			$objectService = $this->container->get(ObjectService::class);
			$offers = $this->container->get(AppTemplateOffers::class);
		} catch (Throwable $e) {
			$output->warning('OpenRegister not available, skipping the app agent-template collect.');
			$this->logger->warning('[hermiq] App agent-template collect skipped: ' . $e->getMessage());
			return;
		}

		$this->withSystemIdentity(
			objectService: $objectService,
			work: function () use ($offers, $output): void {
				$counts = $offers->collect();
				$output->info(
					'App agent templates: ' . $counts['imported'] . ' new, ' . $counts['updated'] . ' updated, '
					. $counts['unchanged'] . ' unchanged, ' . $counts['refused'] . ' refused.'
				);
			}
		);
	}//end run()
}//end class
