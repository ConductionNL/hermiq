<?php

/**
 * Hermiq AllowedModels
 *
 * The models an organisation's model policy allows, as suggestion candidates.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Agent
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
 * @spec openspec/changes/agents-plain-language-builder/specs/agent-management-ui/spec.md#requirement-a-draft-opens-in-the-full-agent-form-after-a-check-req-agbuild-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Agent;

/**
 * A policy's allowed entries.
 *
 * @spec openspec/changes/agents-plain-language-builder/specs/agent-management-ui/spec.md#requirement-a-draft-opens-in-the-full-agent-form-after-a-check-req-agbuild-002
 */
final class AllowedModels {

	/**
	 * Build the list.
	 *
	 * @param array<array-key, mixed> $allowed The policy's allowed entries.
	 *
	 * @spec openspec/changes/agents-plain-language-builder/specs/agent-management-ui/spec.md#requirement-a-draft-opens-in-the-full-agent-form-after-a-check-req-agbuild-002
	 */
	public function __construct(
		private readonly array $allowed,
	) {
	}//end __construct()

	/**
	 * Every allowed `provider/model`, or the provider alone when it allows any model.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/agents-plain-language-builder/specs/agent-management-ui/spec.md#requirement-a-draft-opens-in-the-full-agent-form-after-a-check-req-agbuild-002
	 */
	public function candidates(): array {
		$candidates = [];
		foreach ($this->allowed as $entry) {
			$allowedProvider = (string)($entry['provider'] ?? '');
			$models = (array)($entry['models'] ?? []);
			if ($models === []) {
				$candidates[] = $allowedProvider;
				continue;
			}

			foreach ($models as $allowedModel) {
				$candidates[] = $allowedProvider . '/' . $allowedModel;
			}
		}

		return $candidates;
	}//end candidates()
}//end class
