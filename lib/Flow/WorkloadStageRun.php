<?php

/**
 * One item's workload stage: the step configuration and the item.
 *
 * Built per item by the workload step so the helpers that turn the item into a
 * stage request share one placeholder substitution.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Flow
 * @package  OCA\Hermiq\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/exapp-stage-workload/specs/exapp-stage-workload/spec.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Flow;

/**
 * The configuration and placeholders one item's stage is built from.
 *
 * @spec openspec/changes/exapp-stage-workload/specs/exapp-stage-workload/spec.md
 */
final class WorkloadStageRun {

	/**
	 * Constructor.
	 *
	 * @param array            $config       The step configuration.
	 * @param ItemPlaceholders $placeholders The item's placeholder substitution.
	 */
	public function __construct(
		public readonly array $config,
		public readonly ItemPlaceholders $placeholders,
	) {
	}//end __construct()

	/**
	 * A configured value, rendered against the item ('' when not configured).
	 *
	 * @param string $key The configuration key.
	 *
	 * @return string The rendered value.
	 *
	 * @spec openspec/changes/exapp-stage-workload/specs/exapp-stage-workload/spec.md
	 */
	public function rendered(string $key): string {
		return $this->placeholders->render(template: (string)($this->config[$key] ?? ''));
	}//end rendered()

	/**
	 * A configured value, rendered against the item and trimmed.
	 *
	 * @param string $key The configuration key.
	 *
	 * @return string The trimmed rendered value.
	 *
	 * @spec openspec/changes/exapp-stage-workload/specs/exapp-stage-workload/spec.md
	 */
	public function trimmed(string $key): string {
		return trim($this->rendered(key: $key));
	}//end trimmed()

	/**
	 * Whether the step asks for an asynchronous dispatch.
	 *
	 * @return boolean True when `async` is set to true.
	 *
	 * @spec openspec/changes/exapp-stage-workload/specs/exapp-stage-workload/spec.md
	 */
	public function isAsync(): bool {
		return ($this->config['async'] ?? false) === true;
	}//end isAsync()
}//end class
