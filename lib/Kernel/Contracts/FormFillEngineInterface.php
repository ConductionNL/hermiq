<?php

/**
 * Contract of FormFillEngine
 *
 * The public surface of FormFillEngine that other components call, so they depend
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

/**
 * Contract of FormFillEngine: propose form field values from pasted text.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface FormFillEngineInterface {

	/**
	 * The AiFeature slug the engine is gated by.
	 *
	 * @var string
	 */
	public const FEATURE_SLUG = 'form-fill';

	/**
	 * Whether form fill may run now: the gate only, no model call.
	 *
	 * @return bool True when the `form-fill` feature is enabled.
	 *
	 * @spec openspec/changes/forms-smart-paste-fill/specs/form-fill/spec.md#requirement-form-fill-is-off-until-an-admin-acknowledges-it-req-ffill-001
	 */
	public function isAvailable(): bool;

	/**
	 * Propose values for the given fields from the pasted text.
	 *
	 * @param string $text The pasted text.
	 * @param array<int, array{key: string, label: string, type: string, options: array<int, string>}> $fields The allowed fields.
	 * @param string $language BCP-47 tag of the form's language.
	 * @param string $userId The requesting user.
	 *
	 * @return array<string, mixed> `{available: true, values, draft: true, notice, provider}` or `{available: false, reason}`.
	 *
	 * @spec openspec/changes/forms-smart-paste-fill/specs/form-fill/spec.md#requirement-proposals-fit-the-allowed-fields-req-ffill-003
	 */
	public function fill(string $text, array $fields, string $language, string $userId): array;
}//end interface
