<?php

/**
 * Hermiq `core:text2text:reformulation` provider: rewrites a text in other words, keeping its meaning and language.
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
 * @spec openspec/changes/taskprocessing-provide-text2text/specs/taskprocessing-provide-text2text/spec.md#requirement-text-can-be-reformulated-or-translated-in-one-click-through-hermiq-req-t2t-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\TaskProcessing;

use OCP\TaskProcessing\TaskTypes\TextToTextReformulation;

/**
 * Hermiq's provider for the `core:text2text:reformulation` task type.
 *
 * @spec openspec/changes/taskprocessing-provide-text2text/specs/taskprocessing-provide-text2text/spec.md#requirement-text-can-be-reformulated-or-translated-in-one-click-through-hermiq-req-t2t-003
 */
class Text2TextReformulationProvider extends AbstractTextProvider {

	/**
	 * The unique id of this provider.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/taskprocessing-provide-text2text/specs/taskprocessing-provide-text2text/spec.md#requirement-text-can-be-reformulated-or-translated-in-one-click-through-hermiq-req-t2t-003
	 */
	public function getId(): string {
		return 'hermiq:text2text:reformulation';
	}//end getId()

	/**
	 * The provider's name as the Assistant shows it.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/taskprocessing-provide-text2text/specs/taskprocessing-provide-text2text/spec.md#requirement-text-can-be-reformulated-or-translated-in-one-click-through-hermiq-req-t2t-003
	 */
	public function getName(): string {
		return 'Hermiq';
	}//end getName()

	/**
	 * The task type this provider answers.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/taskprocessing-provide-text2text/specs/taskprocessing-provide-text2text/spec.md#requirement-text-can-be-reformulated-or-translated-in-one-click-through-hermiq-req-t2t-003
	 */
	public function getTaskTypeId(): string {
		return TextToTextReformulation::ID;
	}//end getTaskTypeId()

	/**
	 * Frame the text as a reformulation request.
	 *
	 * @param string $input The text.
	 *
	 * @return string The prompt.
	 *
	 * @spec openspec/changes/taskprocessing-provide-text2text/specs/taskprocessing-provide-text2text/spec.md#requirement-text-can-be-reformulated-or-translated-in-one-click-through-hermiq-req-t2t-003
	 */
	protected function buildPrompt(string $input): string {
		return 'Reformulate the following text in different words. Keep its meaning, its language and '
			. "roughly its length. Reply with only the reformulated text.\n\n" . $input;
	}//end buildPrompt()
}//end class
