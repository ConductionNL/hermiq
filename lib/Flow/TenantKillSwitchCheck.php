<?php

/**
 * hermiq's tenant kill switch, contributed to the engine's oversight gate.
 *
 * OpenRegister's FlowOversightRegistry asks every contributed check before
 * every hop of every flow run. hermiq never contributed one, so an agent
 * step in a canvas-authored flow kept running while a tenant's TenantControl
 * kill switch was engaged: the switch stopped scheduled ticks (dispatch gate
 * 1) but not the engine's walks. For an AI-agent app under EU AI Act
 * Art. 14 the operator's stop control must reach EVERY path an agent runs
 * on, so this check closes that hole (schedules-onto-engine-triggers).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Flow
 * @package  OCA\Hermiq\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-the-tenant-kill-switch-reaches-every-hermiq-flow-hop
 */

declare(strict_types=1);

namespace OCA\Hermiq\Flow;

use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Flow\IFlowOversightCheck;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Vetoes hermiq hops for organisations whose kill switch is engaged.
 *
 * THE FAIL MODES ARE DELIBERATELY ASYMMETRIC:
 *
 * - A TenantControl READ failure consents (logged). This mirrors the
 *   dispatcher's documented choice in `loadEngagedOrganisations()`: a
 *   transient read error must not silently halt every tenant's runs.
 * - An UNATTRIBUTABLE hermiq hop while any switch is engaged is VETOED. A
 *   kill switch that cannot tell whose run it is looking at must stop the
 *   run, not wave it through; over-blocking during an engaged emergency
 *   beats under-blocking during one.
 *
 * Scoped to `hermiq.*` node types only: other apps' hops are not hermiq's
 * to veto, and the registry consults every check on every hop of every run.
 *
 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-the-tenant-kill-switch-reaches-every-hermiq-flow-hop
 */
class TenantKillSwitchCheck implements IFlowOversightCheck {

	/**
	 * OpenRegister register slug that holds hermiq objects.
	 *
	 * @var string
	 */
	private const REGISTER_SLUG = 'hermiq';

	/**
	 * OpenRegister schema slug for tenant-control (kill switch) objects.
	 *
	 * @var string
	 */
	private const TENANT_CONTROL_SCHEMA = 'tenantcontrol';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService Reads engaged TenantControl objects.
	 * @param ContainerInterface $container Lazy FlowRunMapper resolution for the
	 *                                      run's organisation; lazy so the check
	 *                                      stays constructible on an OpenRegister
	 *                                      without the flow store.
	 * @param LoggerInterface $logger Diagnostics.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * The check's id, as recorded on a refusal.
	 *
	 * @return string The id.
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-the-tenant-kill-switch-reaches-every-hermiq-flow-hop
	 */
	public function getId(): string {
		return 'hermiq.tenant-killswitch';
	}//end getId()

	/**
	 * Veto a hermiq hop whose organisation has an engaged kill switch.
	 *
	 * @param array<string,mixed> $context The hop context (`nodeType`, `runUuid`).
	 *
	 * @return string|null The refusal reason, or null to consent.
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-the-tenant-kill-switch-reaches-every-hermiq-flow-hop
	 */
	public function veto(array $context): ?string {
		$nodeType = (string)($context['nodeType'] ?? '');
		if (str_starts_with($nodeType, 'hermiq.') === false) {
			return null;
		}

		$engaged = $this->engagedOrganisations();
		if ($engaged === []) {
			return null;
		}

		$organisation = $this->runOrganisation(runUuid: (string)($context['runUuid'] ?? ''));

		if ($organisation === null) {
			return 'A hermiq kill switch is engaged and this run\'s organisation could not be established. '
				. 'The hop is refused rather than run unattributed.';
		}

		if (in_array($organisation, $engaged, true) === true) {
			return 'The hermiq kill switch for this run\'s organisation is engaged. '
				. 'Disengage it in hermiq\'s tenant controls to let agent work continue.';
		}

		return null;
	}//end veto()

	/**
	 * The organisations whose kill switch is currently engaged.
	 *
	 * A read failure answers the EMPTY set, logged: the dispatcher's own
	 * documented fail-open-on-read choice, so a transient TenantControl
	 * outage never halts every tenant. Engagement itself is then a hard veto.
	 *
	 * @return array<int,string> The engaged organisation identifiers.
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-the-tenant-kill-switch-reaches-every-hermiq-flow-hop
	 */
	private function engagedOrganisations(): array {
		try {
			$objects = $this->objectService
				->setRegister(self::REGISTER_SLUG)
				->setSchema(self::TENANT_CONTROL_SCHEMA)
				->findAll(
					config: ['filters' => ['engaged' => true]],
					_rbac: false,
					_multitenancy: false
				);
		} catch (Throwable $e) {
			$this->logger->error(
				'[hermiq] Oversight check could not load engaged kill switches: ' . $e->getMessage(),
				['exception' => $e]
			);
			return [];
		}

		$organisations = [];
		foreach ($objects as $object) {
			if (($object instanceof ObjectEntity) === false) {
				continue;
			}

			if (($object->getObject()['engaged'] ?? false) !== true) {
				continue;
			}

			$organisation = (string)($object->getOrganisation() ?? '');
			if ($organisation !== '') {
				$organisations[] = $organisation;
			}
		}

		return array_values(array_unique($organisations));
	}//end engagedOrganisations()

	/**
	 * The organisation the identified run belongs to, or null when unknown.
	 *
	 * Read from the FlowRun row rather than the context: the run's stored
	 * attribution is server-written, where a context value would be
	 * caller-supplied at queue time.
	 *
	 * @param string $runUuid The run uuid from the hop context.
	 *
	 * @return string|null The organisation, or null when it cannot be established.
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-the-tenant-kill-switch-reaches-every-hermiq-flow-hop
	 */
	private function runOrganisation(string $runUuid): ?string {
		if ($runUuid === '') {
			return null;
		}

		try {
			$mapper = $this->container->get(FlowRunMapper::class);
			$run = $mapper->findByUuid($runUuid);
		} catch (Throwable $e) {
			return null;
		}

		$organisation = trim((string)($run->getOrganisation() ?? ''));
		if ($organisation === '') {
			return null;
		}

		return $organisation;
	}//end runOrganisation()
}//end class
