<?php

/**
 * Hermiq TaskProcessing text provider base.
 *
 * Shared base for Hermiq's `core:text2text*` PROVIDER implementations
 * (SPECTR-NEXTCLOUD-PLAN.md §8 move 2): registering these makes the whole
 * Nextcloud instance (Assistant UI, decidesk, Mail, …) get AI from Hermiq's one
 * LLM configuration — e.g. decidesk 503s without ANY TaskProcessing provider, and
 * this un-503s it with zero decidesk changes.
 *
 * The `core:text2text`, `core:text2text:summary` and `core:text2text:headline`
 * task types all share the identical `{input}` → `{output}` shape (verified against
 * the NC 33/34 checkout's OCP\TaskProcessing\TaskTypes\*), differing only in id,
 * name and intent — so all three subclasses need only supply their id/name/runtime
 * and their prompt framing. `process()` runs the framed prompt through Hermiq's
 * configured LLM (`ProviderFactory::generateText`), with the `nextcloud`
 * (TaskProcessing) driver forbidden so a Hermiq provider can never recurse into
 * TaskProcessing.
 *
 * The 12 optional-shape / enum / default accessors are all empty here — these are
 * plain single-input single-output text providers with no optional or enum slots —
 * satisfying `IProvider` without per-subclass boilerplate.
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

use OCA\Hermiq\Service\Llm\ProviderFactory;
use OCP\TaskProcessing\Exception\ProcessingException;
use OCP\TaskProcessing\ISynchronousProvider;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Base for Hermiq's synchronous text2text-family providers.
 *
 * @spec openspec/changes/taskprocessing-provide-text2text/tasks.md#task-1-1
 */
abstract class AbstractTextProvider implements ISynchronousProvider
{
    use EmptyOptionalShapesTrait;

    /**
     * Constructor.
     *
     * @param ProviderFactory $providerFactory Hermiq's configured-LLM generation seam.
     * @param LoggerInterface $logger          Logger.
     *
     * @return void
     */
    public function __construct(
        protected readonly ProviderFactory $providerFactory,
        protected readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Frame the raw user input into the prompt this task type should send to the
     * language model (e.g. wrap in a "summarize the following" instruction).
     *
     * @param string $input The raw `input` slot value.
     *
     * @return string The prompt to send to the LLM.
     *
     * @spec openspec/changes/taskprocessing-provide-text2text/tasks.md#task-1-3
     */
    abstract protected function buildPrompt(string $input): string;

    /**
     * Run the task: frame the input, generate via Hermiq's configured LLM, return
     * the `output` slot. The `nextcloud` driver is forbidden here — a Hermiq
     * TaskProcessing provider backed by the TaskProcessing driver would recurse.
     *
     * @param string|null $userId         The user that created the task.
     * @param array       $input          The task input (expects `input` string).
     * @param callable    $reportProgress Progress reporter (single blocking call; reported once).
     *
     * @return array{output: string} The generated reply.
     *
     * @throws ProcessingException When the input is missing/empty or generation fails.
     *
     * @psalm-param  callable(float):bool $reportProgress
     * @psalm-return array{output: string}
     *
     * @spec openspec/changes/taskprocessing-provide-text2text/tasks.md#task-1-2
     */
    public function process(?string $userId, array $input, callable $reportProgress): array
    {
        $text = $input['input'] ?? null;
        if (is_string($text) === false || trim($text) === '') {
            throw new ProcessingException('Hermiq text provider requires a non-empty "input".');
        }

        try {
            $output = $this->providerFactory->generateText(
                prompt: $this->buildPrompt(input: $text),
                userId: $userId,
                allowNextcloud: false
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                message: '[Hermiq TaskProcessing] text2text generation failed',
                context: [
                    'file'       => __FILE__,
                    'line'       => __LINE__,
                    'taskTypeId' => $this->getTaskTypeId(),
                    'error'      => $e->getMessage(),
                ]
            );
            throw new ProcessingException('Hermiq could not generate a reply: '.$e->getMessage(), 0, $e);
        }

        // Report completion so cancelled tasks stop cleanly (single blocking call).
        $reportProgress(1.0);

        return ['output' => $output];

    }//end process()

    /**
     * The expected average runtime of a task in seconds.
     *
     * @return int
     *
     * @spec exclude Trivial framework runtime hint; no behavioural spec.
     */
    public function getExpectedRuntime(): int
    {
        return 10;
    }//end getExpectedRuntime()
}//end class
