<?php

/**
 * Hermiq data-use refusal (models-no-training-guarantee).
 *
 * Thrown before any request is built when the organisation's effective model
 * policy requires providers that never train on its data and the resolved
 * provider has not declared that. It extends the model-policy refusal so every
 * handler that already records a policy refusal as a failed run records this
 * one too; `step()` names the check that refused.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\AiFeature
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/models-no-training-guarantee/specs/provider-data-use/spec.md#requirement-an-organisation-can-require-providers-that-never-train-on-its-data-req-notrain-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\AiFeature;

use OCA\Hermiq\Service\Llm\ModelPolicyViolationException;

/**
 * A run refused by the data-use check.
 *
 * @spec openspec/changes/models-no-training-guarantee/specs/provider-data-use/spec.md#requirement-an-organisation-can-require-providers-that-never-train-on-its-data-req-notrain-002
 */
class DataUseViolationException extends ModelPolicyViolationException {

	/**
	 * The name of the check that refused.
	 */
	public const STEP = 'data-use';

	/**
	 * A stable code the chat surface keys its plain-language message off.
	 */
	public const ERROR_CODE = 'data_use_refused';

	/**
	 * Constructor.
	 *
	 * @param string $organisation The organisation whose policy requires it.
	 * @param string $provider The resolved provider.
	 * @param string $dataUse What that provider has declared.
	 */
	public function __construct(
		public readonly string $organisation,
		public readonly string $provider,
		public readonly string $dataUse,
	) {
		$label = $organisation;
		if ($label === '') {
			$label = '(instance-wide)';
		}

		parent::__construct(
			sprintf(
				"Refused by the %s check: organisation '%s' requires providers that never train on its data, and provider '%s' has declared '%s'.",
				self::STEP,
				$label,
				$provider,
				$dataUse
			),
			422
		);

	}//end __construct()

	/**
	 * Which check refused this run.
	 *
	 * @return string The step name.
	 *
	 * @spec openspec/changes/models-no-training-guarantee/specs/provider-data-use/spec.md#requirement-an-organisation-can-require-providers-that-never-train-on-its-data-req-notrain-002
	 */
	public function step(): string {
		return self::STEP;

	}//end step()

}//end class
