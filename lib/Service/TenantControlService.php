<?php

/**
 * Hermiq TenantControlService.
 *
 * The write-path for the per-organisation kill-switch (EU AI Act Art. 14 stop
 * mechanism). It reads and toggles the durable `TenantControl` OpenRegister object
 * that halts every agent run for an organisation. All persistence flows through
 * OpenRegister's ObjectService single write-path (ADR-001, ADR-004), so the toggle is
 * tenant-scoped and auditable. The org-subadmin / instance-admin authorization lives
 * in TenantControlController; this service is the storage seam.
 *
 * This is a recognised ADR-031 imperative exception: a side-effecting governance
 * service, not a derived value or declarative lifecycle.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#5-kill-switch-toggle-endpoint
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;

/**
 * Reads and toggles the per-organisation kill-switch (TenantControl) via OpenRegister.
 *
 * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#5-kill-switch-toggle-endpoint
 */
class TenantControlService {

	/**
	 * OpenRegister register slug that holds Hermiq objects.
	 *
	 * @var string
	 */
	private const REGISTER_SLUG = 'hermiq';

	/**
	 * OpenRegister schema slug for tenant-control (kill-switch) objects.
	 *
	 * @var string
	 */
	private const SCHEMA_SLUG = 'tenantcontrol';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OpenRegister object read/write (single write-path).
	 */
	public function __construct(
		private readonly ObjectService $objectService,
	) {
	}//end __construct()

	/**
	 * Get the TenantControl object for an organisation, if one exists.
	 *
	 * Matched by `ObjectEntity.organisation` (the tenant scope, an OpenRegister
	 * organisation UUID — the same value schedules carry) rather than a payload field.
	 * There is at most one control per organisation.
	 *
	 * @param string $organisation The organisation identifier (OpenRegister org UUID).
	 *
	 * @return ObjectEntity|null The control object, or null when none exists.
	 *
	 * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#task-5-1
	 */
	public function getForOrganisation(string $organisation): ?ObjectEntity {
		if ($organisation === '') {
			return null;
		}

		$objects = $this->objectService
			->setRegister(self::REGISTER_SLUG)
			->setSchema(self::SCHEMA_SLUG)
			->findAll(config: [], _rbac: false, _multitenancy: false);

		foreach ($objects as $object) {
			if (($object instanceof ObjectEntity) === false) {
				continue;
			}

			if ((string)($object->getOrganisation() ?? '') === $organisation) {
				return $object;
			}
		}

		return null;
	}//end getForOrganisation()

	/**
	 * Engage or disengage the kill-switch for an organisation.
	 *
	 * Updates the existing TenantControl when present, otherwise creates one. The
	 * write goes through ObjectService so it is recorded in OpenRegister's hash-chained
	 * AuditTrail (ADR-004).
	 *
	 * @param string $organisation The organisation identifier.
	 * @param bool $engaged The new engaged state.
	 * @param string|null $reason Optional free-text reason for the change.
	 * @param string $actorUid The org-subadmin/instance-admin performing the toggle.
	 *
	 * @return ObjectEntity The persisted control object.
	 *
	 * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#task-5-1
	 */
	public function toggle(string $organisation, bool $engaged, ?string $reason, string $actorUid): ObjectEntity {
		$now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c');
		$cleanReason = (string)($reason ?? '');

		$existing = $this->getForOrganisation(organisation: $organisation);

		$data = [];
		$uuid = null;
		if ($existing !== null) {
			$data = $existing->getObject();
			$uuid = (string)$existing->getUuid();
		}

		$reasonValue = null;
		if ($cleanReason !== '') {
			$reasonValue = $cleanReason;
		}

		$data['engaged'] = $engaged;
		$data['reason'] = $reasonValue;
		$data['engagedBy'] = $actorUid;
		$data['engagedAt'] = $now;

		// Pin the control to the TARGET organisation (an OpenRegister organisation UUID),
		// not the actor's active organisation. Without this, saveObject() stamps the
		// caller's active org via getOrganisationForNewEntity(), so an admin toggling a
		// different tenant would write into their own org and getForOrganisation() could
		// never find it again (creating a duplicate on every toggle). OpenRegister only
		// honours @self.organisation for an admin or a verified member of that org.
		$self = (array)($data['@self'] ?? []);
		$self['organisation'] = $organisation;
		$data['@self'] = $self;

		return $this->objectService->saveObject(
			object: $data,
			register: self::REGISTER_SLUG,
			schema: self::SCHEMA_SLUG,
			uuid: $uuid,
			_rbac: false,
			_multitenancy: false
		);

	}//end toggle()
}//end class
