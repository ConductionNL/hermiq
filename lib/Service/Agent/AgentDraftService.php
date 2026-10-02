<?php

/**
 * Hermiq AgentDraftService.
 *
 * Checks an agent draft the "Agent builder" wrote in chat before the full agent
 * form opens with it (agents-plain-language-builder). The draft is text: this
 * class reads it, keeps only the fields the form knows, and returns a finding
 * per field that will not work as written: a tool the catalogue does not have,
 * a model the organisation's policy does not allow, a group the person cannot
 * see, a schedule the scheduler cannot read. It never writes anything; the
 * agent exists only when the person saves the form.
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
 * @spec openspec/specs/agent-management-ui/spec.md#requirement-a-draft-opens-in-the-full-agent-form-after-a-check-req-agbuild-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Agent;

use Cron\CronExpression;
use OCA\Hermiq\Service\TenantModelPolicyService;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Service\Mcp\ToolRegistryFacade;
use OCP\IGroupManager;
use Throwable;

/**
 * Read and check an agent draft.
 *
 * @spec openspec/specs/agent-management-ui/spec.md#requirement-a-draft-opens-in-the-full-agent-form-after-a-check-req-agbuild-002
 */
class AgentDraftService {

	/**
	 * The schedule kinds the schedule form offers.
	 *
	 * @var array<int, string>
	 */
	private const SCHEDULE_KINDS = ['once', 'interval', 'cron'];

	/**
	 * Constructor.
	 *
	 * @param ToolRegistryFacade       $tools         The tool catalogue the agent form offers.
	 * @param TenantModelPolicyService $policies      The organisation's model policy.
	 * @param IGroupManager            $groups        Group existence and membership.
	 * @param OrganisationMapper       $organisations The person's active organisation.
	 */
	public function __construct(
		private readonly ToolRegistryFacade $tools,
		private readonly TenantModelPolicyService $policies,
		private readonly IGroupManager $groups,
		private readonly OrganisationMapper $organisations,
	) {
	}//end __construct()

	/**
	 * The draft as the form reads it, with a finding per field that will not work.
	 *
	 * @param string $text The draft block's text.
	 * @param string $uid  The person who will save the agent.
	 *
	 * @return array{draft: array<string, mixed>, findings: array<int, array{field: string, message: string, suggestion: string}>}
	 *
	 * @throws AgentDraftUnreadableException When the text is not a JSON object with a name.
	 *
	 * @spec openspec/specs/agent-management-ui/spec.md#requirement-a-draft-opens-in-the-full-agent-form-after-a-check-req-agbuild-002
	 */
	public function check(string $text, string $uid): array {
		$draft = $this->read(text: $text);

		$findings = array_merge(
			$this->toolFindings(tools: $draft['tools']),
			$this->modelFindings(provider: $draft['provider'], model: $draft['model'], uid: $uid),
			$this->groupFindings(groups: $draft['sharing']['groups'], uid: $uid),
			$this->scheduleFindings(schedule: $draft['schedule'])
		);

		return ['draft' => $draft, 'findings' => $findings];
	}//end check()

	/**
	 * The draft's known fields, normalised.
	 *
	 * @param string $text The draft block's text.
	 *
	 * @return array{name: string, description: string, prompt: string, provider: string, model: string, tools: array<int, string>, sharing: array{mode: string, groups: array<int, string>}, schedule: array<string, mixed>|null, startFields: array<int, mixed>}
	 *
	 * @throws AgentDraftUnreadableException When the text is not a JSON object with a name.
	 */
	private function read(string $text): array {
		$data = json_decode(trim($text), true);
		if (is_array($data) === false || array_is_list($data) === true || trim((string)($data['name'] ?? '')) === '') {
			throw new AgentDraftUnreadableException();
		}

		$sharing = (array)($data['sharing'] ?? []);
		$mode = (string)($sharing['mode'] ?? 'only-me');
		if (in_array($mode, ['only-me', 'groups', 'organisation'], true) === false) {
			$mode = 'only-me';
		}

		$schedule = null;
		if (is_array($data['schedule'] ?? null) === true && $data['schedule'] !== []) {
			$schedule = $data['schedule'];
		}

		return [
			'name' => trim((string)$data['name']),
			'description' => trim((string)($data['description'] ?? '')),
			'prompt' => (string)($data['prompt'] ?? ''),
			'provider' => trim((string)($data['provider'] ?? '')),
			'model' => trim((string)($data['model'] ?? '')),
			'tools' => $this->strings(values: ($data['tools'] ?? [])),
			'sharing' => ['mode' => $mode, 'groups' => $this->strings(values: ($sharing['groups'] ?? []))],
			'schedule' => $schedule,
			'startFields' => array_values((array)($data['startFields'] ?? [])),
		];
	}//end read()

	/**
	 * Tools the catalogue does not have, each with the closest one it does.
	 *
	 * @param array<int, string> $tools The proposed tool ids.
	 *
	 * @return array<int, array{field: string, message: string, suggestion: string}>
	 */
	private function toolFindings(array $tools): array {
		if ($tools === []) {
			return [];
		}

		$known = [];
		foreach ($this->tools->describeTools() as $tool) {
			$known[] = (string)($tool['name'] ?? '');
		}

		$findings = [];
		foreach ($tools as $tool) {
			if (in_array($tool, $known, true) === true) {
				continue;
			}

			$findings[] = [
				'field' => 'tools',
				'message' => 'No tool is called ' . $tool . '.',
				'suggestion' => $this->closest(wanted: $tool, candidates: $known),
			];
		}

		return $findings;
	}//end toolFindings()

	/**
	 * A provider or model the organisation's policy does not allow, with an allowed one.
	 *
	 * @param string $provider The proposed provider.
	 * @param string $model    The proposed model.
	 * @param string $uid      The person.
	 *
	 * @return array<int, array{field: string, message: string, suggestion: string}>
	 */
	private function modelFindings(string $provider, string $model, string $uid): array {
		if ($provider === '' && $model === '') {
			return [];
		}

		$organisation = (string)($this->organisations->getActiveOrganisationWithFallback($uid) ?? '');
		if ($this->policies->isAllowed(organisation: $organisation, provider: $provider, model: $model) === true) {
			return [];
		}

		return [
			[
				'field' => 'model',
				'message' => 'Not allowed by your organisation',
				'suggestion' => $this->allowedModel(organisation: $organisation, provider: $provider, model: $model),
			],
		];
	}//end modelFindings()

	/**
	 * The allowed model closest to the proposed one, as `provider/model`, or a provider alone.
	 *
	 * @param string $organisation The organisation.
	 * @param string $provider     The proposed provider.
	 * @param string $model        The proposed model.
	 *
	 * @return string
	 */
	private function allowedModel(string $organisation, string $provider, string $model): string {
		$policy = $this->policies->effectivePolicyFor(organisation: $organisation);

		$default = ($policy['defaultModel'] ?? null);
		if (is_array($default) === true && ($default['provider'] ?? '') !== '' && ($default['model'] ?? '') !== '') {
			return $default['provider'] . '/' . $default['model'];
		}

		$candidates = [];
		foreach ((array)($policy['allowed'] ?? []) as $entry) {
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

		return $this->closest(wanted: $provider . '/' . $model, candidates: $candidates);
	}//end allowedModel()

	/**
	 * Groups that do not exist or that the person is not in (an admin sees every group).
	 *
	 * @param array<int, string> $groups The proposed group ids.
	 * @param string             $uid    The person.
	 *
	 * @return array<int, array{field: string, message: string, suggestion: string}>
	 */
	private function groupFindings(array $groups, string $uid): array {
		$admin = $this->groups->isAdmin($uid);

		$findings = [];
		foreach ($groups as $group) {
			$visible = $this->groups->groupExists($group) === true
				&& ($admin === true || $this->groups->isInGroup($uid, $group) === true);
			if ($visible === false) {
				$findings[] = ['field' => 'groups', 'message' => 'You cannot share with the group ' . $group . '.', 'suggestion' => ''];
			}
		}

		return $findings;
	}//end groupFindings()

	/**
	 * A schedule the scheduler cannot read.
	 *
	 * @param array<string, mixed>|null $schedule The proposed schedule.
	 *
	 * @return array<int, array{field: string, message: string, suggestion: string}>
	 */
	private function scheduleFindings(?array $schedule): array {
		if ($schedule === null) {
			return [];
		}

		$kind = (string)($schedule['kind'] ?? '');
		if (in_array($kind, self::SCHEDULE_KINDS, true) === false) {
			return [['field' => 'schedule', 'message' => 'The schedule kind must be once, interval or cron.', 'suggestion' => '']];
		}

		if ($kind === 'cron' && $this->validCron(expression: (string)($schedule['cronExpr'] ?? '')) === false) {
			return [['field' => 'schedule', 'message' => 'The schedule could not be read.', 'suggestion' => '0 8 * * 1']];
		}

		if ($kind === 'interval' && (int)($schedule['intervalMinutes'] ?? 0) < 1) {
			return [['field' => 'schedule', 'message' => 'The interval must be at least one minute.', 'suggestion' => '']];
		}

		return [];
	}//end scheduleFindings()

	/**
	 * Whether the scheduler can read a cron expression.
	 *
	 * @param string $expression The expression.
	 *
	 * @return bool
	 */
	private function validCron(string $expression): bool {
		if (trim($expression) === '') {
			return false;
		}

		try {
			new CronExpression($expression);
			return true;
		} catch (Throwable) {
			return false;
		}
	}//end validCron()

	/**
	 * The candidate closest to the wanted text, or '' when there is none.
	 *
	 * @param string             $wanted     The text.
	 * @param array<int, string> $candidates The candidates.
	 *
	 * @return string
	 */
	private function closest(string $wanted, array $candidates): string {
		$best = '';
		$bestDistance = PHP_INT_MAX;
		foreach ($candidates as $candidate) {
			$distance = levenshtein(strtolower($wanted), strtolower($candidate));
			if ($candidate !== '' && $distance < $bestDistance) {
				$best = $candidate;
				$bestDistance = $distance;
			}
		}

		return $best;
	}//end closest()

	/**
	 * A list of non-empty strings.
	 *
	 * @param mixed $values The values.
	 *
	 * @return array<int, string>
	 */
	private function strings(mixed $values): array {
		$strings = [];
		foreach ((array)$values as $value) {
			if (is_scalar($value) === true && trim((string)$value) !== '') {
				$strings[] = trim((string)$value);
			}
		}

		return array_values(array_unique($strings));
	}//end strings()
}//end class
