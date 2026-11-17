<?php

/**
 * Golden test for gate one, OutsideAgentGateway::assertGranted: which gate
 * refuses, in what order, with which exact message, and what passes.
 *
 * @category Tests
 * @package  OCA\Hermiq\Tests\Unit\Service\OutsideAgent
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

namespace OCA\Hermiq\Tests\Unit\Service\OutsideAgent;

use OCA\Hermiq\Service\OutsideAgent\OutsideAgentGateway;
use OCA\Hermiq\Service\OutsideAgent\OutsideCallRefusedException;
use OCA\Hermiq\Service\OutsideAgent\OutsideToolSurface;
use OCA\OpenRegister\Mcp\Service\Mcp\ToolRegistryFacade;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class OutsideAgentGatewayGrantTest extends TestCase {

	private OutsideAgentGateway $gateway;

	protected function setUp(): void {
		$facade = $this->createMock(ToolRegistryFacade::class);
		$facade->method('listTools')->willReturn(
			[
				['name' => 'app.case.get', 'annotations' => [OutsideToolSurface::OUTSIDE_ANNOTATION => true, 'readOnlyHint' => true]],
				['name' => 'app.update', 'annotations' => [OutsideToolSurface::OUTSIDE_ANNOTATION => true]],
				['name' => '42', 'annotations' => [OutsideToolSurface::OUTSIDE_ANNOTATION => true, 'readOnlyHint' => true]],
				['name' => 'app.hidden', 'annotations' => []],
			]
		);
		$this->gateway = new OutsideAgentGateway(
			objectService: $this->createMock(ObjectService::class),
			surface: new OutsideToolSurface($facade, new NullLogger()),
			toolRegistryFacade: $facade,
			logger: new NullLogger()
		);
	}//end setUp()

	/**
	 * Each refusal: [registration, tool, gate, reason].
	 *
	 * @return array<string, array{0: array<string, mixed>|null, 1: string, 2: string, 3: string}>
	 */
	public static function refusals(): array {
		return [
			'no registration' => [null, 'app.hidden', OutsideCallRefusedException::GATE_REGISTRATION, 'no outside-agent registration exists for this principal'],
			'switched off before surface' => [['enabled' => false, 'tools' => ['app.hidden']], 'app.hidden', OutsideCallRefusedException::GATE_REGISTRATION, 'the registration is switched off'],
			'undeclared even when named' => [['tools' => ['app.hidden']], 'app.hidden', OutsideCallRefusedException::GATE_SURFACE, 'no app declares this tool reachable from outside the instance'],
			'unknown tool' => [['tools' => []], 'app.nope', OutsideCallRefusedException::GATE_SURFACE, 'no app declares this tool reachable from outside the instance'],
			'ungranted write' => [['tools' => ['app.case.get']], 'app.update', OutsideCallRefusedException::GATE_GRANT, 'this tool writes, and a tool that writes is denied unless the registration names it'],
			'ungranted read' => [['tools' => ['app.update']], 'app.case.get', OutsideCallRefusedException::GATE_GRANT, 'the registration does not name this tool'],
			'empty list is not everything' => [['enabled' => true], 'app.case.get', OutsideCallRefusedException::GATE_GRANT, 'the registration does not name this tool'],
			'non-list tools is empty' => [['tools' => 'app.case.get'], 'app.case.get', OutsideCallRefusedException::GATE_GRANT, 'the registration does not name this tool'],
		];
	}//end refusals()

	/**
	 * @dataProvider refusals
	 */
	public function testEachGateRefusesWithItsExactSentence(?array $registration, string $toolId, string $gate, string $reason): void {
		try {
			$this->gateway->assertGranted(registration: $registration, toolId: $toolId, principal: 'bot');
			self::fail('Expected a refusal.');
		} catch (OutsideCallRefusedException $refusal) {
			self::assertSame($gate, $refusal->gate());
			self::assertSame($toolId, $refusal->toolId);
			self::assertSame('bot', $refusal->principal);
			self::assertSame(403, $refusal->getCode());
			self::assertSame(sprintf("Refused by the %s gate: '%s' for principal '%s': %s.", $gate, $toolId, 'bot', $reason), $refusal->getMessage());
		}
	}//end testEachGateRefusesWithItsExactSentence()

	/**
	 * Each pass: [registration, tool].
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function passes(): array {
		return [
			'granted read' => [['tools' => ['app.case.get']], 'app.case.get'],
			'granted write, enabled missing' => [['tools' => ['app.update', 'app.case.get']], 'app.update'],
			'enabled only refuses on false' => [['enabled' => 0, 'tools' => ['app.case.get']], 'app.case.get'],
			'numeric grant compared as text' => [['tools' => [42]], '42'],
		];
	}//end passes()

	/**
	 * @dataProvider passes
	 */
	public function testAGrantedDeclaredToolPasses(array $registration, string $toolId): void {
		$this->gateway->assertGranted(registration: $registration, toolId: $toolId, principal: 'bot');

		$this->addToAssertionCount(1);
	}//end testAGrantedDeclaredToolPasses()
}//end class
