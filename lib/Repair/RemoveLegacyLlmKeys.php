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
 * @spec openspec/changes/archive/2026-07-12-llm-keys-via-broker/tasks.md#task-4-delete-the-cleartext-keys
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
 * @spec openspec/changes/archive/2026-07-12-llm-keys-via-broker/tasks.md
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
	 * @spec openspec/changes/archive/2026-07-12-llm-keys-via-broker/tasks.md
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
	 * @spec openspec/changes/archive/2026-07-12-llm-keys-via-broker/tasks.md#task-4-delete-the-cleartext-keys
	 */
	public function run(IOutput $output): void {
		$config = $this->storedConfig(output: $output);
		if ($config === null) {
			return;
		}

		$removed = $this->countStoredKeys(config: $config);
		if ($removed === 0) {
			$output->info('No cleartext LLM API keys stored; nothing to remove.');
			return;
		}

		if ($this->rewrite(config: $this->withoutApiKeys(config: $config), output: $output) === true) {
			$output->info(
				'Removed ' . $removed . ' cleartext LLM API key(s). Select a credential from the broker in the '
				. 'Hermiq LLM settings to re-enable each provider.'
			);
		}
	}//end run()

	/**
	 * The stored LLM config blob, or null (with a message) when none is stored or it
	 * is not a JSON object.
	 *
	 * @param IOutput $output The output interface.
	 *
	 * @return array|null
	 */
	private function storedConfig(IOutput $output): ?array {
		$raw = $this->appConfig->getValueString(Application::APP_ID, self::LLM_CONFIG_KEY, '');
		if ($raw === '') {
			$output->info('No Hermiq LLM config stored; nothing to remove.');
			return null;
		}

		$config = json_decode($raw, true);
		if (is_array($config) === false) {
			$output->warning('Hermiq LLM config is not valid JSON; leaving it untouched.');
			return null;
		}

		return $config;
	}//end storedConfig()

	/**
	 * How many provider blocks hold a non-empty `apiKey`.
	 *
	 * Count only keys that actually held something — an empty string is not a
	 * secret, and reporting it as one would overstate what this step did.
	 *
	 * @param array $config The LLM config blob.
	 *
	 * @return int
	 */
	private function countStoredKeys(array $config): int {
		$count = 0;
		foreach ($this->blocksWithApiKey(config: $config) as $block) {
			if ((string)$config[$block]['apiKey'] !== '') {
				$count++;
			}
		}

		return $count;
	}//end countStoredKeys()

	/**
	 * The config with `apiKey` removed from every provider block that has one.
	 *
	 * @param array $config The LLM config blob.
	 *
	 * @return array
	 */
	private function withoutApiKeys(array $config): array {
		foreach ($this->blocksWithApiKey(config: $config) as $block) {
			unset($config[$block]['apiKey']);
		}

		return $config;
	}//end withoutApiKeys()

	/**
	 * The provider blocks that are objects with an `apiKey` entry.
	 *
	 * @param array $config The LLM config blob.
	 *
	 * @return array<int, string>
	 */
	private function blocksWithApiKey(array $config): array {
		return array_values(
			array_filter(
				self::PROVIDER_BLOCKS,
				static fn (string $block): bool => is_array($config[$block] ?? null) === true
					&& array_key_exists('apiKey', $config[$block]) === true
			)
		);
	}//end blocksWithApiKey()

	/**
	 * Write the stripped config back, or warn and log loudly when the write fails.
	 *
	 * @param array $config The config without its API keys.
	 * @param IOutput $output The output interface.
	 *
	 * @return bool Whether the config was written.
	 */
	private function rewrite(array $config, IOutput $output): bool {
		try {
			$this->appConfig->setValueString(
				Application::APP_ID,
				self::LLM_CONFIG_KEY,
				(string)json_encode($config)
			);
		} catch (Throwable $e) {
			$this->reportKeysStillStored(error: $e, output: $output);
			return false;
		}

		return true;
	}//end rewrite()

	/**
	 * Never fatal, but this IS a secret we meant to remove — say so loudly.
	 *
	 * @param Throwable $error Why the rewrite failed.
	 * @param IOutput $output The output interface.
	 *
	 * @return void
	 */
	private function reportKeysStillStored(Throwable $error, IOutput $output): void {
		$output->warning('Could not rewrite the Hermiq LLM config: ' . $error->getMessage());
		$this->logger->error(
			'[Hermiq] Could not remove the cleartext LLM API keys; they are still stored',
			['exception' => $error->getMessage()]
		);
	}//end reportKeysStillStored()
}//end class
