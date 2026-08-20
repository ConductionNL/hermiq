<?php

/**
 * Hermiq EngineRequiredException (run-replay-and-dry-run).
 *
 * Thrown by `ScheduleService::dryRunNow()`/`replayRun()` when
 * `hermiq.engine.enabled` is off. Dry-run/replay's whole mechanism depends on
 * the in-app `Engine`/`FacadeToolInvoker` tool-call interception point — the
 * default OpenRegister `ChatService` path has no comparable hook
 * (`run-trace-observability`'s Out of Scope already established this). A
 * distinct exception (rather than a plain `RuntimeException`) lets the
 * controllers recognise the case and return a stable 422 with an actionable
 * message, instead of matching on exception message text.
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
 * @spec openspec/changes/run-replay-and-dry-run/specs/run-replay-and-dry-run/spec.md#requirement-dry-run-and-replay-require-the-in-app-agent-engine
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use RuntimeException;

/**
 * Signals that dry-run/replay was attempted with the in-app agent engine off.
 *
 * @spec openspec/changes/run-replay-and-dry-run/specs/run-replay-and-dry-run/spec.md#requirement-dry-run-and-replay-require-the-in-app-agent-engine
 */
class EngineRequiredException extends RuntimeException {
	/**
	 * Constructor.
	 *
	 * @param string $message A clear, actionable message naming the required feature flag.
	 */
	public function __construct(string $message = 'Dry-run requires the in-app agent engine. Enable the engine.enabled feature flag first.') {
		parent::__construct(message: $message, code: 422);

	}//end __construct()
}//end class
