<?php

/**
 * Hermiq AI provider connection report.
 *
 * Works out what the saved `llm` settings mean for the `llm` and `llm-runner`
 * connection rows, and reports both to integriq. It asks the same
 * ProviderFactory a chat turn uses, so the page cannot disagree with what a
 * turn meets. Building a driver and checking the runner make no network call.
 *
 * An unset provider is off, not mocked: chat answers 503. So no status here is
 * ever `simulated` (adopt-connection-registry design D2).
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Connection
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-the-runner-and-the-speech-sidecar-read-honestly-req-hermiq-conn-004
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Connection;

use OCA\Hermiq\Service\Llm\ProviderFactory;
use OCA\Hermiq\Service\Llm\ProviderUnavailableException;
use Throwable;

/**
 * Reports the AI provider and the LLM runner after an AI provider save.
 *
 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-the-runner-and-the-speech-sidecar-read-honestly-req-hermiq-conn-004
 */
class LlmConnectionReport {

	/**
	 * Provider names a message uses, by `chatProvider` value.
	 *
	 * @var array<string, string>
	 */
	private const PROVIDER_NAMES = [
		'openai'    => 'OpenAI',
		'ollama'    => 'Ollama',
		'fireworks' => 'Fireworks AI',
		'anthropic' => 'Anthropic',
		'nextcloud' => 'Nextcloud Assistant',
	];

	/**
	 * Constructor.
	 *
	 * @param ConnectionReporter $reporter        Sends the reports to integriq.
	 * @param ProviderFactory    $providerFactory Builds the driver a chat turn would get, and checks the runner.
	 */
	public function __construct(
		private readonly ConnectionReporter $reporter,
		private readonly ProviderFactory $providerFactory,
	) {
	}//end __construct()

	/**
	 * Report the `llm` and `llm-runner` rows for a saved configuration.
	 *
	 * @param array<string, mixed> $config The merged `llm` configuration the save wrote.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-the-runner-and-the-speech-sidecar-read-honestly-req-hermiq-conn-004
	 */
	public function reportSaved(array $config): void {
		[$status, $message] = $this->describeProvider(config: $config);
		$this->reporter->report(key: 'llm', status: $status, message: $message);

		[$runnerStatus, $runnerMessage] = $this->describeRunner(config: $config);
		$this->reporter->report(key: 'llm-runner', status: $runnerStatus, message: $runnerMessage);
	}//end reportSaved()

	/**
	 * What the saved chat provider means for the `llm` row.
	 *
	 * @param array<string, mixed> $config The saved configuration.
	 *
	 * @return array{0: string, 1: string} The status and the message.
	 */
	private function describeProvider(array $config): array {
		$provider = (string)($config['chatProvider'] ?? '');
		if ($provider === '') {
			return ['unconfigured', 'No chat provider is chosen. Chat answers 503 until one is.'];
		}

		$name = (self::PROVIDER_NAMES[$provider] ?? $provider);

		try {
			$this->providerFactory->createChatDriver(llmConfig: $config);
		} catch (ProviderUnavailableException $e) {
			return ['unconfigured', $name . ' is chosen and cannot answer yet: ' . $e->getMessage()];
		} catch (Throwable $e) {
			return ['error', $name . ' is chosen and failed to load: ' . $e->getMessage()];
		}

		if ($provider === 'nextcloud') {
			return [
				'limited',
				'Nextcloud Assistant runs background work, such as titles and summaries. Chat cannot run on it. Saved, not tested.',
			];
		}

		return ['configured', 'Chat uses ' . $name . '. Saved, not tested.'];
	}//end describeProvider()

	/**
	 * What the saved configuration means for the `llm-runner` row.
	 *
	 * Only Anthropic with `executionMode: cli` sends turns to the runner.
	 *
	 * @param array<string, mixed> $config The saved configuration.
	 *
	 * @return array{0: string, 1: string} The status and the message.
	 */
	private function describeRunner(array $config): array {
		$anthropic = (array)($config['anthropicConfig'] ?? []);
		$routesToRunner = (($config['chatProvider'] ?? '') === 'anthropic' && ($anthropic['executionMode'] ?? 'http') === 'cli');
		if ($routesToRunner === false) {
			return [
				'unconfigured',
				'Not in use. Turns reach the runner only when Anthropic is the provider with executionMode cli.',
			];
		}

		try {
			$this->providerFactory->assertCliRunnerAvailable();
		} catch (Throwable $e) {
			return ['error', $e->getMessage()];
		}

		return ['configured', 'AppAPI reports the hermiq-llm-runner ExApp enabled. Anthropic turns run through it. Not tested.'];
	}//end describeRunner()
}//end class
