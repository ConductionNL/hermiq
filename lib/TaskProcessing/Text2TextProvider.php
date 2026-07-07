<?php

/**
 * Hermiq `core:text2text` provider.
 *
 * Registers Hermiq as a provider for Nextcloud's generic free-text prompt task
 * type (`core:text2text`), backed by Hermiq's configured LLM (SPECTR-NEXTCLOUD-PLAN.md
 * §8 move 2). The prompt is passed through verbatim — this is the raw "prompt in,
 * reply out" surface that consumers such as decidesk's MinutesDraftService already
 * frame themselves before calling `runTask`.
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

use OCP\TaskProcessing\TaskTypes\TextToText;

/**
 * Hermiq's provider for the `core:text2text` task type.
 *
 * @spec openspec/changes/taskprocessing-provide-text2text/tasks.md#task-1-3
 */
class Text2TextProvider extends AbstractTextProvider
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
        return 'hermiq:text2text';
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
        return TextToText::ID;
    }//end getTaskTypeId()

    /**
     * Pass the prompt through unchanged.
     *
     * @param string $input The raw prompt.
     *
     * @return string
     *
     * @spec openspec/changes/taskprocessing-provide-text2text/tasks.md#task-1-3
     */
    protected function buildPrompt(string $input): string
    {
        return $input;
    }//end buildPrompt()
}//end class
