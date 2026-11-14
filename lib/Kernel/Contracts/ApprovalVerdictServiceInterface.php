<?php

/**
 * Contract of ApprovalVerdictService
 *
 * The public surface of ApprovalVerdictService that other components call, so they depend
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

use InvalidArgumentException;

/**
 * Contract of ApprovalVerdictService.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ApprovalVerdictServiceInterface {

	/**
	 * The request fields, every one a non-empty string, echoed in the verdict.
	 *
	 * @var array<int, string>
	 */
	public const REQUEST_FIELDS = ['approvalId', 'toolId', 'binding', 'actingAgent', 'nonce'];

	/**
	 * Answer a verify request with {verdict, signature}.
	 *
	 * @param array<string, mixed> $request The five request fields.
	 *
	 * @return array{verdict: array<string, mixed>, signature: string}
	 *
	 * @throws InvalidArgumentException When a field is missing, empty or not a string.
	 *
	 * @spec openspec/specs/human-approval-gate/spec.md#requirement-hermiq-answers-a-signed-verdict-on-a-toolcall-approval-req-apver-001
	 */
	public function verify(array $request): array;
}//end interface
