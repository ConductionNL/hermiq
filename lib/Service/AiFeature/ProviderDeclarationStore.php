<?php

/**
 * Hermiq ProviderDeclarationStore
 *
 * The app-config map one provider declaration registry keeps: what an
 * administrator stated per provider, stored as one JSON object under the
 * registry's own key. Shared by `ProviderDataUseRegistry` and
 * `ProviderResidencyRegistry`, which differ only in what a declaration says.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/provider-data-use/spec.md#requirement-a-configured-provider-states-what-it-does-with-data-req-notrain-001
 * @spec openspec/changes/a-provider-and-a-place-per-ai-feature/specs/ai-feature-governance/spec.md#requirement-a-configured-provider-must-declare-where-it-runs
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\AiFeature;

use OCA\Hermiq\AppInfo\Application;
use OCP\IAppConfig;

/**
 * One registry's stored declarations, keyed by provider id.
 *
 * @spec openspec/specs/provider-data-use/spec.md#requirement-a-configured-provider-states-what-it-does-with-data-req-notrain-001
 */
final class ProviderDeclarationStore {

	/**
	 * Build the store.
	 *
	 * @param IAppConfig $appConfig App config holding the declarations.
	 * @param string     $key       The registry's app-config key.
	 *
	 * @spec openspec/specs/provider-data-use/spec.md#requirement-a-configured-provider-states-what-it-does-with-data-req-notrain-001
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly string $key,
	) {
	}//end __construct()

	/**
	 * The raw stored map, or an empty map when nothing has been declared.
	 *
	 * @return array<array-key, mixed> The stored declarations.
	 *
	 * @spec openspec/specs/provider-data-use/spec.md#requirement-a-configured-provider-states-what-it-does-with-data-req-notrain-001
	 */
	public function stored(): array {
		$raw = $this->appConfig->getValueString(Application::APP_ID, $this->key, '');
		if ($raw === '') {
			return [];
		}

		$decoded = json_decode($raw, true);
		if (is_array($decoded) === false) {
			return [];
		}

		return $decoded;
	}//end stored()

	/**
	 * Every stored provider, read through the registry's own reader.
	 *
	 * @param callable(string): array<string, string> $read Reads one provider's declaration.
	 *
	 * @return array<array-key, array<string, string>> The declarations, keyed by provider id.
	 *
	 * @spec openspec/specs/provider-data-use/spec.md#requirement-a-configured-provider-states-what-it-does-with-data-req-notrain-001
	 */
	public function each(callable $read): array {
		$out = [];
		foreach (array_keys($this->stored()) as $provider) {
			$out[$provider] = $read((string)$provider);
		}

		return $out;
	}//end each()

	/**
	 * Store one provider's declaration, keeping every other.
	 *
	 * @param string               $provider The provider id.
	 * @param array<string, mixed> $entry    The declaration to store.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/provider-data-use/spec.md#requirement-a-configured-provider-states-what-it-does-with-data-req-notrain-001
	 */
	public function put(string $provider, array $entry): void {
		$stored = $this->stored();
		$stored[$provider] = $entry;

		$this->appConfig->setValueString(Application::APP_ID, $this->key, (string)json_encode($stored));
	}//end put()
}//end class
