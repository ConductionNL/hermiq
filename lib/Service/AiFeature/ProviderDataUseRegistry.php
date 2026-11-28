<?php

/**
 * Hermiq provider data-use registry (models-no-training-guarantee).
 *
 * What an administrator stated each configured provider does with the data it
 * is sent: zero retention, no training, may train, or not declared, with the
 * terms or contract that says so and who stated it when. Administered, never
 * inferred, for the same reason residency is: a guessed term is one no privacy
 * officer can rely on. Stored in app config beside the residency map, because
 * providers are instance configuration, not register objects.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\AiFeature
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/provider-data-use/spec.md#requirement-a-configured-provider-states-what-it-does-with-data-req-notrain-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\AiFeature;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use OCA\Hermiq\Kernel\Contracts\ProviderDataUseRegistryInterface;
use OCA\Hermiq\Service\Llm\LlmSettingsHandler;
use OCP\IAppConfig;

/**
 * Reads and writes the administered data-use statement of each provider.
 *
 * @spec openspec/specs/provider-data-use/spec.md#requirement-a-configured-provider-states-what-it-does-with-data-req-notrain-001
 */
class ProviderDataUseRegistry implements ProviderDataUseRegistryInterface {

	/**
	 * The values that satisfy an organisation's no-training requirement.
	 *
	 * @var array<int, string>
	 */
	public const NEVER_TRAINS = [self::ZERO_RETENTION, self::NO_TRAINING];

	/**
	 * The IAppConfig key holding the JSON map of declarations.
	 */
	private const CONFIG_KEY = 'providerDataUse';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig App config holding the declarations.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Every declaration, keyed by provider id.
	 *
	 * @return array<string, array{provider: string, dataUse: string, termsReference: string, declaredBy: string, declaredAt: string}>
	 *
	 * @spec openspec/specs/provider-data-use/spec.md#requirement-a-configured-provider-states-what-it-does-with-data-req-notrain-001
	 */
	public function all(): array {
		return $this->store()->each(read: fn (string $provider): array => $this->forProvider(provider: $provider));
	}//end all()

	/**
	 * What an administrator stated about one provider; `undeclared` when nothing.
	 *
	 * @param string $provider The provider id.
	 *
	 * @return array{provider: string, dataUse: string, termsReference: string, declaredBy: string, declaredAt: string}
	 *
	 * @spec openspec/specs/provider-data-use/spec.md#requirement-a-configured-provider-states-what-it-does-with-data-req-notrain-001
	 */
	public function forProvider(string $provider): array {
		$entry = ($this->store()->stored()[$provider] ?? null);
		if (is_array($entry) === false) {
			$entry = [];
		}

		$dataUse = (string)($entry['dataUse'] ?? '');
		if (in_array($dataUse, self::DECLARABLE, true) === false) {
			$dataUse = self::UNDECLARED;
		}

		return [
			'provider' => $provider,
			'dataUse' => $dataUse,
			'termsReference' => (string)($entry['termsReference'] ?? ''),
			'declaredBy' => (string)($entry['declaredBy'] ?? ''),
			'declaredAt' => (string)($entry['declaredAt'] ?? ''),
		];

	}//end forProvider()

	/**
	 * Whether a provider has declared it never trains on what it is sent.
	 *
	 * @param string $provider The provider id.
	 *
	 * @return bool True for zero-retention or no-training.
	 *
	 * @spec openspec/specs/provider-data-use/spec.md#requirement-an-organisation-can-require-providers-that-never-train-on-its-data-req-notrain-002
	 */
	public function neverTrains(string $provider): bool {
		return in_array($this->forProvider(provider: $provider)['dataUse'], self::NEVER_TRAINS, true);

	}//end neverTrains()

	/**
	 * State what a provider does with the data it is sent.
	 *
	 * @param string $provider The provider id, one of the supported chat providers.
	 * @param string $dataUse One of zero-retention, no-training, may-train.
	 * @param string $termsReference The terms or contract that says so.
	 * @param string $declaredBy The administrator's user id.
	 *
	 * @return array{provider: string, dataUse: string, termsReference: string, declaredBy: string, declaredAt: string}
	 *
	 * @throws InvalidArgumentException When the provider or the value is not supported.
	 *
	 * @spec openspec/specs/provider-data-use/spec.md#requirement-a-configured-provider-states-what-it-does-with-data-req-notrain-001
	 */
	public function declare(string $provider, string $dataUse, string $termsReference, string $declaredBy): array {
		if (in_array($provider, LlmSettingsHandler::ALLOWED_CHAT_PROVIDERS, true) === false) {
			throw new InvalidArgumentException(
				"Unsupported provider '{$provider}', must be one of: " . implode(', ', LlmSettingsHandler::ALLOWED_CHAT_PROVIDERS)
			);
		}

		if (in_array($dataUse, self::DECLARABLE, true) === false) {
			throw new InvalidArgumentException(
				"Unsupported data use '{$dataUse}', must be one of: " . implode(', ', self::DECLARABLE)
			);
		}

		$this->store()->put(
			provider: $provider,
			entry: [
				'dataUse' => $dataUse,
				'termsReference' => trim($termsReference),
				'declaredBy' => $declaredBy,
				'declaredAt' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM),
			]
		);

		return $this->forProvider(provider: $provider);
	}//end declare()

	/**
	 * This registry's stored declarations.
	 *
	 * @return ProviderDeclarationStore
	 */
	private function store(): ProviderDeclarationStore {
		return new ProviderDeclarationStore(appConfig: $this->appConfig, key: self::CONFIG_KEY);
	}//end store()

}//end class
