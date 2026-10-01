<?php

/**
 * The agent that answers in an app when a chat names none (agents-bound-to-their-app,
 * REQ-APPAG-002), on the real AgentAccessService: the app's assistant, else an
 * accessible agent of that app, else the first accessible agent.
 *
 * @category Tests
 * @package  OCA\Hermiq\Tests\Unit\Service
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\AgentAccessService;
use OCA\Hermiq\Service\AppAssistantResolver;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class AppAssistantResolverTest extends TestCase {

	/**
	 * The offsets the resolver asked for.
	 *
	 * @var array<int, int>
	 */
	private array $offsets = [];

	private function agent(string $uuid, string $slug = '', bool $assistant = false, bool $isPrivate = false, bool $active = true): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setOwner('someone-else');
		$data = ['isPrivate' => $isPrivate, 'active' => $active];
		if ($slug !== '') {
			$data['applicationSlug'] = $slug;
		}

		if ($assistant === true) {
			$data['appAssistantFor'] = strtolower($slug);
		}

		$entity->setObject($data);
		return $entity;
	}//end agent()

	/**
	 * @param array<int, ObjectEntity> $agents The register, in order.
	 */
	private function resolve(array $agents, string $appId, int $pageSize = 100): string {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('setRegister')->willReturnSelf();
		$objects->method('setSchema')->willReturnSelf();
		$objects->method('findAll')->willReturnCallback(
			function (array $config = []) use ($agents): array {
				$offset = (int)($config['offset'] ?? 0);
				$this->offsets[] = $offset;
				return array_slice($agents, $offset, (int)$config['limit']);
			}
		);

		$resolver = new AppAssistantResolver(
			objectService: $objects,
			agentAccess: new AgentAccessService($objects, $this->createMock(LoggerInterface::class), $this->createMock(IGroupManager::class)),
			logger: $this->createMock(LoggerInterface::class),
			pageSize: $pageSize
		);

		return $resolver->resolve(userId: 'alice', appId: $appId);
	}//end resolve()

	public function testTheAppsAssistantWinsOverAnEarlierAgentOfTheSameApp(): void {
		$chosen = $this->resolve(
			[
				$this->agent('first', 'hydra-console'),
				$this->agent('plain', 'subsidies'),
				$this->agent('assistant', 'subsidies', assistant: true),
			],
			'subsidies'
		);

		self::assertSame('assistant', $chosen);
	}//end testTheAppsAssistantWinsOverAnEarlierAgentOfTheSameApp()

	public function testAnAssistantOfAnotherAppDoesNotCount(): void {
		$chosen = $this->resolve(
			[
				$this->agent('other-assistant', 'pipelinq', assistant: true),
				$this->agent('plain', 'subsidies'),
			],
			'subsidies'
		);

		self::assertSame('plain', $chosen);
	}//end testAnAssistantOfAnotherAppDoesNotCount()

	public function testAnAssistantTheUserMayNotUseFallsBackToAnAgentOfTheApp(): void {
		$chosen = $this->resolve(
			[
				$this->agent('private-assistant', 'subsidies', assistant: true, isPrivate: true),
				$this->agent('plain', 'Subsidies'),
			],
			'subsidies'
		);

		self::assertSame('plain', $chosen, 'access is decided before the app preference, and slugs match case-insensitively');
	}//end testAnAssistantTheUserMayNotUseFallsBackToAnAgentOfTheApp()

	public function testAnAssistantMovedToAnotherAppNoLongerAnswersThere(): void {
		$moved = $this->agent('moved', 'pipelinq');
		$moved->setObject(array_merge($moved->getObject(), ['appAssistantFor' => 'subsidies']));

		$chosen = $this->resolve([$moved, $this->agent('plain', 'subsidies')], 'subsidies');

		self::assertSame('plain', $chosen, 'the choice holds only while the agent serves the app it was chosen for');
	}//end testAnAssistantMovedToAnotherAppNoLongerAnswersThere()

	public function testASwitchedOffAssistantIsPassedOver(): void {
		$chosen = $this->resolve(
			[
				$this->agent('off-assistant', 'subsidies', assistant: true, active: false),
				$this->agent('plain', 'subsidies'),
			],
			'subsidies'
		);

		self::assertSame('plain', $chosen);
	}//end testASwitchedOffAssistantIsPassedOver()

	public function testWithNoAgentOfTheAppTheFirstAccessibleAgentAnswers(): void {
		$chosen = $this->resolve(
			[
				$this->agent('private', 'hydra-console', isPrivate: true),
				$this->agent('first-accessible', 'hydra-console'),
				$this->agent('other', 'dossiq'),
			],
			'subsidies'
		);

		self::assertSame('first-accessible', $chosen);
	}//end testWithNoAgentOfTheAppTheFirstAccessibleAgentAnswers()

	public function testWithNoAppTheFirstAccessibleAgentAnswers(): void {
		$chosen = $this->resolve(
			[
				$this->agent('first', 'hydra-console'),
				$this->agent('assistant', 'subsidies', assistant: true),
			],
			''
		);

		self::assertSame('first', $chosen);
	}//end testWithNoAppTheFirstAccessibleAgentAnswers()

	public function testTheAssistantIsFoundPastTheFirstPage(): void {
		$agents = [];
		for ($i = 0; $i < 5; $i++) {
			$agents[] = $this->agent('a' . $i, 'hydra-console');
		}

		$agents[] = $this->agent('late-assistant', 'subsidies', assistant: true);

		self::assertSame('late-assistant', $this->resolve($agents, 'subsidies', pageSize: 2));
		self::assertSame([0, 2, 4], $this->offsets);
	}//end testTheAssistantIsFoundPastTheFirstPage()

	public function testNoAccessibleAgentIsAnEmptyAnswer(): void {
		self::assertSame('', $this->resolve([$this->agent('private', 'subsidies', isPrivate: true)], 'subsidies'));
	}//end testNoAccessibleAgentIsAnEmptyAnswer()
}//end class
