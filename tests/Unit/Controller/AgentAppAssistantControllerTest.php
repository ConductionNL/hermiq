<?php

/**
 * POST /api/agents/{id}/app-assistant keeps the service's refusals as their HTTP
 * status (agents-bound-to-their-app, REQ-APPAG-002).
 *
 * @category Tests
 * @package  OCA\Hermiq\Tests\Unit\Controller
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

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\AgentAppAssistantController;
use OCA\Hermiq\Service\Agent\AppAssistantService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AgentAppAssistantControllerTest extends TestCase {

	private function controller(AppAssistantService $service, mixed $flag): AgentAppAssistantController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(fn (string $key) => ($key === 'appAssistant' ? $flag : null));
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('carol');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new AgentAppAssistantController(request: $request, assistants: $service, userSession: $session);
	}//end controller()

	public function testMarksTheAgentAsTheCaller(): void {
		$agent = new ObjectEntity();
		$agent->setObject(['applicationSlug' => 'subsidies', 'appAssistantFor' => 'subsidies']);
		$service = $this->createMock(AppAssistantService::class);
		$service->expects($this->once())->method('mark')->with('a1', true, 'carol')->willReturn($agent);

		$response = $this->controller(service: $service, flag: 'true')->update(id: 'a1');

		self::assertSame(200, $response->getStatus());
		self::assertSame(['appAssistant' => true, 'applicationSlug' => 'subsidies'], $response->getData());
	}//end testMarksTheAgentAsTheCaller()

	public function testRefusalsKeepTheirStatus(): void {
		foreach ([403, 404, 409, 422, 500] as $code) {
			$service = $this->createMock(AppAssistantService::class);
			$service->method('mark')->willThrowException(new RuntimeException('no', $code));

			self::assertSame($code, $this->controller(service: $service, flag: true)->update(id: 'a1')->getStatus());
		}

		$service = $this->createMock(AppAssistantService::class);
		$service->method('mark')->willThrowException(new RuntimeException('odd', 7));
		self::assertSame(500, $this->controller(service: $service, flag: true)->update(id: 'a1')->getStatus());
	}//end testRefusalsKeepTheirStatus()
}//end class
