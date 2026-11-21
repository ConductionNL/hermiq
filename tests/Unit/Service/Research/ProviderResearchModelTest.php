<?php

/**
 * Unit tests for ProviderResearchModel and ResearchObjects (tools-deep-research-report).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Research
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-user-can-start-deep-research-and-sees-the-plan-req-research-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Research;

use OCA\Hermiq\Kernel\Contracts\ProviderFactoryInterface;
use OCA\Hermiq\Service\Research\ProviderResearchModel;
use OCA\Hermiq\Service\Research\ResearchObjects;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Hermiq\Service\Research\ProviderResearchModel
 * @covers \OCA\Hermiq\Service\Research\ResearchObjects
 */
final class ProviderResearchModelTest extends TestCase {

	/**
	 * A generation is checked against the organisation's model policy.
	 *
	 * @return void
	 */
	public function testAGenerationIsCheckedAgainstTheOrganisationsModelPolicy(): void {
		$providers = $this->createMock(originalClassName: ProviderFactoryInterface::class);
		$providers->expects(self::once())->method('generateText')
			->with('the prompt', 'anne', true, 'org-1')
			->willReturn('the answer');

		$answer = (new ProviderResearchModel(providers: $providers))->generate(prompt: 'the prompt', userId: 'anne', organisation: 'org-1');

		self::assertSame(expected: 'the answer', actual: $answer);
	}//end testAGenerationIsCheckedAgainstTheOrganisationsModelPolicy()

	/**
	 * An object is read in hermiq's register; a missing or unreadable one is null.
	 *
	 * @return void
	 */
	public function testAnObjectIsReadAsThePersonAndAnUnreadableOneIsNull(): void {
		$agent = new ObjectEntity();
		$agent->setObject(['prompt' => '  Schrijf zakelijk.  ']);
		$objectService = $this->createMock(originalClassName: ObjectService::class);
		$objectService->method('find')->willReturnCallback(
			fn (string $id, ObjectFindOptions $options): mixed => $this->found(id: $id, options: $options, agent: $agent)
		);
		$objects = new ResearchObjects(objectService: $objectService);

		self::assertSame(expected: $agent, actual: $objects->readable(uuid: 'agent-1', schema: ResearchObjects::AGENT));
		self::assertNull(actual: $objects->readable(uuid: 'denied', schema: ResearchObjects::AGENT));
		self::assertNull(actual: $objects->readable(uuid: 'odd', schema: ResearchObjects::AGENT));
		self::assertNull(actual: $objects->readable(uuid: '', schema: ResearchObjects::AGENT));
		self::assertSame(expected: 'Schrijf zakelijk.', actual: $objects->agentPrompt(agentId: 'agent-1'));
		self::assertSame(expected: '', actual: $objects->agentPrompt(agentId: 'denied'));
	}//end testAnObjectIsReadAsThePersonAndAnUnreadableOneIsNull()

	/**
	 * What the object service double finds.
	 *
	 * @param string            $id      The id.
	 * @param ObjectFindOptions $options The options.
	 * @param ObjectEntity      $agent   The agent.
	 *
	 * @return mixed
	 *
	 * @throws RuntimeException For an object the person may not read.
	 */
	private function found(string $id, ObjectFindOptions $options, ObjectEntity $agent): mixed {
		self::assertSame(expected: 'hermiq', actual: $options->register);
		if ($id === 'denied') {
			throw new RuntimeException('Not readable');
		}

		if ($id === 'odd') {
			return ['not' => 'an entity'];
		}

		return $agent;
	}//end found()
}//end class
