<?php

/**
 * Contract of EffectiveModelPolicy
 *
 * The public surface of EffectiveModelPolicy that other components call, so they depend
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
 * Contract of EffectiveModelPolicy.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface EffectiveModelPolicyInterface {

	/**
	 * Resolve the effective policy for an organisation: its own ModelPolicy, else
	 * the organisation-less instance default, else a fail-closed synthetic policy
	 * restricting to the currently-configured `hermiq.llm.chatProvider` only.
	 *
	 * @param string $organisation The organisation identifier (may be '').
	 *
	 * @return array{source:string,allowed:list<array{provider:string,models:list<string>}>,defaultModel:array{provider:string,model:string}|null}
	 *                                                                                                                                             The effective, shaped policy.
	 *
	 * @spec openspec/specs/tenant-model-policy/spec.md#requirement-instance-admin-fallback-policy
	 */
	public function effectivePolicyFor(string $organisation): array;
}//end interface
