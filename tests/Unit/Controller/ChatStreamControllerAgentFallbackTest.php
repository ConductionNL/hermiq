<?php

/**
 * Unit tests for ChatStreamController's fallback agent choice.
 *
 * Measured 2026-09-18 in the browser: the floating chat inside Buildiq opened as
 * "Hydra Triage", and the turn came back
 * `{"code":"tool_grants_unresolved", ... hydra.change.*, hydra.cycle.* ...}`.
 * The agent was not wrong for the user, it was wrong for the PAGE: it simply
 * came first in the register.
 *
 * The panel already sends the app it is sitting in, as `context.appId`, so the
 * choice can be made on evidence rather than on order.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\ChatStreamController;
use OCA\Hermiq\Service\Engine\RunStepBus;
use OCA\Hermiq\Service\ToolAccessRequestService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Hermiq\Service\Engine\Engine;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * The fallback prefers the app's own agent, and still answers when none matches.
 */
final class ChatStreamControllerAgentFallbackTest extends TestCase {

	/**
	 * Build an agent row.
	 *
	 * @param string $uuid The agent uuid.
	 * @param string $slug Its applicationSlug ('' for none).
	 * @param bool $isPrivate Whether the agent is private.
	 *
	 * @return ObjectEntity
	 */
	private function agent(string $uuid, string $slug, bool $isPrivate = false): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getUuid')->willReturn($uuid);
		$entity->method('getOwner')->willReturn('someone-else');
		$payload = ['isPrivate' => $isPrivate];
		if ($slug !== '') {
			$payload['applicationSlug'] = $slug;
		}

		$entity->method('getObject')->willReturn($payload);
		return $entity;
	}//end agent()

	/**
	 * Call the private picker with a fixed agent list.
	 *
	 * @param array<int, ObjectEntity> $agents The register page.
	 * @param string $applicationSlug The app the caller sits in.
	 *
	 * @return string The chosen uuid.
	 */
	private function pick(array $agents, string $applicationSlug): string {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnSelf();
		$objectService->method('findAll')->willReturn($agents);

		$controller = new ChatStreamController(
			$this->createMock(IRequest::class),
			$this->createMock(Engine::class),
			$objectService,
			$this->createMock(IUserSession::class),
			$this->createMock(IDBConnection::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(IL10N::class),
			$this->createMock(RunStepBus::class),
			$this->createMock(ToolAccessRequestService::class)
		);

		$method = new ReflectionMethod(ChatStreamController::class, 'pickFallbackAgentForUser');
		$method->setAccessible(true);
		return (string)$method->invoke($controller, 'alice', $applicationSlug);

	}//end pick()

	/**
	 * The app's own agent wins over one that merely comes first.
	 *
	 * @return void
	 */
	public function testTheAppsOwnAgentWinsOverTheFirstInTheRegister(): void {
		$chosen = $this->pick(
			[
				$this->agent('hydra-uuid', 'hydra-console'),
				$this->agent('buildiq-uuid', 'buildiq'),
			],
			'buildiq'
		);

		$this->assertSame('buildiq-uuid', $chosen, 'the chat opened in Buildiq must not answer as a Hydra agent');

	}//end testTheAppsOwnAgentWinsOverTheFirstInTheRegister()

	/**
	 * Matching is case-insensitive on both sides.
	 *
	 * @return void
	 */
	public function testMatchingIsCaseInsensitive(): void {
		$chosen = $this->pick(
			[
				$this->agent('hydra-uuid', 'hydra-console'),
				$this->agent('buildiq-uuid', 'BuildIQ'),
			],
			'buildiq'
		);

		$this->assertSame('buildiq-uuid', $chosen);

	}//end testMatchingIsCaseInsensitive()

	/**
	 * With no match, the first ACCESSIBLE agent still answers. A wrong-app agent
	 * beats an empty chat window.
	 *
	 * @return void
	 */
	public function testWithNoMatchTheFirstAccessibleAgentStillAnswers(): void {
		$chosen = $this->pick(
			[
				$this->agent('hydra-uuid', 'hydra-console'),
				$this->agent('other-uuid', 'dossiq'),
			],
			'buildiq'
		);

		$this->assertSame('hydra-uuid', $chosen);

	}//end testWithNoMatchTheFirstAccessibleAgentStillAnswers()

	/**
	 * An INACCESSIBLE agent is never chosen, even when its slug matches. The app
	 * preference reorders the accessible set; it does not widen it.
	 *
	 * @return void
	 */
	public function testAnInaccessibleAgentIsNeverChosenEvenWhenItsSlugMatches(): void {
		$chosen = $this->pick(
			[
				$this->agent('hydra-uuid', 'hydra-console'),
				$this->agent('private-buildiq-uuid', 'buildiq', true),
			],
			'buildiq'
		);

		$this->assertSame('hydra-uuid', $chosen, 'access is decided before the app preference, never by it');

	}//end testAnInaccessibleAgentIsNeverChosenEvenWhenItsSlugMatches()

	/**
	 * With no app context the behaviour is exactly what it was: the first
	 * accessible agent.
	 *
	 * @return void
	 */
	public function testWithNoAppContextTheFirstAccessibleAgentIsUnchanged(): void {
		$chosen = $this->pick(
			[
				$this->agent('hydra-uuid', 'hydra-console'),
				$this->agent('buildiq-uuid', 'buildiq'),
			],
			''
		);

		$this->assertSame('hydra-uuid', $chosen);

	}//end testWithNoAppContextTheFirstAccessibleAgentIsUnchanged()

}//end class
