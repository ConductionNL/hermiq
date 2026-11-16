<?php

/**
 * Unit tests for ToolGrantWrites' access-request lookup and refused decisions.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\ToolOversight
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
 * @spec openspec/changes/archive/2026-11-12-tool-discovery-and-access-requests/specs/tool-discovery-and-access-requests/spec.md#requirement-only-the-agents-owner-must-be-able-to-grant-a-request
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\ToolOversight;

use OCA\Hermiq\Service\ToolAccessRequestService;
use OCA\Hermiq\Service\ToolOversight\ToolGrantWrites;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A request is only found for its own agent; a missing, foreign or unreadable
 * request looks the same, and a refused request writes no grant.
 *
 * @covers \OCA\Hermiq\Service\ToolOversight\ToolGrantWrites
 */
class ToolGrantWritesAccessRequestTest extends TestCase {

	/**
	 * Writes over an object service whose find() answers $found.
	 *
	 * @param ObjectEntity|RuntimeException|null $found What find() answers or throws.
	 * @param array<int, array<string, mixed>>  $finds Receives each find() call.
	 *
	 * @return ToolGrantWrites
	 */
	private function writes(ObjectEntity|RuntimeException|null $found, array &$finds = []): ToolGrantWrites {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturnCallback(
			static function (...$args) use ($found, &$finds) {
				$finds[] = $args;
				if ($found instanceof RuntimeException) {
					throw $found;
				}

				return $found;
			}
		);
		$objects->expects($this->never())->method('saveObject');

		return new ToolGrantWrites($objects, $this->createMock(ToolAccessRequestService::class));
	}//end writes()

	/**
	 * A stored request.
	 *
	 * @param string $agentId The agent it is for.
	 *
	 * @return ObjectEntity
	 */
	private function request(string $agentId): ObjectEntity {
		$request = new ObjectEntity();
		$request->setUuid('req-1');
		$request->setObject(['agentId' => $agentId, 'toolId' => 'mail.send', 'status' => 'pending']);
		return $request;
	}//end request()

	/**
	 * The agent's own request is answered with its data, looked up in the hermiq register.
	 *
	 * @return void
	 */
	public function testTheAgentsOwnRequestIsFound(): void {
		$finds = [];
		$data = $this->writes($this->request('agent-1'), $finds)->findAccessRequest('req-1', 'agent-1');

		$this->assertSame('agent-1', $data['agentId']);
		$this->assertSame('mail.send', $data['toolId']);
		$this->assertSame('req-1', $finds[0][0]);
		$this->assertInstanceOf(ObjectFindOptions::class, $finds[0][1]);
		$this->assertSame(['hermiq', 'toolAccessRequest'], [$finds[0][1]->register, $finds[0][1]->schema]);
	}//end testTheAgentsOwnRequestIsFound()

	/**
	 * Another agent's request, a missing one and an unreadable one all answer null.
	 *
	 * @return void
	 */
	public function testForeignMissingAndUnreadableRequestsLookTheSame(): void {
		$this->assertNull($this->writes($this->request('agent-2'))->findAccessRequest('req-1', 'agent-1'));
		$this->assertNull($this->writes(null)->findAccessRequest('req-1', 'agent-1'));
		$this->assertNull($this->writes(new RuntimeException('gone'))->findAccessRequest('req-1', 'agent-1'));
	}//end testForeignMissingAndUnreadableRequestsLookTheSame()

	/**
	 * A refused or undecided request adds nothing to the agent: saveObject is never called.
	 *
	 * @return void
	 */
	public function testARefusedRequestWritesNoGrant(): void {
		$agent = new ObjectEntity();
		$agent->setObject(['tools' => 'not-a-list']);
		$writes = $this->writes(null);

		$writes->grantRequestedTool($agent, ['agentId' => 'agent-1', 'toolId' => 'mail.send', 'status' => 'refused']);
		$writes->grantRequestedTool($agent, ['agentId' => 'agent-1', 'toolId' => 'mail.send']);
	}//end testARefusedRequestWritesNoGrant()
}//end class
