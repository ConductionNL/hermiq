<?php

/**
 * Which Assistant surface and which model an agent asked for.
 *
 * Two questions that belong to the AGENT rather than to the turn, lifted out of
 * ResponseGenerationHandler because they made that class exceed its complexity
 * ceiling and because neither needs anything the handler holds. Both are pure
 * reads over the agent's own data, which is also why they are static: there is
 * no state to carry and nothing to inject.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Engine
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec exclude Pure reads over agent data, extracted from ResponseGenerationHandler to stay
 *   under the class-complexity gate; the behaviour they feed is specified where it is used.
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Engine;

use OCA\Hermiq\Service\Llm\ProviderFactory;

/**
 * The agent's own answers to "which surface" and "which model".
 *
 * @spec exclude Pure reads over agent data; each behaviour is specified where it is used.
 */
final class AssistantSelection {
	/**
	 * Which TaskProcessing task type this agent asked for.
	 *
	 * Anything other than `contextagent` reads as `text2text`, so an agent that
	 * says nothing behaves exactly as it did before this existed, and a typo
	 * cannot silently hand a governed step to a tool-running provider.
	 *
	 * @param array $agentData The agent object's data.
	 *
	 * @return string Either `text2text` or `contextagent`.
	 *
	 * @spec exclude Pure read over agent data; the surface it selects is specified by the provider that serves it.
	 */
	public static function taskType(array $agentData): string {
		$requested = strtolower(trim((string)($agentData['taskType'] ?? '')));
		if ($requested === ProviderFactory::TASK_TYPE_AGENT) {
			return ProviderFactory::TASK_TYPE_AGENT;
		}

		return ProviderFactory::TASK_TYPE_TEXT;
	}//end taskType()

	/**
	 * The model this agent asked for, or null to leave the provider's default alone.
	 *
	 * Empty is returned as null rather than as an empty string because those mean
	 * opposite things downstream: null is "the admin's choice stands", and '' would
	 * be a request for a model with no name.
	 *
	 * @param array $agentData The agent object's data.
	 *
	 * @return string|null The requested model, or null.
	 *
	 * @spec exclude Pure read over agent data; what is done with the model is specified by ProviderFactory.
	 */
	public static function model(array $agentData): ?string {
		$model = trim((string)($agentData['model'] ?? ''));
		if ($model === '') {
			return null;
		}

		return $model;
	}//end model()

	/**
	 * The provider this agent names, when it names one.
	 *
	 * Agents have carried a `provider` field since they existed, stored and shown
	 * while nothing read it, so an agent authored against one provider ran on
	 * whatever the instance's single `chatProvider` happened to be. Null means the
	 * instance setting stands.
	 *
	 * @param array $agentData The agent object's data.
	 *
	 * @return string|null The named provider, or null.
	 *
	 * @spec exclude Pure read over agent data; the override it feeds is specified by ProviderFactory::createChatDriver().
	 */
	public static function provider(array $agentData): ?string {
		$provider = ($agentData['provider'] ?? null);
		if (is_string($provider) === false || trim($provider) === '') {
			return null;
		}

		return $provider;
	}//end provider()

	/**
	 * Flatten a turn into the single prompt string TaskProcessing accepts.
	 *
	 * `core:text2text` takes one `input` string, so the roles a chat model would
	 * have seen as structure are written out as labels instead. Crude, and it is
	 * what the task type offers: Assistant has no multi-turn text task shape.
	 *
	 * @param array $messageHistory The turn, newest last.
	 *
	 * @return string The prompt.
	 *
	 * @spec exclude Pure prompt shaping over a message list, extracted from ResponseGenerationHandler to stay under the class-complexity gate.
	 */
	public static function flatten(array $messageHistory): string {
		$lines = [];
		foreach ($messageHistory as $message) {
			$role = '';
			$content = '';
			if (is_object($message) === true) {
				$role = (string)($message->role->value ?? $message->role ?? '');
				$content = (string)($message->content ?? '');
			} elseif (is_array($message) === true) {
				$role = (string)($message['role'] ?? '');
				$content = (string)($message['content'] ?? '');
			}

			if (trim($content) === '') {
				continue;
			}

			$label = match ($role) {
				'system' => 'Instructions',
				'assistant' => 'Assistant',
				default => 'User',
			};

			$line = $label . ': ' . $content;
			// A turn is often handed to us with the current message already in the
			// history and appended again. Two identical lines in a row read to the
			// model as emphasis and it answers by echoing them back.
			if (end($lines) === $line) {
				continue;
			}

			$lines[] = $line;
		}

		// The text2text task type continues a document rather than answering a turn,
		// so it needs somewhere to write. Without the trailing cue the model tends to
		// repeat the prompt back instead of replying to it.
		$lines[] = 'Assistant:';

		return implode("\n\n", $lines);
	}//end flatten()
}//end class
