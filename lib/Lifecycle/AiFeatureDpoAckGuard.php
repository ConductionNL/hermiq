<?php

/**
 * Hermiq AiFeatureDpoAckGuard.
 *
 * The imperative business-rule seam (ADR-031 justified exception) that gates the
 * declarative `enable` transition of an `AiFeature`: activation is refused until the
 * Data Protection Officer has acknowledged the feature. The acknowledgement is a
 * separately-authorised act by a different party (the DPO), recorded in `IAppConfig`
 * under `dpo_ack.<tenantId>.<slug>` (or the legacy unscoped `dpo_ack.<slug>`), NOT in
 * the tenant-writable object body — so it cannot be expressed as a plain declarative
 * property gate.
 *
 * `riskCategory: unacceptable` (EU AI Act Art. 5 prohibited practice) is refused
 * unconditionally, before any acknowledgement is even consulted — unlike `high`, no
 * DPO sign-off can legitimise enabling a prohibited practice, so this is not a
 * stricter version of the ack check but a separate, unwaivable denial.
 *
 * OpenRegister's lifecycle engine resolves the transition's `requires` FQCN via the
 * server container (DI autowire — no Application.php registration needed) and calls
 * `check()` before the `enable` transition is persisted. A read-only verdict: the guard
 * MUST NOT mutate the object (side effects belong on ObjectTransitionedEvent listeners).
 * Fail-closed: a missing slug or an absent acknowledgement denies the transition.
 *
 * @category Lifecycle
 * @package  OCA\Hermiq\Lifecycle
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
 * @spec openspec/changes/ai-feature-governance-register/tasks.md#2-dpo-ack-lifecycle-guard-imperative-business-rule-seam-adr-031
 */

declare(strict_types=1);

namespace OCA\Hermiq\Lifecycle;

use OCA\Hermiq\AppInfo\Application;
use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use OCP\IAppConfig;

/**
 * Blocks an AiFeature `enable` transition until the DPO acknowledgement is recorded.
 *
 * @spec openspec/changes/ai-feature-governance-register/tasks.md#task-2-1
 */
class AiFeatureDpoAckGuard implements LifecycleGuardInterface {

	/**
	 * IAppConfig key prefix under which a DPO acknowledgement is recorded.
	 *
	 * @var string
	 */
	private const ACK_KEY_PREFIX = 'dpo_ack';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig App config holding the authoritative DPO acknowledgement.
	 *
	 * @spec openspec/changes/ai-feature-governance-register/tasks.md#task-2-1
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Authorise the `enable` transition iff the DPO has acknowledged the feature.
	 *
	 * Derives the acknowledgement key identically to the acknowledge write-path
	 * (AiFeatureService::acknowledge): the object's `slug` plus its tenant (`tenantId`,
	 * falling back to the legacy `tenant_id`). Returns an allow verdict only when
	 * `IAppConfig` holds a non-empty acknowledgement string for that key; a missing slug
	 * or an absent acknowledgement denies the transition (fail-closed).
	 *
	 * @param array<string, mixed> $object The loaded object payload at its current state.
	 * @param string $action The transition action being applied (e.g. `enable`).
	 * @param string $userId The uid of the caller.
	 *
	 * @return GuardResult Allow when acknowledged, otherwise deny with a user-visible reason.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The interface passes action + userId; this
	 *   guard authorises purely on the DPO acknowledgement state, so neither is read.
	 * @SuppressWarnings(PHPMD.StaticAccess)          GuardResult::allow()/deny() is OpenRegister's mandated
	 *   verdict factory (LifecycleGuardInterface contract); there is no instance alternative.
	 *
	 * @spec openspec/changes/ai-feature-governance-register/tasks.md#task-2-1
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		if (($object['riskCategory'] ?? null) === 'unacceptable') {
			// EU AI Act Art. 5: a prohibited practice cannot be legitimised by any
			// acknowledgement, DPO or otherwise. Unwaivable — checked before, and
			// independently of, the ack lookup below.
			return GuardResult::deny(
				message: 'This AI feature is classified "unacceptable" (a prohibited practice under '
					. 'EU AI Act Art. 5) and cannot be enabled, regardless of DPO acknowledgement.'
			);
		}

		$slug = trim((string)($object['slug'] ?? ''));
		if ($slug === '') {
			// Fail-closed: without a slug the acknowledgement cannot be verified.
			return GuardResult::deny(
				message: 'This AI feature has no slug, so the DPO acknowledgement cannot be verified.'
			);
		}

		$key = $this->ackKey(slug: $slug, tenantId: $this->resolveTenant(object: $object));
		$ack = $this->appConfig->getValueString(Application::APP_ID, $key, '');
		if ($ack === '') {
			return GuardResult::deny(
				message: 'This AI feature has not been acknowledged by the Data Protection Officer and cannot be enabled.'
			);
		}

		return GuardResult::allow();
	}//end check()

	/**
	 * Resolve the tenant scope from an AiFeature payload.
	 *
	 * Reads `tenantId`, falling back to the legacy `tenant_id`; an empty value means the
	 * object is single-tenant / untenanted and the unscoped acknowledgement key is used.
	 *
	 * @param array<string, mixed> $object The AiFeature payload.
	 *
	 * @return string The tenant identifier, or an empty string when untenanted.
	 *
	 * @spec openspec/changes/ai-feature-governance-register/tasks.md#task-2-1
	 */
	private function resolveTenant(array $object): string {
		return trim((string)($object['tenantId'] ?? ($object['tenant_id'] ?? '')));
	}//end resolveTenant()

	/**
	 * Build the IAppConfig acknowledgement key for a feature slug + tenant.
	 *
	 * Tenant-scoped (`dpo_ack.<tenantId>.<slug>`) when a tenant is present, otherwise the
	 * legacy unscoped key (`dpo_ack.<slug>`). MUST match AiFeatureService::acknowledge.
	 *
	 * @param string $slug The feature slug.
	 * @param string $tenantId The tenant identifier, or an empty string.
	 *
	 * @return string The IAppConfig key.
	 *
	 * @spec openspec/changes/ai-feature-governance-register/tasks.md#task-2-1
	 */
	private function ackKey(string $slug, string $tenantId): string {
		if ($tenantId !== '') {
			return self::ACK_KEY_PREFIX . '.' . $tenantId . '.' . $slug;
		}

		return self::ACK_KEY_PREFIX . '.' . $slug;
	}//end ackKey()
}//end class
