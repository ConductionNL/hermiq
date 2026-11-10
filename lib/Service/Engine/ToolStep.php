<?php

/**
 * Hermiq ToolStep.
 *
 * One tool call of a CLI turn as the chat may show it: the tool, its arguments,
 * its result, how long it took and how it ended.
 *
 * @category  Service
 * @package   OCA\Hermiq\Service\Engine
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://conduction.nl
 *
 * @spec openspec/changes/warm-start-and-cli-step-visibility/specs/warm-start-and-cli-step-visibility/spec.md#requirement-a-cli-turns-tool-calls-are-visible-in-the-chat
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Engine;

/**
 * One recorded tool step.
 *
 * @spec openspec/changes/warm-start-and-cli-step-visibility/specs/warm-start-and-cli-step-visibility/spec.md#requirement-a-cli-turns-tool-calls-are-visible-in-the-chat
 */
final class ToolStep {

	/**
	 * The result payload as the chat may show it.
	 *
	 * @var string
	 */
	public string $result = '';

	/**
	 * How long it took.
	 *
	 * @var int
	 */
	public int $durationMs = 0;

	/**
	 * `ok` or `error`, stored verbatim.
	 *
	 * @var string
	 */
	public string $outcome = RunStepBus::OUTCOME_OK;

	/**
	 * Build the step.
	 *
	 * @param string       $name      The tool that ran.
	 * @param array|string $arguments The arguments as the chat may show them; a
	 *                                 string when the output filter withheld them.
	 */
	public function __construct(
		public readonly string $name,
		public readonly array|string $arguments,
	) {
	}//end __construct()
}//end class
