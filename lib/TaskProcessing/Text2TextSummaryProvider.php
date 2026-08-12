<?php

/**
 * Hermiq `core:text2text:summary` provider.
 *
 * Registers Hermiq as a provider for Nextcloud's summarisation task type
 * (`core:text2text:summary`), backed by Hermiq's configured LLM
 * (SPECTR-NEXTCLOUD-PLAN.md §8 move 2). Because Hermiq's LLM layer is a generic
 * chat model (not a model with native task-typed endpoints), the summary intent is
 * expressed by framing the input in an explicit "summarise the following" prompt.
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
 * @spec openspec/changes/taskprocessing-provide-text2text/tasks.md#task-1-3
 */

declare(strict_types=1);

namespace OCA\Hermiq\TaskProcessing;

use OCP\TaskProcessing\TaskTypes\TextToTextSummary;

/**
 * Hermiq's provider for the `core:text2text:summary` task type.
 *
 * @spec openspec/changes/taskprocessing-provide-text2text/tasks.md#task-1-3
 */
class Text2TextSummaryProvider extends AbstractTextProvider {
	/**
	 * The unique id of this provider.
	 *
	 * @return string
	 *
	 * @spec exclude Trivial provider identity accessor; no behavioural spec.
	 */
	public function getId(): string {
		return 'hermiq:text2text:summary';
	}//end getId()

	/**
	 * The localized name of this provider.
	 *
	 * @return string
	 *
	 * @spec exclude Trivial provider name accessor; no behavioural spec.
	 */
	public function getName(): string {
		return 'Hermiq';
	}//end getName()

	/**
	 * The task type this provider handles.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/taskprocessing-provide-text2text/tasks.md#task-1-3
	 */
	public function getTaskTypeId(): string {
		return TextToTextSummary::ID;
	}//end getTaskTypeId()

	/**
	 * Frame the input as a summarisation instruction.
	 *
	 * @param string $input The original text to summarise.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/taskprocessing-provide-text2text/tasks.md#task-1-3
	 */
	protected function buildPrompt(string $input): string {
		return 'Summarize the following text concisely, preserving the key points. '
			. "Reply with only the summary.\n\n" . $input;
	}//end buildPrompt()
}//end class
