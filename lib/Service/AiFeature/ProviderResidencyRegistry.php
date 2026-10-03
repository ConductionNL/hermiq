<?php

/**
 * Hermiq ProviderResidencyRegistry.
 *
 * Where a configured chat provider runs, as stated by whoever configured it. The
 * label is administered and never inferred: a `.eu` hostname resolving to a US
 * region is ordinary, and an on-premise reverse proxy in front of a hosted model
 * looks local from inside. A guessed residency is one no data protection officer
 * can rely on, and reliance is the whole purpose of the field.
 *
 * Stored as one `IAppConfig` JSON map (`hermiq.providerResidency`) keyed by
 * provider id, each entry carrying the typed residency plus the free-text location
 * an enum cannot hold ("Frankfurt, AWS eu-central-1").
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/a-provider-and-a-place-per-ai-feature/specs/ai-feature-governance/spec.md#requirement-a-configured-provider-must-declare-where-it-runs
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\AiFeature;

use InvalidArgumentException;
use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\Service\Llm\LlmSettingsHandler;
use OCP\IAppConfig;

/**
 * Reads and writes the administered residency label of each configured provider.
 *
 * @spec openspec/changes/a-provider-and-a-place-per-ai-feature/specs/ai-feature-governance/spec.md#requirement-a-configured-provider-must-declare-where-it-runs
 */
class ProviderResidencyRegistry {

	/**
	 * The provider runs inside the organisation's own premises.
	 *
	 * @var string
	 */
	public const RESIDENCY_ON_PREMISE = 'on-premise';

	/**
	 * The provider runs in the European Union.
	 *
	 * @var string
	 */
	public const RESIDENCY_EU = 'eu';

	/**
	 * The provider runs outside the European Union.
	 *
	 * @var string
	 */
	public const RESIDENCY_OUTSIDE_EU = 'outside-eu';

	/**
	 * No administrator has stated where this provider runs. Never a guess, and
	 * never a default that reads as safe.
	 *
	 * @var string
	 */
	public const RESIDENCY_UNDECLARED = 'undeclared';

	/**
	 * The residencies an administrator may state.
	 *
	 * @var array<int, string>
	 */
	public const DECLARABLE_RESIDENCIES = [
		self::RESIDENCY_ON_PREMISE,
		self::RESIDENCY_EU,
		self::RESIDENCY_OUTSIDE_EU,
	];

	/**
	 * The IAppConfig key holding the JSON map of declarations.
	 *
	 * @var string
	 */
	private const CONFIG_KEY = 'providerResidency';

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
	 * @return array<string, array{provider: string, residency: string, location: string}> The declarations.
	 *
	 * @spec openspec/changes/a-provider-and-a-place-per-ai-feature/specs/ai-feature-governance/spec.md#requirement-a-configured-provider-must-declare-where-it-runs
	 */
	public function all(): array {
		$out = [];
		foreach (array_keys($this->stored()) as $provider) {
			$out[$provider] = $this->forProvider(provider: (string)$provider);
		}

		return $out;
	}//end all()

	/**
	 * What an administrator stated about one provider. An undeclared provider
	 * reads `undeclared`: nothing here looks at an endpoint, a hostname or an
	 * address, so there is no code path that could guess.
	 *
	 * @param string $provider The provider id.
	 *
	 * @return array{provider: string, residency: string, location: string} The declaration.
	 *
	 * @spec openspec/changes/a-provider-and-a-place-per-ai-feature/specs/ai-feature-governance/spec.md#requirement-a-configured-provider-must-declare-where-it-runs
	 */
	public function forProvider(string $provider): array {
		$entry = ($this->stored()[$provider] ?? null);

		$residency = self::RESIDENCY_UNDECLARED;
		$location = '';
		if (is_array($entry) === true) {
			$candidate = (string)($entry['residency'] ?? '');
			if (in_array($candidate, self::DECLARABLE_RESIDENCIES, true) === true) {
				$residency = $candidate;
			}

			$location = (string)($entry['location'] ?? '');
		}

		return [
			'provider' => $provider,
			'residency' => $residency,
			'location' => $location,
		];

	}//end forProvider()

	/**
	 * State where a provider runs.
	 *
	 * @param string $provider The provider id, one of the supported chat providers.
	 * @param string $residency One of `on-premise`, `eu`, `outside-eu`.
	 * @param string $location The free-text location detail, e.g. "Frankfurt, AWS eu-central-1".
	 *
	 * @return array{provider: string, residency: string, location: string} The stored declaration.
	 *
	 * @throws InvalidArgumentException When the provider or the residency is not a supported value.
	 *
	 * @spec openspec/changes/a-provider-and-a-place-per-ai-feature/specs/ai-feature-governance/spec.md#requirement-a-configured-provider-must-declare-where-it-runs
	 */
	public function declareResidency(string $provider, string $residency, string $location = ''): array {
		if (in_array($provider, LlmSettingsHandler::ALLOWED_CHAT_PROVIDERS, true) === false) {
			throw new InvalidArgumentException(
				"Unsupported provider '{$provider}' — must be one of: " . implode(', ', LlmSettingsHandler::ALLOWED_CHAT_PROVIDERS)
			);
		}

		if (in_array($residency, self::DECLARABLE_RESIDENCIES, true) === false) {
			throw new InvalidArgumentException(
				"Unsupported residency '{$residency}' — must be one of: " . implode(', ', self::DECLARABLE_RESIDENCIES)
			);
		}

		$stored = $this->stored();
		$stored[$provider] = [
			'residency' => $residency,
			'location' => trim($location),
		];

		$this->appConfig->setValueString(
			Application::APP_ID,
			self::CONFIG_KEY,
			(string)json_encode($stored)
		);

		return $this->forProvider(provider: $provider);
	}//end declareResidency()

	/**
	 * The raw stored map, or an empty map when nothing has been declared.
	 *
	 * @return array<string, mixed> The stored declarations.
	 */
	private function stored(): array {
		$raw = $this->appConfig->getValueString(Application::APP_ID, self::CONFIG_KEY, '');
		if ($raw === '') {
			return [];
		}

		$decoded = json_decode($raw, true);
		if (is_array($decoded) === false) {
			return [];
		}

		return $decoded;
	}//end stored()
}//end class
