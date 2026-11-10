<?php

/**
 * Hermiq: what a turn asks of the provider factory's chat-driver resolution.
 *
 * The agent's model, temperature and output ceiling, its organisation, AI
 * feature and pinned credentials, the document the turn is about and the
 * attachments it carries, each read from the turn the way
 * `ResponseGenerationHandler` has always read them.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Engine
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/agent-engine-port/tasks.md#task-1-1
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Engine;

/**
 * The chat-driver inputs of one turn, read from its run.
 *
 * @spec openspec/changes/agent-engine-port/tasks.md#task-1-1
 */
final class ChatDriverInputs {

	/**
	 * Constructor.
	 *
	 * @param ResponseTurnRun $run The turn.
	 */
	public function __construct(
		private readonly ResponseTurnRun $run,
	) {
	}//end __construct()

	/**
	 * The agent's model override, or null when it names none as a string.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-1-1
	 */
	public function model(): ?string {
		$agentModel = $this->run->agentData()['model'] ?? null;
		if (is_string($agentModel) === false) {
			return null;
		}

		return $agentModel;
	}//end model()

	/**
	 * The agent's temperature override, or null when it is not numeric.
	 *
	 * @return float|null
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-1-1
	 */
	public function temperature(): ?float {
		$temperature = ($this->run->agentData()['temperature'] ?? null);
		if (is_numeric($temperature) === false) {
			return null;
		}

		return (float)$temperature;
	}//end temperature()

	/**
	 * The agent's output ceiling. Stored, versioned and shown in the UI since
	 * agents existed; a non-positive value is treated as unset rather than as
	 * "no tokens".
	 *
	 * @return int|null
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-1-1
	 */
	public function maxTokens(): ?int {
		$maxTokens = ($this->run->agentData()['maxTokens'] ?? null);
		if (is_numeric($maxTokens) === false || (int)$maxTokens <= 0) {
			return null;
		}

		return (int)$maxTokens;
	}//end maxTokens()

	/**
	 * Tenant-model-policy: the agent's organisation, so the resolved (provider,
	 * model) pair is checked against its effective ModelPolicy; null (no agent
	 * bound to this turn) skips the check.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-1-1
	 */
	public function organisation(): ?string {
		if ($this->run->agent === null) {
			return null;
		}

		return (string)($this->run->agent->getOrganisation() ?? '');
	}//end organisation()

	/**
	 * The AI feature this agent's runs belong to, when it declares one. It
	 * selects the feature's own provider binding and the residency it requires.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-1-1
	 */
	public function aiFeature(): ?string {
		$aiFeature = $this->run->agentData()['aiFeature'] ?? null;
		if (is_string($aiFeature) === false || trim($aiFeature) === '') {
			return null;
		}

		return $aiFeature;
	}//end aiFeature()

	/**
	 * The document this turn is about, when the widget's context snapshot names
	 * one. A feature that reads no unredacted document is refused on this
	 * reference, and a turn that carries none still runs: the requirement
	 * attaches to the document, not to the whole run.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-1-1
	 */
	public function documentReference(): ?string {
		foreach (['fileId', 'documentId', 'objectId'] as $key) {
			$candidate = ($this->run->cnAiContext[$key] ?? null);
			if (is_scalar($candidate) === true && trim((string)$candidate) !== '') {
				return trim((string)$candidate);
			}
		}

		return null;
	}//end documentReference()

	/**
	 * The agent's own credential per provider, as stored; the resolver reads only
	 * non-empty string pins.
	 *
	 * @return array<mixed>
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-1-1
	 */
	public function credentialIds(): array {
		return (array)($this->run->agentData()['credentialIds'] ?? []);
	}//end credentialIds()

	/**
	 * The file id of each attachment, so the feature's gates check each one
	 * before any request is built.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-1-1
	 */
	public function attachmentReferences(): array {
		return array_map(
			static fn (array $attachment): string => (string)($attachment['fileId'] ?? ''),
			$this->run->attachments
		);
	}//end attachmentReferences()
}//end class
