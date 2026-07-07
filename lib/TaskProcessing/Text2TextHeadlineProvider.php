<?php

/**
 * Hermiq `core:text2text:headline` provider.
 *
 * Registers Hermiq as a provider for Nextcloud's headline task type
 * (`core:text2text:headline`), backed by Hermiq's configured LLM
 * (SPECTR-NEXTCLOUD-PLAN.md §8 move 2). The headline intent is expressed by framing
 * the input in an explicit "generate a concise headline" prompt.
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

use OCP\TaskProcessing\TaskTypes\TextToTextHeadline;

/**
 * Hermiq's provider for the `core:text2text:headline` task type.
 *
 * @spec openspec/changes/taskprocessing-provide-text2text/tasks.md#task-1-3
 */
class Text2TextHeadlineProvider extends AbstractTextProvider
{
    /**
     * The unique id of this provider.
     *
     * @return string
     *
     * @spec exclude Trivial provider identity accessor; no behavioural spec.
     */
    public function getId(): string
    {
        return 'hermiq:text2text:headline';
    }//end getId()

    /**
     * The localized name of this provider.
     *
     * @return string
     *
     * @spec exclude Trivial provider name accessor; no behavioural spec.
     */
    public function getName(): string
    {
        return 'Hermiq';
    }//end getName()

    /**
     * The task type this provider handles.
     *
     * @return string
     *
     * @spec openspec/changes/taskprocessing-provide-text2text/tasks.md#task-1-3
     */
    public function getTaskTypeId(): string
    {
        return TextToTextHeadline::ID;
    }//end getTaskTypeId()

    /**
     * Frame the input as a headline-generation instruction.
     *
     * @param string $input The original text to headline.
     *
     * @return string
     *
     * @spec openspec/changes/taskprocessing-provide-text2text/tasks.md#task-1-3
     */
    protected function buildPrompt(string $input): string
    {
        return "Generate a single concise headline (max 10 words) for the following text. "
            ."Reply with only the headline, no punctuation at the end.\n\n".$input;
    }//end buildPrompt()
}//end class
