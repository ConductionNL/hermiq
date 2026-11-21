<?php

/**
 * Hermiq DraftModel
 *
 * The provider and model an agent draft proposes.
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
 * A proposed provider and model.
 *
 * @spec openspec/changes/agents-plain-language-builder/specs/agent-management-ui/spec.md#requirement-a-draft-opens-in-the-full-agent-form-after-a-check-req-agbuild-002
 */
final class DraftModel {

	/**
	 * Build the proposal.
	 *
	 * @param string $provider The proposed provider.
	 * @param string $model    The proposed model.
	 *
	 * @spec openspec/changes/agents-plain-language-builder/specs/agent-management-ui/spec.md#requirement-a-draft-opens-in-the-full-agent-form-after-a-check-req-agbuild-002
	 */
	public function __construct(
		public readonly string $provider,
		public readonly string $model,
	) {
	}//end __construct()

	/**
	 * The proposal as `provider/model`.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/agents-plain-language-builder/specs/agent-management-ui/spec.md#requirement-a-draft-opens-in-the-full-agent-form-after-a-check-req-agbuild-002
	 */
	public function wanted(): string {
		return $this->provider . '/' . $this->model;
	}//end wanted()
}//end class
