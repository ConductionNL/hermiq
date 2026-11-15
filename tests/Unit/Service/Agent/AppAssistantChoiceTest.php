<?php

/**
 * Unit tests for AppAssistantChoice: which app an agent serves and whether it answers there.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Agent
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
 * @spec openspec/specs/agent-management-ui/spec.md#requirement-an-organisation-admin-picks-the-agent-that-answers-in-an-app-req-appag-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Agent;

use OCA\Hermiq\Service\Agent\AppAssistantChoice;
use PHPUnit\Framework\TestCase;

/**
 * The assistant choice holds only while it names the app the agent serves.
 *
 * @covers \OCA\Hermiq\Service\Agent\AppAssistantChoice
 */
class AppAssistantChoiceTest extends TestCase {

	/**
	 * The app is read trimmed and in lower case; an absent app is ''.
	 *
	 * @return void
	 */
	public function testAppOfIsTrimmedAndLowerCase(): void {
		$choice = new AppAssistantChoice();

		$this->assertSame('pipelinq', $choice->appOf(data: ['applicationSlug' => '  PipelinQ ']));
		$this->assertSame('', $choice->appOf(data: []));
		$this->assertSame('', $choice->appOf(data: ['applicationSlug' => null]));

	}//end testAppOfIsTrimmedAndLowerCase()

	/**
	 * An agent answers in the app it serves when it was chosen for that app,
	 * however either value is cased or padded.
	 *
	 * @return void
	 */
	public function testAnswersInTheAppItServesAndWasChosenFor(): void {
		$choice = new AppAssistantChoice();
		$data = ['applicationSlug' => 'Pipelinq', 'appAssistantFor' => ' PIPELINQ '];

		$this->assertTrue($choice->answersIn(data: $data, app: 'pipelinq'));
		$this->assertFalse($choice->answersIn(data: $data, app: 'decidiq'), 'Not in another app.');

	}//end testAnswersInTheAppItServesAndWasChosenFor()

	/**
	 * Moving the agent to another app ends the choice; no choice, no answer.
	 *
	 * @return void
	 */
	public function testAChoiceForAnotherAppDoesNotHold(): void {
		$choice = new AppAssistantChoice();

		$this->assertFalse(
			$choice->answersIn(data: ['applicationSlug' => 'decidiq', 'appAssistantFor' => 'pipelinq'], app: 'pipelinq'),
			'The agent now serves decidiq, so its pipelinq choice no longer holds.'
		);
		$this->assertFalse($choice->answersIn(data: ['applicationSlug' => 'pipelinq'], app: 'pipelinq'));

	}//end testAChoiceForAnotherAppDoesNotHold()

	/**
	 * An empty app never matches, even when both stored fields are empty too.
	 *
	 * @return void
	 */
	public function testAnEmptyAppNeverMatches(): void {
		$this->assertFalse((new AppAssistantChoice())->answersIn(data: ['applicationSlug' => '', 'appAssistantFor' => ''], app: ''));
		$this->assertFalse((new AppAssistantChoice())->answersIn(data: [], app: ''));

	}//end testAnEmptyAppNeverMatches()
}//end class
