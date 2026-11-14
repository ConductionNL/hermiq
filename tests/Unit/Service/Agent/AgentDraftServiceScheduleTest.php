<?php

/**
 * AgentDraftService::check() findings for schedules and an empty draft.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Agent
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Agent;

use OCA\Hermiq\Service\Agent\AgentDraftService;
use OCA\Hermiq\Service\EffectiveModelPolicy;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Mcp\Service\Mcp\ToolRegistryFacade;
use OCP\IGroupManager;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Pins the schedule findings and that a draft naming no tools and no model
 * asks neither the tool catalogue nor the model policy.
 *
 * @covers \OCA\Hermiq\Service\Agent\AgentDraftService
 * @uses   \OCA\Hermiq\Service\Agent\DraftModel
 */
class AgentDraftServiceScheduleTest extends TestCase {

	/**
	 * The service; the catalogue and policy must not be asked unless a test allows it.
	 *
	 * @param bool $asksCollaborators Whether the tool catalogue and model policy may be asked.
	 *
	 * @return AgentDraftService
	 */
	private function service(bool $asksCollaborators = true): AgentDraftService {
		$tools = $this->createMock(ToolRegistryFacade::class);
		$policies = $this->createMock(EffectiveModelPolicy::class);
		if ($asksCollaborators === false) {
			$tools->expects($this->never())->method('describeTools');
			$policies->expects($this->never())->method('isAllowed');
		} else {
			$tools->method('describeTools')->willReturn([]);
		}

		$groups = $this->createMock(IGroupManager::class);
		$organisations = $this->createMock(OrganisationMapper::class);
		$organisations->method('getActiveOrganisationWithFallback')->willReturn('org-1');

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));

		return new AgentDraftService(tools: $tools, policies: $policies, groups: $groups, organisations: $organisations, l10n: $l10n);
	}//end service()

	/**
	 * The findings for a draft with only a name and the given schedule.
	 *
	 * @param mixed $schedule The schedule value.
	 *
	 * @return array<int, array{field: string, message: string, suggestion: string}>
	 */
	private function findings(mixed $schedule): array {
		$text = (string)json_encode(['name' => 'Digest', 'schedule' => $schedule]);

		return $this->service(asksCollaborators: false)->check(text: $text, uid: 'alice')['findings'];
	}//end findings()

	/**
	 * A draft with no tools, no model and no schedule has no findings and asks nobody.
	 *
	 * @return void
	 */
	public function testABareDraftHasNoFindings(): void {
		$result = $this->service(asksCollaborators: false)->check(text: '{"name":" Digest "}', uid: 'alice');

		$this->assertSame([], $result['findings']);
		$this->assertSame('Digest', $result['draft']['name']);
		$this->assertNull($result['draft']['schedule']);
	}//end testABareDraftHasNoFindings()

	/**
	 * An unknown schedule kind is named.
	 *
	 * @return void
	 */
	public function testAnUnknownScheduleKindIsAFinding(): void {
		$this->assertSame(
			[['field' => 'schedule', 'message' => 'The schedule kind must be once, interval or cron.', 'suggestion' => '']],
			$this->findings(schedule: ['kind' => 'weekly'])
		);
	}//end testAnUnknownScheduleKindIsAFinding()

	/**
	 * A cron schedule with no expression cannot be read, and a working one is suggested.
	 *
	 * @return void
	 */
	public function testACronWithoutAnExpressionIsAFinding(): void {
		$expected = [['field' => 'schedule', 'message' => 'The schedule could not be read.', 'suggestion' => '0 8 * * 1']];

		$this->assertSame($expected, $this->findings(schedule: ['kind' => 'cron']));
		$this->assertSame($expected, $this->findings(schedule: ['kind' => 'cron', 'cronExpr' => '   ']));
	}//end testACronWithoutAnExpressionIsAFinding()

	/**
	 * An interval below one minute is a finding; one minute or more is not.
	 *
	 * @return void
	 */
	public function testAnIntervalMustBeAtLeastOneMinute(): void {
		$expected = [['field' => 'schedule', 'message' => 'The interval must be at least one minute.', 'suggestion' => '']];

		$this->assertSame($expected, $this->findings(schedule: ['kind' => 'interval']));
		$this->assertSame($expected, $this->findings(schedule: ['kind' => 'interval', 'intervalMinutes' => 0]));
		$this->assertSame([], $this->findings(schedule: ['kind' => 'interval', 'intervalMinutes' => 1]));
	}//end testAnIntervalMustBeAtLeastOneMinute()

	/**
	 * A once schedule needs nothing more.
	 *
	 * @return void
	 */
	public function testAOnceScheduleIsAccepted(): void {
		$this->assertSame([], $this->findings(schedule: ['kind' => 'once']));
	}//end testAOnceScheduleIsAccepted()
}//end class
