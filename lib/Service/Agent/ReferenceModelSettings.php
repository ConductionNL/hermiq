<?php

/**
 * Hermiq ReferenceModelSettings.
 *
 * Reads and checks the `ensemble` setting of an agent before it is saved: two
 * or three reference models, each a provider and model pair the
 * organisation's effective model policy allows. The run-time checks (policy,
 * residency, redaction, budget) happen again on every call; this is the
 * save-time check that keeps an agent from naming a model it could never use.
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
 * @link https://conduction.nl
 *
 * @spec openspec/specs/model-ensemble/spec.md#requirement-an-agent-owner-can-switch-on-ensemble-answers-req-mens-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Agent;

use InvalidArgumentException;
use OCA\Hermiq\Service\EffectiveModelPolicy;
use OCA\OpenRegister\Db\OrganisationMapper;

/**
 * Validate and normalise an agent's reference models.
 *
 * @spec openspec/specs/model-ensemble/spec.md#requirement-an-agent-owner-can-switch-on-ensemble-answers-req-mens-001
 */
class ReferenceModelSettings {

	/**
	 * The fewest reference models an enabled ensemble names.
	 */
	public const MIN_REFERENCES = 2;

	/**
	 * The most reference models an ensemble names.
	 */
	public const MAX_REFERENCES = 3;

	/**
	 * The refusal of a reference model the policy does not allow.
	 */
	public const NOT_ALLOWED = "This model is not allowed by your organisation's model policy.";

	/**
	 * Constructor.
	 *
	 * @param EffectiveModelPolicy $policies      The organisation's model policy.
	 * @param OrganisationMapper   $organisations The person's active organisation.
	 */
	public function __construct(
		private readonly EffectiveModelPolicy $policies,
		private readonly OrganisationMapper $organisations,
	) {
	}//end __construct()

	/**
	 * The organisation whose policy applies to a new agent of this person.
	 *
	 * @param string $uid The person.
	 *
	 * @return string The organisation identifier, or ''.
	 *
	 * @spec openspec/specs/model-ensemble/spec.md#requirement-an-agent-owner-can-switch-on-ensemble-answers-req-mens-001
	 */
	public function organisationOf(string $uid): string {
		if ($uid === '') {
			return '';
		}

		return (string)($this->organisations->getActiveOrganisationWithFallback(userId: $uid) ?? '');
	}//end organisationOf()

	/**
	 * Validate and normalise an `ensemble` payload.
	 *
	 * Null clears it. A disabled ensemble keeps its list (so switching it back
	 * on restores the picks) but every kept entry must still be allowed. An
	 * enabled one names two or three entries.
	 *
	 * @param mixed  $raw          The raw `ensemble` value from the request.
	 * @param string $organisation The organisation whose policy applies.
	 *
	 * @return array{enabled: bool, referenceModels: array<int, array{provider: string, model: string}>} The setting.
	 *
	 * @throws InvalidArgumentException When it is malformed, has the wrong number of entries, or names a model the policy does not allow.
	 *
	 * @spec openspec/specs/model-ensemble/spec.md#requirement-an-agent-owner-can-switch-on-ensemble-answers-req-mens-001
	 */
	public function normalise(mixed $raw, string $organisation): array {
		if ($raw === null) {
			return ['enabled' => false, 'referenceModels' => []];
		}

		if (is_array($raw) === false) {
			throw new InvalidArgumentException('ensemble must be an object with enabled and referenceModels');
		}

		$enabled = (($raw['enabled'] ?? false) === true);
		$references = $this->referencesOf(raw: ($raw['referenceModels'] ?? []), organisation: $organisation);

		if ($enabled === true && count($references) < self::MIN_REFERENCES) {
			throw new InvalidArgumentException('An ensemble names at least ' . self::MIN_REFERENCES . ' reference models.');
		}

		return ['enabled' => $enabled, 'referenceModels' => $references];
	}//end normalise()

	/**
	 * The reference models, each checked against the policy.
	 *
	 * @param mixed  $raw          The raw `referenceModels` value.
	 * @param string $organisation The organisation whose policy applies.
	 *
	 * @return array<int, array{provider: string, model: string}> The references in order.
	 *
	 * @throws InvalidArgumentException When the list is malformed, too long, or names a model the policy does not allow.
	 */
	private function referencesOf(mixed $raw, string $organisation): array {
		if (is_array($raw) === false || array_is_list($raw) === false) {
			throw new InvalidArgumentException('referenceModels must be a list of {provider, model} entries');
		}

		if (count($raw) > self::MAX_REFERENCES) {
			throw new InvalidArgumentException('An ensemble names at most ' . self::MAX_REFERENCES . ' reference models.');
		}

		$references = [];
		foreach ($raw as $entry) {
			if (is_array($entry) === false) {
				throw new InvalidArgumentException('Each reference model must be an object with provider and model');
			}

			$provider = trim($this->textOf(value: ($entry['provider'] ?? '')));
			$model = trim($this->textOf(value: ($entry['model'] ?? '')));
			if ($provider === '' || $model === ''
				|| $this->policies->isAllowed(organisation: $organisation, provider: $provider, model: $model) === false
			) {
				throw new InvalidArgumentException(self::NOT_ALLOWED);
			}

			$references[] = ['provider' => $provider, 'model' => $model];
		}

		return $references;
	}//end referencesOf()

	/**
	 * A string field as text, '' when it is not a string.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return string The text.
	 */
	private function textOf(mixed $value): string {
		if (is_string($value) === false) {
			return '';
		}

		return $value;
	}//end textOf()
}//end class
