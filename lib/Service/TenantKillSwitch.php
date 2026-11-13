<?php

/**
 * Hermiq TenantKillSwitch.
 *
 * Reads the organisation kill-switches (TenantControl objects in register
 * `hermiq`, schema `tenantcontrol`). An engaged kill-switch halts every
 * governed run of its organisation — a scheduled tick, a flow-triggered run,
 * a webhook run, an eval run, a skill consolidation — so all of them ask this
 * one class, against one data source (ADR-004 Art. 14).
 *
 * @category Service
 * @package  OCA\Hermiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-09-06-human-approval-gate-enforcement/tasks.md#3-dispatcher-kill-switch-scheduleservice
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Answers which organisations have their kill-switch engaged.
 *
 * @spec openspec/changes/archive/2026-09-06-human-approval-gate-enforcement/tasks.md#3-dispatcher-kill-switch-scheduleservice
 */
final class TenantKillSwitch {

	/**
	 * OpenRegister register slug that holds Hermiq tenant-control objects.
	 *
	 * @var string
	 */
	private const REGISTER_SLUG = 'hermiq';

	/**
	 * OpenRegister schema slug for tenant-control (kill-switch) objects.
	 *
	 * @var string
	 */
	private const TENANT_CONTROL_SCHEMA = 'tenantcontrol';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OpenRegister object reads (single read-path).
	 * @param LoggerInterface $logger PSR-3 logger for a failed kill-switch read.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Load the set of organisations whose kill-switch (TenantControl) is engaged.
	 *
	 * Read via a single register/schema-wide query. When engaged, ALL runs for
	 * that organisation are halted. A read failure is logged and treated as "no
	 * organisation engaged" so a transient TenantControl read error never
	 * silently halts every tenant — the read fails open but the halt itself is
	 * a hard, synchronous block.
	 *
	 * @return array<int, string> The engaged organisation identifiers.
	 *
	 * @spec openspec/changes/archive/2026-09-06-human-approval-gate-enforcement/tasks.md#3-dispatcher-kill-switch-scheduleservice
	 */
	public function engagedOrganisations(): array {
		$organisations = array_map(
			fn (mixed $object): string => $this->engagedOrganisationOf(object: $object),
			$this->readTenantControls()
		);
		$named = array_filter($organisations, fn (string $organisation): bool => $organisation !== '');

		return array_values(array_unique($named));
	}//end engagedOrganisations()

	/**
	 * Read the engaged TenantControl objects, logging a failed read and
	 * answering "none" for it (fail open on the read).
	 *
	 * @return array<int, mixed> The TenantControl rows, or [] when unreadable.
	 */
	private function readTenantControls(): array {
		try {
			return $this->queryEngagedControls();
		} catch (Throwable $e) {
			$this->logger->error(
				'Hermiq could not load engaged kill-switches: ' . $e->getMessage(),
				['exception' => $e]
			);
			return [];
		}
	}//end readTenantControls()

	/**
	 * Query every engaged TenantControl object, system-wide.
	 *
	 * @return array<int, mixed> The TenantControl rows.
	 */
	private function queryEngagedControls(): array {
		return $this->objectService
			->setRegister(self::REGISTER_SLUG)
			->setSchema(self::TENANT_CONTROL_SCHEMA)
			->findAll(
				config: ['filters' => ['engaged' => true]],
				_rbac: false,
				_multitenancy: false
			);
	}//end queryEngagedControls()

	/**
	 * Whether the given organisation's kill-switch (TenantControl) is currently
	 * engaged — GATE 1 for any governed run, not just a Schedule tick.
	 *
	 * @param string $organisation The organisation identifier to check.
	 *
	 * @return bool True when the organisation's kill-switch is engaged.
	 *
	 * @spec openspec/changes/archive/2026-09-06-flow-agent-listener/tasks.md#2-flowagentrunservice-governed-dispatch
	 */
	public function isOrganisationEngaged(string $organisation): bool {
		if ($organisation === '') {
			return false;
		}

		return in_array($organisation, $this->engagedOrganisations(), true);
	}//end isOrganisationEngaged()

	/**
	 * The organisation a TenantControl object halts, or '' when it halts none
	 * (not an entity, not engaged, or naming no organisation).
	 *
	 * @param mixed $object One row of the TenantControl query.
	 *
	 * @return string The engaged organisation identifier, or ''.
	 */
	private function engagedOrganisationOf(mixed $object): string {
		if (($object instanceof ObjectEntity) === false) {
			return '';
		}

		$data = $object->getObject();
		if (($data['engaged'] ?? false) !== true) {
			return '';
		}

		return (string)($object->getOrganisation() ?? '');
	}//end engagedOrganisationOf()
}//end class
