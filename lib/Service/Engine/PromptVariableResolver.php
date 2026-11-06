<?php

/**
 * Hermiq PromptVariableResolver.
 *
 * Fills in the placeholders an agent owner writes in an agent's instructions,
 * for the person taking the turn: who they are, their language and
 * organisation, the date and time where they are, the agent's name, the app
 * they are in, and their start field answers.
 *
 * The list is closed and nothing is evaluated: a placeholder is replaced by a
 * value in one pass, so a value that itself reads like a placeholder stays
 * text, and an unknown placeholder is left as written. Only `Agent.prompt` is
 * passed through here; retrieved documents and the person's own messages never
 * are.
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
 * @link https://conduction.nl
 *
 * @spec openspec/specs/agent-management-ui/spec.md#requirement-placeholders-in-an-agents-instructions-are-filled-in-per-turn-req-agvar-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Engine;

use DateTimeZone;
use OCA\Hermiq\Service\Agent\StartFields;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IUserManager;
use Throwable;

/**
 * Per-turn placeholder values and the one-pass fill.
 *
 * @spec openspec/specs/agent-management-ui/spec.md#requirement-placeholders-in-an-agents-instructions-are-filled-in-per-turn-req-agvar-001
 */
class PromptVariableResolver {

	/**
	 * The fixed placeholders, without braces. Start fields add `field.<key>`.
	 *
	 * @var array<int, string>
	 */
	public const PLACEHOLDERS = [
		'user.displayName',
		'user.id',
		'user.language',
		'organisation.name',
		'today',
		'now',
		'agent.name',
		'app.id',
	];

	/**
	 * A placeholder as written: `{{name}}` or `{{group.name}}`, spaces allowed inside.
	 */
	private const PATTERN = '/\{\{\s*([A-Za-z][A-Za-z0-9_]*(?:\.[A-Za-z0-9_]+)?)\s*\}\}/';

	/**
	 * Constructor.
	 *
	 * @param IUserManager            $userManager   Display names.
	 * @param IConfig                 $config        The person's language and time zone.
	 * @param ITimeFactory            $time          The clock.
	 * @param OrganisationMapper|null $organisations Organisation names (null when OpenRegister is absent).
	 * @param StartFields             $startFields   The agent's start fields and their answers.
	 */
	public function __construct(
		private readonly IUserManager $userManager,
		private readonly IConfig $config,
		private readonly ITimeFactory $time,
		private readonly ?OrganisationMapper $organisations = null,
		private readonly StartFields $startFields = new StartFields(),
	) {
	}//end __construct()

	/**
	 * The values for one turn, keyed by placeholder name.
	 *
	 * @param string               $userId       The acting person (a schedule's owner on a scheduled run).
	 * @param array<string, mixed> $agentData    The agent's data (name, startFields).
	 * @param string               $organisation The session's organisation UUID.
	 * @param array<string, mixed> $appContext   The companion's context snapshot.
	 * @param array<string, mixed> $startValues  The session's start field answers.
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/specs/agent-management-ui/spec.md#requirement-placeholders-in-an-agents-instructions-are-filled-in-per-turn-req-agvar-001
	 */
	public function variablesFor(
		string $userId,
		array $agentData,
		string $organisation = '',
		array $appContext = [],
		array $startValues = [],
	): array {
		$now = $this->time->getDateTime('now', $this->zoneOf(userId: $userId));

		$variables = [
			'user.displayName' => $this->displayName(userId: $userId),
			'user.id' => $userId,
			'user.language' => $this->languageOf(userId: $userId),
			'organisation.name' => $this->organisationName(uuid: $organisation),
			'today' => $now->format('Y-m-d'),
			'now' => $now->format('Y-m-d H:i'),
			'agent.name' => trim((string)($agentData['name'] ?? '')),
			'app.id' => $this->appId(appContext: $appContext),
		];

		$fields = $this->startFields->fieldsOf(agentData: $agentData);
		foreach ($this->startFields->answersFor(fields: $fields, values: $startValues) as $key => $answer) {
			$variables['field.' . $key] = $answer;
		}

		return $variables;
	}//end variablesFor()

	/**
	 * Replace every known placeholder in one pass; leave the rest as written.
	 *
	 * @param string                $prompt    The instructions.
	 * @param array<string, string> $variables The values from variablesFor().
	 *
	 * @return string
	 *
	 * @spec openspec/specs/agent-management-ui/spec.md#requirement-placeholders-in-an-agents-instructions-are-filled-in-per-turn-req-agvar-001
	 */
	public static function fill(string $prompt, array $variables): string {
		if ($variables === [] || str_contains($prompt, '{{') === false) {
			return $prompt;
		}

		return (string)preg_replace_callback(
			self::PATTERN,
			static function (array $match) use ($variables): string {
				if (array_key_exists($match[1], $variables) === false) {
					return $match[0];
				}

				return $variables[$match[1]];
			},
			$prompt
		);
	}//end fill()

	/**
	 * The placeholders in the instructions that have no value, each once, as written.
	 *
	 * @param string                $prompt    The instructions.
	 * @param array<string, string> $variables The values from variablesFor().
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/specs/agent-management-ui/spec.md#requirement-placeholders-in-an-agents-instructions-are-filled-in-per-turn-req-agvar-001
	 */
	public static function unknown(string $prompt, array $variables): array {
		preg_match_all(self::PATTERN, $prompt, $matches, PREG_SET_ORDER);

		$unknown = [];
		foreach ($matches as $match) {
			if (array_key_exists($match[1], $variables) === false && in_array($match[0], $unknown, true) === false) {
				$unknown[] = $match[0];
			}
		}

		return $unknown;
	}//end unknown()

	/**
	 * The person's display name, else their user id.
	 *
	 * @param string $userId The person.
	 *
	 * @return string
	 */
	private function displayName(string $userId): string {
		if ($userId === '') {
			return '';
		}

		$user = $this->userManager->get($userId);
		if ($user === null) {
			return $userId;
		}

		return $user->getDisplayName();
	}//end displayName()

	/**
	 * The person's language, else the instance default, else `en`.
	 *
	 * @param string $userId The person.
	 *
	 * @return string
	 */
	private function languageOf(string $userId): string {
		$fallback = $this->config->getSystemValueString('default_language', 'en');
		if ($userId === '') {
			return $fallback;
		}

		$language = (string)$this->config->getUserValue($userId, 'core', 'lang', '');
		if ($language === '') {
			return $fallback;
		}

		return $language;
	}//end languageOf()

	/**
	 * The person's time zone, else the instance default, else UTC.
	 *
	 * @param string $userId The person.
	 *
	 * @return DateTimeZone
	 */
	private function zoneOf(string $userId): DateTimeZone {
		$candidates = [$this->config->getSystemValueString('default_timezone', 'UTC')];
		if ($userId !== '') {
			array_unshift($candidates, (string)$this->config->getUserValue($userId, 'core', 'timezone', ''));
		}

		foreach ($candidates as $candidate) {
			if ($candidate === '') {
				continue;
			}

			try {
				return new DateTimeZone($candidate);
			} catch (Throwable) {
				continue;
			}
		}

		return new DateTimeZone('UTC');
	}//end zoneOf()

	/**
	 * The organisation's name, or an empty string when it cannot be read.
	 *
	 * @param string $uuid The organisation UUID.
	 *
	 * @return string
	 */
	private function organisationName(string $uuid): string {
		if ($uuid === '' || $this->organisations === null) {
			return '';
		}

		try {
			return (string)($this->organisations->findByUuid($uuid)->getName() ?? '');
		} catch (Throwable) {
			return '';
		}
	}//end organisationName()

	/**
	 * The app the person is in, from the companion's snapshot.
	 *
	 * @param array<string, mixed> $appContext The snapshot.
	 *
	 * @return string
	 */
	private function appId(array $appContext): string {
		foreach (['appId', 'app'] as $key) {
			$value = ($appContext[$key] ?? null);
			if (is_string($value) === true && trim($value) !== '') {
				return trim($value);
			}
		}

		return '';
	}//end appId()
}//end class
