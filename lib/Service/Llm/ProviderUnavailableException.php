<?php

/**
 * Hermiq LLM Provider Unavailable Exception.
 *
 * Thrown by ProviderFactory when the requested chat provider cannot be used right
 * now — e.g. the `nextcloud` TaskProcessing driver was selected but
 * `IManager::hasProviders()` is false, or a provider's required credentials are
 * missing. A recoverable, catchable signal (not a fatal \Error) so callers (Engine
 * response generation, background title/summary jobs) can degrade gracefully —
 * mirrors decidesk's existing 503-without-provider convention.
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
 * @spec openspec/changes/agent-engine-port/tasks.md#task-2-2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Llm;

use RuntimeException;

/**
 * Recoverable "no usable LLM provider right now" signal.
 *
 * @spec openspec/changes/agent-engine-port/tasks.md#task-2-2
 */
class ProviderUnavailableException extends RuntimeException {
}//end class
