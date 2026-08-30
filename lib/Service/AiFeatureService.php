<?php

/**
 * Hermiq AiFeatureService.
 *
 * The management surface for the design-time AI-feature governance register (EU AI Act
 * registration/oversight). Lists tenant-scoped `AiFeature` objects, records the DPO
 * acknowledgement (an `IAppConfig` write plus a human-readable object stamp), and drives
 * the declarative `enable`/`disable` lifecycle transitions through OpenRegister. All
 * object persistence flows through OpenRegister's ObjectService single write-path
 * (ADR-001, ADR-004), so tenancy and the hash-chained AuditTrail are inherited; the
 * `enable` transition runs the DPO-ack lifecycle guard via OR's validation listener.
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
 * @spec openspec/changes/ai-feature-governance-register/tasks.md#3-controller-action-auth-adr-023-mirror-approvalcontroller
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Hermiq\AppInfo\Application;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\HookStoppedException;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;

/**
 * Reads and governs AiFeature objects via OpenRegister.
 *
 * @spec openspec/changes/ai-feature-governance-register/tasks.md#3-controller-action-auth-adr-023-mirror-approvalcontroller
 */
class AiFeatureService {

	/**
	 * OpenRegister register slug that holds Hermiq objects.
	 *
	 * @var string
	 */
	private const REGISTER_SLUG = 'hermiq';

	/**
	 * Schema slug for AiFeature objects.
	 *
	 * @var string
	 */
	private const AIFEATURE_SCHEMA = 'agentaifeature';

	/**
	 * IAppConfig key prefix under which a DPO acknowledgement is recorded.
	 *
	 * @var string
	 */
	private const ACK_KEY_PREFIX = 'dpo_ack';

	/**
	 * Transition outcome: the feature is now enabled.
	 *
	 * @var string
	 */
	public const RESULT_ENABLED = 'enabled';

	/**
	 * Transition outcome: the feature is now disabled.
	 *
	 * @var string
	 */
	public const RESULT_DISABLED = 'disabled';

	/**
	 * Transition outcome: the guard refused the transition (e.g. no DPO ack).
	 *
	 * @var string
	 */
	public const RESULT_BLOCKED = 'blocked';

	/**
	 * Transition outcome: the transition is not allowed from the current state.
	 *
	 * @var string
	 */
	public const RESULT_INVALID = 'invalid';

	/**
	 * Transition outcome: the feature was not found (or is cross-tenant).
	 *
	 * @var string
	 */
	public const RESULT_NOT_FOUND = 'notfound';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OpenRegister object read/write (single write-path).
	 * @param IAppConfig $appConfig App config holding the authoritative DPO acknowledgement.
	 *
	 * @spec openspec/changes/ai-feature-governance-register/tasks.md#task-3-2
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * List the AiFeature objects visible in the caller's tenant (RBAC + tenancy scoped).
	 *
	 * @return array<int, ObjectEntity> The AiFeature objects.
	 *
	 * @spec openspec/changes/ai-feature-governance-register/tasks.md#task-3-2
	 */
	public function listFeatures(): array {
		$objects = $this->objectService
			->setRegister(self::REGISTER_SLUG)
			->setSchema(self::AIFEATURE_SCHEMA)
			->findAll(config: ['limit' => 200]);

		$out = [];
		foreach ($objects as $object) {
			if ($object instanceof ObjectEntity) {
				$out[] = $object;
			}
		}

		return $out;
	}//end listFeatures()

	/**
	 * Get an AiFeature by UUID (RBAC + tenancy scoped), or null.
	 *
	 * @param string $id The AiFeature UUID.
	 *
	 * @return ObjectEntity|null The AiFeature object, or null when absent / cross-tenant.
	 *
	 * @spec openspec/changes/ai-feature-governance-register/tasks.md#task-3-4
	 */
	public function getFeature(string $id): ?ObjectEntity {
		if ($id === '') {
			return null;
		}

		return $this->objectService->find(
			id: $id,
			register: self::REGISTER_SLUG,
			schema: self::AIFEATURE_SCHEMA
		);

	}//end getFeature()

	/**
	 * Find the AiFeature with the given slug in the caller's tenant, or null.
	 *
	 * @param string $slug The feature slug.
	 *
	 * @return ObjectEntity|null The AiFeature object, or null.
	 *
	 * @spec openspec/changes/ai-feature-governance-register/tasks.md#task-3-3
	 */
	public function findBySlug(string $slug): ?ObjectEntity {
		if ($slug === '') {
			return null;
		}

		$objects = $this->objectService
			->setRegister(self::REGISTER_SLUG)
			->setSchema(self::AIFEATURE_SCHEMA)
			->findAll(config: ['filters' => ['slug' => $slug], 'limit' => 200]);

		foreach ($objects as $object) {
			if (($object instanceof ObjectEntity) === false) {
				continue;
			}

			if ((string)($object->getObject()['slug'] ?? '') === $slug) {
				return $object;
			}
		}

		return null;
	}//end findBySlug()

	/**
	 * Record the DPO acknowledgement for a feature and stamp the object.
	 *
	 * Writes the authoritative acknowledgement to `IAppConfig`
	 * (`dpo_ack.<tenantId>.<slug>` = `<uid>@<ISO-8601>`, the non-empty string the guard
	 * treats as acknowledged), then stamps the human-readable mirror (`dpoAckBy`,
	 * `dpoAckAt`) onto the object via the single ObjectService write-path (auto-audited).
	 * The tenant is derived from the object identically to the guard so `enable` unblocks.
	 *
	 * @param string $slug The feature slug to acknowledge.
	 * @param string $uid The acknowledging user id (the DPO / admin).
	 *
	 * @return ObjectEntity|null The stamped AiFeature, or null when no such feature.
	 *
	 * @spec openspec/changes/ai-feature-governance-register/tasks.md#task-3-3
	 */
	public function acknowledge(string $slug, string $uid): ?ObjectEntity {
		$feature = $this->findBySlug(slug: $slug);
		if ($feature === null) {
			return null;
		}

		$data = $feature->getObject();
		$tenantId = $this->resolveTenant(object: $data);
		$timestamp = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c');

		// Authoritative acknowledgement the guard reads (admin/DPO-only config write).
		$this->appConfig->setValueString(
			Application::APP_ID,
			$this->ackKey(slug: $slug, tenantId: $tenantId),
			$uid . '@' . $timestamp
		);

		// Human-readable mirror stamped onto the object (single write-path, auto-audited).
		$data['dpoAckBy'] = $uid;
		$data['dpoAckAt'] = $timestamp;

		return $this->objectService->saveObject(
			object: $data,
			register: self::REGISTER_SLUG,
			schema: self::AIFEATURE_SCHEMA,
			uuid: (string)$feature->getUuid()
		);

	}//end acknowledge()

	/**
	 * Record a successful Algoritmeregister publication on the feature.
	 *
	 * Sets `algoritmeregisterStatus = gepubliceerd` and stores the external register
	 * reference through the single ObjectService write-path (auto-audited). Returns null
	 * when the feature is absent / cross-tenant.
	 *
	 * @param string $id The AiFeature UUID.
	 * @param string $reference The external register reference returned by the seam.
	 *
	 * @return ObjectEntity|null The stamped feature, or null when absent.
	 *
	 * @spec openspec/changes/algoritmeregister-publication/specs/algoritmeregister-publication/spec.md#requirement-publication-is-delegated-to-the-fleet-publication-path-not-re-implemented
	 */
	public function recordPublication(string $id, string $reference): ?ObjectEntity {
		$feature = $this->getFeature(id: $id);
		if ($feature === null) {
			return null;
		}

		$data = $feature->getObject();
		$data['algoritmeregisterStatus'] = 'gepubliceerd';
		$data['algoritmeregisterRef'] = $reference;

		return $this->objectService->saveObject(
			object: $data,
			register: self::REGISTER_SLUG,
			schema: self::AIFEATURE_SCHEMA,
			uuid: (string)$feature->getUuid()
		);

	}//end recordPublication()

	/**
	 * Record a withdrawal (intrekken) from the Algoritmeregister on the feature.
	 *
	 * Sets `algoritmeregisterStatus = ingetrokken` through the single ObjectService
	 * write-path. Returns null when the feature is absent / cross-tenant.
	 *
	 * @param string $id The AiFeature UUID.
	 *
	 * @return ObjectEntity|null The stamped feature, or null when absent.
	 *
	 * @spec openspec/changes/algoritmeregister-publication/specs/algoritmeregister-publication/spec.md#requirement-publication-is-delegated-to-the-fleet-publication-path-not-re-implemented
	 */
	public function recordWithdrawal(string $id): ?ObjectEntity {
		$feature = $this->getFeature(id: $id);
		if ($feature === null) {
			return null;
		}

		$data = $feature->getObject();
		$data['algoritmeregisterStatus'] = 'ingetrokken';

		return $this->objectService->saveObject(
			object: $data,
			register: self::REGISTER_SLUG,
			schema: self::AIFEATURE_SCHEMA,
			uuid: (string)$feature->getUuid()
		);

	}//end recordWithdrawal()

	/**
	 * Drive the `enable` lifecycle transition (guarded by the DPO-ack guard).
	 *
	 * @param string $id The AiFeature UUID.
	 *
	 * @return string One of the RESULT_* outcomes.
	 *
	 * @spec openspec/changes/ai-feature-governance-register/tasks.md#task-3-4
	 */
	public function enable(string $id): string {
		return $this->transition(id: $id, target: self::RESULT_ENABLED, from: [self::RESULT_DISABLED]);
	}//end enable()

	/**
	 * Drive the `disable` lifecycle transition (unguarded).
	 *
	 * @param string $id The AiFeature UUID.
	 *
	 * @return string One of the RESULT_* outcomes.
	 *
	 * @spec openspec/changes/ai-feature-governance-register/tasks.md#task-3-4
	 */
	public function disable(string $id): string {
		return $this->transition(id: $id, target: self::RESULT_DISABLED, from: [self::RESULT_ENABLED]);
	}//end disable()

	/**
	 * Apply a lifecycle transition by changing the `lifecycle` field through ObjectService.
	 *
	 * The change is persisted via the single write-path, so OpenRegister's lifecycle
	 * validation listener validates the transition and runs the declarative `requires`
	 * guard; a guard denial (or a listener-rejected transition) surfaces as a
	 * HookStoppedException, mapped here to RESULT_BLOCKED. The from-state is pre-checked
	 * so a genuinely-disallowed transition is reported as RESULT_INVALID without a write.
	 *
	 * @param string $id The AiFeature UUID.
	 * @param string $target The target lifecycle value.
	 * @param array<int,string> $from The lifecycle values from which the transition is allowed.
	 *
	 * @return string One of the RESULT_* outcomes.
	 *
	 * @spec openspec/changes/ai-feature-governance-register/tasks.md#task-3-4
	 */
	private function transition(string $id, string $target, array $from): string {
		$feature = $this->getFeature(id: $id);
		if ($feature === null) {
			return self::RESULT_NOT_FOUND;
		}

		$data = $feature->getObject();
		$current = (string)($data['lifecycle'] ?? self::RESULT_DISABLED);

		// Idempotent no-op when already in the target state.
		if ($current === $target) {
			return $target;
		}

		if (in_array($current, $from, true) === false) {
			return self::RESULT_INVALID;
		}

		$data['lifecycle'] = $target;

		try {
			$this->objectService->saveObject(
				object: $data,
				register: self::REGISTER_SLUG,
				schema: self::AIFEATURE_SCHEMA,
				uuid: (string)$feature->getUuid()
			);
		} catch (HookStoppedException $e) {
			// The lifecycle guard denied the transition (e.g. no DPO acknowledgement),
			// or the validation listener rejected it. Surface as a refusal, not a 500.
			return self::RESULT_BLOCKED;
		}

		return $target;
	}//end transition()

	/**
	 * Resolve the tenant scope from an AiFeature payload (matches the guard).
	 *
	 * @param array<string, mixed> $object The AiFeature payload.
	 *
	 * @return string The tenant identifier, or an empty string when untenanted.
	 *
	 * @spec openspec/changes/ai-feature-governance-register/tasks.md#task-3-3
	 */
	private function resolveTenant(array $object): string {
		return trim((string)($object['tenantId'] ?? ($object['tenant_id'] ?? '')));
	}//end resolveTenant()

	/**
	 * Build the IAppConfig acknowledgement key for a feature slug + tenant (matches the guard).
	 *
	 * @param string $slug The feature slug.
	 * @param string $tenantId The tenant identifier, or an empty string.
	 *
	 * @return string The IAppConfig key.
	 *
	 * @spec openspec/changes/ai-feature-governance-register/tasks.md#task-3-3
	 */
	private function ackKey(string $slug, string $tenantId): string {
		if ($tenantId !== '') {
			return self::ACK_KEY_PREFIX . '.' . $tenantId . '.' . $slug;
		}

		return self::ACK_KEY_PREFIX . '.' . $slug;
	}//end ackKey()
}//end class
