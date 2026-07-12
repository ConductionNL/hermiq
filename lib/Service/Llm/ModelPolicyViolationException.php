<?php

/**
 * Hermiq Model Policy Violation Exception.
 *
 * Thrown by ProviderFactory::createChatDriver() when the resolved (provider, model)
 * pair for an agent turn falls outside the calling organisation's effective
 * ModelPolicy (its own policy, else the instance-wide default, else the fail-closed
 * `hermiq.llm.chatProvider`-only policy). Mirrors ProviderUnavailableException's
 * shape/role: a recoverable, catchable signal — not a fatal \Error — so callers
 * (interactive chat, scheduled/Run-now/flow-triggered runs) can surface a clear,
 * audited refusal instead of a silent pass-through or an unhandled crash.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Llm
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tenant-model-policy/specs/tenant-model-policy/spec.md#requirement-run-time-enforcement-of-the-effective-model-policy
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Llm;

use RuntimeException;

/**
 * Recoverable "out-of-policy provider/model" refusal signal.
 *
 * @spec openspec/changes/tenant-model-policy/specs/tenant-model-policy/spec.md#requirement-run-time-enforcement-of-the-effective-model-policy
 */
class ModelPolicyViolationException extends RuntimeException
{
}//end class
