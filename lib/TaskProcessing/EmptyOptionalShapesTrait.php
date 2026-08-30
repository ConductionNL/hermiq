<?php

/**
 * Empty optional-shape accessors for Hermiq TaskProcessing providers.
 *
 * Hermiq's providers back task types whose REQUIRED input/output shape is already
 * declared by the core `ITaskType` (e.g. `TextToText`, `ContextAgentInteraction`);
 * they add no OPTIONAL slots and no ENUM slots. This trait supplies the eight
 * `IProvider` optional-shape / enum / default accessors as empty arrays, so each
 * concrete provider needs only its id/name/task-type/runtime + `process()`.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category TaskProcessing
 * @package  OCA\Hermiq\TaskProcessing
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/taskprocessing-provide-text2text/tasks.md#task-1-1
 */

declare(strict_types=1);

namespace OCA\Hermiq\TaskProcessing;

/**
 * Supplies the eight empty optional-shape/enum/default IProvider accessors.
 *
 * @spec openspec/changes/taskprocessing-provide-text2text/tasks.md#task-1-1
 */
trait EmptyOptionalShapesTrait {
	/**
	 * Optional input shape (none).
	 *
	 * @return array
	 *
	 * @psalm-return array<never, never>
	 *
	 * @spec exclude Empty framework-shape accessor (no optional/enum slots); no behavioural spec.
	 */
	public function getOptionalInputShape(): array {
		return [];
	}//end getOptionalInputShape()

	/**
	 * Optional output shape (none).
	 *
	 * @return array
	 *
	 * @psalm-return array<never, never>
	 *
	 * @spec exclude Empty framework-shape accessor (no optional/enum slots); no behavioural spec.
	 */
	public function getOptionalOutputShape(): array {
		return [];
	}//end getOptionalOutputShape()

	/**
	 * Enum options for input shape slots (none).
	 *
	 * @return array
	 *
	 * @psalm-return array<never, never>
	 *
	 * @spec exclude Empty framework-shape accessor (no optional/enum slots); no behavioural spec.
	 */
	public function getInputShapeEnumValues(): array {
		return [];
	}//end getInputShapeEnumValues()

	/**
	 * Defaults for input shape slots (none).
	 *
	 * @return array
	 *
	 * @psalm-return array<never, never>
	 *
	 * @spec exclude Empty framework-shape accessor (no optional/enum slots); no behavioural spec.
	 */
	public function getInputShapeDefaults(): array {
		return [];
	}//end getInputShapeDefaults()

	/**
	 * Enum options for optional input shape slots (none).
	 *
	 * @return array
	 *
	 * @psalm-return array<never, never>
	 *
	 * @spec exclude Empty framework-shape accessor (no optional/enum slots); no behavioural spec.
	 */
	public function getOptionalInputShapeEnumValues(): array {
		return [];
	}//end getOptionalInputShapeEnumValues()

	/**
	 * Defaults for optional input shape slots (none).
	 *
	 * @return array
	 *
	 * @psalm-return array<never, never>
	 *
	 * @spec exclude Empty framework-shape accessor (no optional/enum slots); no behavioural spec.
	 */
	public function getOptionalInputShapeDefaults(): array {
		return [];
	}//end getOptionalInputShapeDefaults()

	/**
	 * Enum options for output shape slots (none).
	 *
	 * @return array
	 *
	 * @psalm-return array<never, never>
	 *
	 * @spec exclude Empty framework-shape accessor (no optional/enum slots); no behavioural spec.
	 */
	public function getOutputShapeEnumValues(): array {
		return [];
	}//end getOutputShapeEnumValues()

	/**
	 * Enum options for optional output shape slots (none).
	 *
	 * @return array
	 *
	 * @psalm-return array<never, never>
	 *
	 * @spec exclude Empty framework-shape accessor (no optional/enum slots); no behavioural spec.
	 */
	public function getOptionalOutputShapeEnumValues(): array {
		return [];
	}//end getOptionalOutputShapeEnumValues()
}//end trait
