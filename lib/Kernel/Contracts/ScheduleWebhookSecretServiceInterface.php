<?php

/**
 * Contract of ScheduleWebhookSecretService
 *
 * The public surface of ScheduleWebhookSecretService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Hermiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Kernel\Contracts;

use OCA\OpenRegister\Db\ObjectEntity;
use RuntimeException;

/**
 * Contract of ScheduleWebhookSecretService.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ScheduleWebhookSecretServiceInterface {

	/**
	 * Mint a new webhook signing secret for a schedule that has none.
	 *
	 * @param ObjectEntity $schedule The schedule to mint a secret for.
	 *
	 * @return array{secret:string, rotatedAt:string} The plaintext secret (shown
	 *                                                once) and the mint timestamp.
	 *
	 * @throws RuntimeException When a secret already exists for this schedule (409 — use rotate()).
	 *
	 * @spec openspec/specs/talk-delivery/spec.md#requirement-a-per-schedule-webhook-signing-secret-can-be-minted-rotated-and-revoked-mvp
	 */
	public function mint(ObjectEntity $schedule): array;

	/**
	 * Revoke a schedule's webhook signing secret. Idempotent: safe to call
	 * even when none is configured (deletes nothing, still returns success) so
	 * an owner never gets a surprising error just tidying up.
	 *
	 * @param ObjectEntity $schedule The schedule whose secret is revoked.
	 *
	 * @return ObjectEntity The persisted schedule with `deliverWebhookSecretConfigured=false`.
	 *
	 * @spec openspec/specs/talk-delivery/spec.md#requirement-a-per-schedule-webhook-signing-secret-can-be-minted-rotated-and-revoked-mvp
	 */
	public function revoke(ObjectEntity $schedule): ObjectEntity;

	/**
	 * Rotate an existing webhook signing secret, invalidating the previous one
	 * immediately (no grace window — mirrors the inbound `WebhookSecretService::rotate()`).
	 *
	 * @param ObjectEntity $schedule The schedule whose secret is rotated.
	 *
	 * @return array{secret:string, rotatedAt:string} The new plaintext secret
	 *                                                (shown once) and the rotation timestamp.
	 *
	 * @throws RuntimeException When no secret exists yet for this schedule (404 — use mint()).
	 *
	 * @spec openspec/specs/talk-delivery/spec.md#requirement-a-per-schedule-webhook-signing-secret-can-be-minted-rotated-and-revoked-mvp
	 */
	public function rotate(ObjectEntity $schedule): array;

	/**
	 * Shape a schedule's webhook-secret state into the status payload the
	 * management endpoints and the UI consume. NEVER includes the plaintext secret.
	 *
	 * @param ObjectEntity $schedule The schedule to report on.
	 *
	 * @return array<string, mixed> The status payload.
	 *
	 * @spec openspec/specs/talk-delivery/spec.md#requirement-a-per-schedule-webhook-signing-secret-can-be-minted-rotated-and-revoked-mvp
	 */
	public function status(ObjectEntity $schedule): array;
}//end interface
