<?php

/**
 * Hermiq RemoveLegacyLlmKeys repair step.
 *
 * Deletes the OpenAI / Fireworks API keys Hermiq used to hold.
 *
 * They were not merely app-held, they were app-held **in cleartext**: both keys sat inside
 * the `hermiq.llm` JSON blob in `oc_appconfig`, unencrypted — readable by anything that
 * could read the database, and printed verbatim by `occ config:app:get hermiq llm`.
 *
 * The keys now live in OpenRegister's credential broker, which injects them server-side,
 * and nothing reads these fields any more. Leaving them behind would be the worst of both
 * worlds: dead config that is still live secret material, waiting for the next database
 * dump. Deleting them is the point of the migration, not a tidy-up afterwards.
 *
 * The step rewrites the blob rather than deleting it: everything else in `hermiq.llm`
 * (models, provider selection, the Ollama URL, vector config) must survive. Idempotent,
 * and a no-op when no key was ever configured.
 *
 * @category Repair
 * @package  OCA\Hermiq\Repair
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/llm-keys-via-broker/tasks.md#task-4-delete-the-cleartext-keys
 */

declare(strict_types=1);

namespace OCA\Hermiq\Repair;

use OCA\Hermiq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Strips the retired, cleartext LLM API keys out of the `hermiq.llm` config blob.
 *
 * @spec openspec/changes/llm-keys-via-broker/tasks.md
 */
class RemoveLegacyLlmKeys implements IRepairStep {
	/**
	 * The config key holding the LLM settings blob.
	 *
	 * @var string
	 */
	private const LLM_CONFIG_KEY = 'llm';

	/**
	 * The provider sub-blocks that used to carry a cleartext `apiKey`.
	 *
	 * @var array<int, string>
	 */
	private const PROVIDER_BLOCKS = ['openaiConfig', 'fireworksConfig'];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app config.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get the name of this repair step.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/llm-keys-via-broker/tasks.md
	 */
	public function getName(): string {
		return 'Remove the legacy cleartext LLM API keys (they live in the credential broker now)';
	}//end getName()

	/**
	 * Strip `apiKey` from every provider block and rewrite the blob.
	 *
	 * @param IOutput $output The output interface.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/llm-keys-via-broker/tasks.md#task-4-delete-the-cleartext-keys
	 */
	public function run(IOutput $output): void {
		$raw = $this->appConfig->getValueString(Application::APP_ID, self::LLM_CONFIG_KEY, '');
		if ($raw === '') {
			$output->info('No Hermiq LLM config stored; nothing to remove.');
			return;
		}

		$config = json_decode($raw, true);
		if (is_array($config) === false) {
			$output->warning('Hermiq LLM config is not valid JSON; leaving it untouched.');
			return;
		}

		$removed = 0;
		foreach (self::PROVIDER_BLOCKS as $block) {
			if (is_array($config[$block] ?? null) === false) {
				continue;
			}

			if (array_key_exists('apiKey', $config[$block]) === false) {
				continue;
			}

			// Count only keys that actually held something — an empty string is not a
			// secret, and reporting it as one would overstate what this step did.
			if ((string)$config[$block]['apiKey'] !== '') {
				$removed++;
			}

			unset($config[$block]['apiKey']);
		}

		if ($removed === 0) {
			$output->info('No cleartext LLM API keys stored; nothing to remove.');
			return;
		}

		try {
			$this->appConfig->setValueString(
				Application::APP_ID,
				self::LLM_CONFIG_KEY,
				(string)json_encode($config)
			);
		} catch (Throwable $e) {
			// Never fatal, but this IS a secret we meant to remove — say so loudly.
			$output->warning('Could not rewrite the Hermiq LLM config: ' . $e->getMessage());
			$this->logger->error(
				'[Hermiq] Could not remove the cleartext LLM API keys; they are still stored',
				['exception' => $e->getMessage()]
			);
			return;
		}

		$output->info(
			'Removed ' . $removed . ' cleartext LLM API key(s). Select a credential from the broker in the '
			. 'Hermiq LLM settings to re-enable each provider.'
		);
	}//end run()
}//end class
