<?php

/**
 * An organisation admin marks the agent that answers in an app, one per app per
 * organisation (agents-bound-to-their-app, REQ-APPAG-002). The saved agent is
 * validated against the real register Agent fragment with Opis.
 *
 * @category Tests
 * @package  OCA\Hermiq\Tests\Unit\Service\Agent
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

namespace OCA\Hermiq\Tests\Unit\Service\Agent;

use OCA\Hermiq\Service\Agent\AgentAvailabilityService;
use OCA\Hermiq\Service\Agent\AppAssistantService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AppAssistantServiceTest extends TestCase {

	private const AGENT = '5f0c6a3e-1d2b-4c8e-9a7f-3b2d1e0f4a51';

	/**
	 * Payloads saved.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	private function agent(string $uuid, string $organisation, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setOwner('bob');
		$entity->setOrganisation($organisation);
		$entity->setObject($data);
		return $entity;
	}//end agent()

	/**
	 * @param array<int, ObjectEntity> $others The other agents in the register.
	 */
	private function service(?ObjectEntity $target, bool $admin, array $others = []): AppAssistantService {
		$availability = $this->createMock(AgentAvailabilityService::class);
		$availability->method('readableAgent')->willReturn($target);
		$availability->method('mayAdministerOrganisation')->willReturn($admin);

		$objects = $this->createMock(ObjectService::class);
		$objects->method('setRegister')->willReturnSelf();
		$objects->method('setSchema')->willReturnSelf();
		$all = $others;
		if ($target !== null) {
			$all[] = $target;
		}

		$objects->method('findAll')->willReturnCallback(fn (array $config = []): array => array_slice($all, (int)($config['offset'] ?? 0), (int)($config['limit'] ?? 100)));
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ...$rest): ObjectEntity {
				$this->saved[] = $object;
				return $this->agent(self::AGENT, 'org-1', $object);
			}
		);

		return new AppAssistantService(objectService: $objects, availability: $availability);
	}//end service()

	private function target(array $extra = []): ObjectEntity {
		return $this->agent(self::AGENT, 'org-1', array_merge(['name' => 'Subsidy desk helper', 'applicationSlug' => 'subsidies'], $extra));
	}//end target()

	public function testAnOrganisationAdminMarksTheAppsAssistant(): void {
		$stored = $this->service(target: $this->target(), admin: true)->mark(agentId: self::AGENT, assistant: true, uid: 'carol');

		self::assertTrue($stored->getObject()['appAssistant']);
		self::assertCount(1, $this->saved);
		self::assertTrue($this->validAgent($this->saved[0]), 'The saved agent must be valid against the register Agent schema.');
		$bad = $this->saved[0];
		$bad['appAssistant'] = 'yes';
		self::assertFalse($this->validAgent($bad), 'negative control: the validator refuses a non-boolean flag');
	}//end testAnOrganisationAdminMarksTheAppsAssistant()

	public function testASecondAssistantForTheSameAppIsRefusedWith409(): void {
		$taken = $this->agent('other', 'org-1', ['applicationSlug' => 'Subsidies', 'appAssistant' => true]);

		try {
			$this->service(target: $this->target(), admin: true, others: [$taken])->mark(agentId: self::AGENT, assistant: true, uid: 'carol');
			self::fail('expected a refusal');
		} catch (RuntimeException $e) {
			self::assertSame(409, $e->getCode());
			self::assertSame('Another agent already answers in this app', $e->getMessage());
		}

		self::assertSame([], $this->saved);
	}//end testASecondAssistantForTheSameAppIsRefusedWith409()

	public function testAnAssistantInAnotherOrganisationDoesNotBlock(): void {
		$elsewhere = $this->agent('other', 'org-2', ['applicationSlug' => 'subsidies', 'appAssistant' => true]);

		$this->service(target: $this->target(), admin: true, others: [$elsewhere])->mark(agentId: self::AGENT, assistant: true, uid: 'carol');

		self::assertCount(1, $this->saved);
	}//end testAnAssistantInAnotherOrganisationDoesNotBlock()

	public function testUnmarkingIsNeverBlocked(): void {
		$taken = $this->agent('other', 'org-1', ['applicationSlug' => 'subsidies', 'appAssistant' => true]);

		$this->service(target: $this->target(['appAssistant' => true]), admin: true, others: [$taken])->mark(agentId: self::AGENT, assistant: false, uid: 'carol');

		self::assertFalse($this->saved[0]['appAssistant']);
	}//end testUnmarkingIsNeverBlocked()

	public function testTheOwnerWhoIsNoAdminIsRefusedWith403(): void {
		$this->expectExceptionCode(403);
		$this->service(target: $this->target(), admin: false)->mark(agentId: self::AGENT, assistant: true, uid: 'bob');
	}//end testTheOwnerWhoIsNoAdminIsRefusedWith403()

	public function testAnAgentTheCallerCannotReadIs404(): void {
		$this->expectExceptionCode(404);
		$this->service(target: null, admin: true)->mark(agentId: self::AGENT, assistant: true, uid: 'carol');
	}//end testAnAgentTheCallerCannotReadIs404()

	public function testAnAgentWithoutAnAppCannotBeAnAppsAssistant(): void {
		$this->expectExceptionCode(422);
		$this->service(target: $this->target(['applicationSlug' => '']), admin: true)->mark(agentId: self::AGENT, assistant: true, uid: 'carol');
	}//end testAnAgentWithoutAnAppCannotBeAnAppsAssistant()

	public function testTheRegisterLetsOnlyAnAdminWriteTheFlagThroughTheObjectApi(): void {
		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../../lib/Settings/hermiq_register.json'), true);
		$property = $register['components']['schemas']['Agent']['properties']['appAssistant'];

		self::assertSame('boolean', $property['type']);
		self::assertSame([['group' => 'admin']], $property['authorization']['update']);
	}//end testTheRegisterLetsOnlyAnAdminWriteTheFlagThroughTheObjectApi()

	private function validAgent(array $payload): bool {
		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../../lib/Settings/hermiq_register.json'));
		$schema = $register->components->schemas->Agent;
		$this->stripSlugRefs(node: $schema);
		return (new Validator())->validate(json_decode((string)json_encode($payload)), $schema)->isValid();
	}//end validAgent()

	private function stripSlugRefs(mixed $node): void {
		if (is_object($node) === false && is_array($node) === false) {
			return;
		}

		foreach ($node as $key => $child) {
			if ($key === '$ref' && is_string($child) === true && str_starts_with($child, '#') === false) {
				unset($node->{'$ref'});
				continue;
			}

			$this->stripSlugRefs(node: $child);
		}
	}//end stripSlugRefs()
}//end class
