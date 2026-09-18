<?php

/**
 * Hermiq OutsideAgentGateway unit tests.
 *
 * Covers the two gates and the narrowing-only filter: a write tool denied unless
 * granted, a granted agent still refused by the owning app, a permitted caller still
 * refused by the grant, and a response narrowed without a single value renamed.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\OutsideAgent
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
 * @spec openspec/changes/the-declared-tool-surface-and-the-prompt-library/specs/agent-tool-governance/spec.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\OutsideAgent;

use OCA\Hermiq\Service\OutsideAgent\OutsideAgentGateway;
use OCA\Hermiq\Service\OutsideAgent\OutsideCallRefusedException;
use OCA\Hermiq\Service\OutsideAgent\OutsideToolSurface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Mcp\ToolRegistryFacade;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The two gates in front of a call from outside.
 *
 * @spec openspec/changes/the-declared-tool-surface-and-the-prompt-library/specs/agent-tool-governance/spec.md#requirement-both-gates-must-open-before-a-tool-runs
 */
class OutsideAgentGatewayTest extends TestCase {

	/**
	 * A tool catalogue in the shape the facade returns, with the owning app's own
	 * outside-agent annotation on the declared ones.
	 *
	 * @return array<int, array<string, mixed>> The catalogue.
	 */
	private function catalog(): array {
		return [
			[
				'name' => 'dossiq.case.get',
				'description' => 'Read one case',
				'annotations' => [OutsideToolSurface::OUTSIDE_ANNOTATION => true, 'readOnlyHint' => true],
			],
			[
				'name' => 'dossiq.case.update',
				'description' => 'Change one case',
				'annotations' => [OutsideToolSurface::OUTSIDE_ANNOTATION => true],
			],
			[
				// Declared by nobody as reachable from outside: present in the
				// catalogue, absent from the surface.
				'name' => 'dossiq.case.delete',
				'description' => 'Destroy one case',
				'annotations' => [],
			],
			[
				// hermiq's own tool, which this surface never offers in either
				// direction.
				'name' => 'hermiq.webFetch',
				'description' => 'Fetch a URL',
				'annotations' => [OutsideToolSurface::OUTSIDE_ANNOTATION => true],
			],
		];
	}//end catalog()

	/**
	 * A facade double serving the catalogue and one canned invoke result.
	 *
	 * @param array<string, mixed>|null $invokeResult The envelope invokeTool returns.
	 *
	 * @return ToolRegistryFacade The double.
	 */
	private function facade(?array $invokeResult = null): ToolRegistryFacade {
		$facade = $this->createMock(ToolRegistryFacade::class);
		$facade->method('listTools')->willReturn($this->catalog());
		$facade->method('invokeTool')->willReturn(
			($invokeResult ?? ['result' => [], 'isError' => false])
		);

		return $facade;
	}//end facade()

	/**
	 * An ObjectService double serving one registration.
	 *
	 * @param array<string, mixed>|null $registration The stored registration, or null for none.
	 *
	 * @return ObjectService The double.
	 */
	private function objectService(?array $registration): ObjectService {
		$service = $this->createMock(ObjectService::class);
		$service->method('setRegister')->willReturnSelf();
		$service->method('setSchema')->willReturnSelf();
		$service->method('findAll')->willReturnCallback(
			static function (array $config = [], bool $_rbac = true, bool $_multitenancy = true) use ($registration): array {
				if ($registration === null) {
					return [];
				}

				$entity = new ObjectEntity();
				$entity->setUuid('registration-1');
				$entity->setObject($registration);
				return [$entity];
			}
		);

		return $service;
	}//end objectService()

	/**
	 * Build the gateway.
	 *
	 * @param array<string, mixed>|null $registration The caller's registration.
	 * @param array<string, mixed>|null $invokeResult What the owning app answers.
	 *
	 * @return OutsideAgentGateway The gateway.
	 */
	private function gateway(?array $registration, ?array $invokeResult = null): OutsideAgentGateway {
		$facade = $this->facade($invokeResult);

		return new OutsideAgentGateway(
			$this->objectService($registration),
			new OutsideToolSurface($facade, new NullLogger()),
			$facade,
			new NullLogger()
		);
	}//end gateway()

	/**
	 * The owning app declares and hermiq publishes: exactly the annotated tools are
	 * offered, and hermiq's own never are.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-declared-tool-surface-and-the-prompt-library/specs/agent-tool-governance/spec.md#scenario-the-owning-app-declares-hermiq-publishes
	 */
	public function testTheOwningAppDeclaresAndHermiqPublishes(): void {
		$surface = new OutsideToolSurface($this->facade(), new NullLogger());

		$ids = array_map(
			static fn (array $tool): string => (string)$tool['name'],
			$surface->declaredTools()
		);

		$this->assertSame(['dossiq.case.get', 'dossiq.case.update'], $ids);
		$this->assertNotContains('hermiq.webFetch', $ids);
		$this->assertNotContains('dossiq.case.delete', $ids);
	}//end testTheOwningAppDeclaresAndHermiqPublishes()

	/**
	 * A registration naming no tools cannot write. An empty grant list is not
	 * "everything": that is the same default-deny rule the per-agent grants state,
	 * read from OpenRegister's own classification rather than restated here.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-declared-tool-surface-and-the-prompt-library/specs/agent-tool-governance/spec.md#scenario-a-write-tool-is-denied-unless-granted
	 */
	public function testAWriteToolIsDeniedUnlessGranted(): void {
		$gateway = $this->gateway(['principal' => 'agent-user', 'tools' => [], 'enabled' => true]);

		try {
			$gateway->call(principal: 'agent-user', toolId: 'dossiq.case.update', arguments: []);
			$this->fail('The ungranted write was allowed.');
		} catch (OutsideCallRefusedException $refusal) {
			$this->assertSame(OutsideCallRefusedException::GATE_GRANT, $refusal->gate());
			$this->assertStringContainsString('writes', $refusal->getMessage());
		}

	}//end testAWriteToolIsDeniedUnlessGranted()

	/**
	 * A principal who may write and a registration that was not granted the write
	 * tool still cannot write: the grant gate refuses before the owning app is even
	 * asked.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-declared-tool-surface-and-the-prompt-library/specs/agent-tool-governance/spec.md#scenario-a-permitted-caller-without-the-grant-is-refused
	 */
	public function testAPermittedCallerWithoutTheGrantIsRefused(): void {
		$facade = $this->createMock(ToolRegistryFacade::class);
		$facade->method('listTools')->willReturn($this->catalog());
		// The owning app would have said yes. It is never asked.
		$facade->expects($this->never())->method('invokeTool');

		$gateway = new OutsideAgentGateway(
			$this->objectService(['principal' => 'agent-user', 'tools' => ['dossiq.case.get'], 'enabled' => true]),
			new OutsideToolSurface($facade, new NullLogger()),
			$facade,
			new NullLogger()
		);

		$this->expectException(OutsideCallRefusedException::class);

		$gateway->call(principal: 'agent-user', toolId: 'dossiq.case.update', arguments: []);
	}//end testAPermittedCallerWithoutTheGrantIsRefused()

	/**
	 * A registration granted every tool is still refused when its principal may not
	 * read the case. The refusal comes from the owning app, and nothing here can
	 * overturn it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-declared-tool-surface-and-the-prompt-library/specs/agent-tool-governance/spec.md#scenario-a-granted-agent-without-the-right-is-refused
	 */
	public function testAGrantedAgentWithoutTheRightIsRefused(): void {
		$gateway = $this->gateway(
			['principal' => 'agent-user', 'tools' => ['dossiq.case.get', 'dossiq.case.update'], 'enabled' => true],
			['result' => ['error' => 'You may not read this case'], 'isError' => true]
		);

		try {
			$gateway->call(principal: 'agent-user', toolId: 'dossiq.case.get', arguments: ['id' => 'case-1']);
			$this->fail('The owning app refusal was read as a result.');
		} catch (OutsideCallRefusedException $refusal) {
			$this->assertSame(OutsideCallRefusedException::GATE_OWNING_APP, $refusal->gate());
			$this->assertStringContainsString('You may not read this case', $refusal->getMessage());
		}

	}//end testAGrantedAgentWithoutTheRightIsRefused()

	/**
	 * With no registration at all there is no call, whatever the principal may do
	 * for themselves at a screen.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-declared-tool-surface-and-the-prompt-library/specs/agent-tool-governance/spec.md#requirement-an-outside-agent-must-reach-declared-tools-through-a-registration
	 */
	public function testWithoutARegistrationThereIsNoCall(): void {
		$gateway = $this->gateway(null);

		try {
			$gateway->call(principal: 'agent-user', toolId: 'dossiq.case.get', arguments: []);
			$this->fail('An unregistered caller reached a tool.');
		} catch (OutsideCallRefusedException $refusal) {
			$this->assertSame(OutsideCallRefusedException::GATE_REGISTRATION, $refusal->gate());
		}

	}//end testWithoutARegistrationThereIsNoCall()

	/**
	 * A reading agent does not receive every field: the response carries the three
	 * the registration allows and no others.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-declared-tool-surface-and-the-prompt-library/specs/agent-tool-governance/spec.md#scenario-a-reading-agent-does-not-receive-every-field
	 */
	public function testAReadingAgentDoesNotReceiveEveryField(): void {
		$gateway = $this->gateway(
			[
				'principal' => 'agent-user',
				'tools' => ['dossiq.case.get'],
				'enabled' => true,
				'fieldAllowlist' => ['identificatie', 'status', 'startdatum'],
			],
			[
				'result' => [
					'identificatie' => 'ZAAK-1',
					'status' => 'in behandeling',
					'startdatum' => '2026-01-01',
					'initiatorBsn' => '123456789',
					'toelichting' => 'Mevrouw De Vries vraagt om uitstel',
				],
				'isError' => false,
			]
		);

		$response = $gateway->call(principal: 'agent-user', toolId: 'dossiq.case.get', arguments: []);

		$this->assertSame(['identificatie', 'status', 'startdatum'], array_keys($response['result']));
		$this->assertArrayNotHasKey('initiatorBsn', $response['result']);
		$this->assertArrayNotHasKey('toelichting', $response['result']);
	}//end testAReadingAgentDoesNotReceiveEveryField()

	/**
	 * Nothing is renamed on the way out. A filter that reshaped values would hold a
	 * second copy of the owning app's data model, and a field renamed there would
	 * then silently produce a wrong shape here.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-declared-tool-surface-and-the-prompt-library/specs/agent-tool-governance/spec.md#scenario-nothing-is-renamed-on-the-way-out
	 */
	public function testNothingIsRenamedOnTheWayOut(): void {
		$gateway = $this->gateway(['principal' => 'agent-user', 'tools' => [], 'enabled' => true]);

		$owned = ['identificatie' => 'ZAAK-1', 'startdatum' => '2026-01-01', 'zaaktype' => 'bezwaar'];

		$narrowed = $gateway->narrow(
			registration: ['fieldAllowlist' => ['identificatie', 'startdatum']],
			result: $owned
		);

		foreach ($narrowed as $field => $value) {
			$this->assertArrayHasKey($field, $owned);
			$this->assertSame($owned[$field], $value);
		}
	}//end testNothingIsRenamedOnTheWayOut()

	/**
	 * An empty allowlist is the owning app's own response, untouched: narrowing is
	 * optional, and an unset filter must not read as "nothing allowed".
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-declared-tool-surface-and-the-prompt-library/specs/agent-tool-governance/spec.md#requirement-a-registration-may-narrow-what-a-tool-response-carries
	 */
	public function testAnEmptyAllowlistPassesTheResponseThrough(): void {
		$gateway = $this->gateway(['principal' => 'agent-user', 'tools' => [], 'enabled' => true]);

		$owned = ['identificatie' => 'ZAAK-1', 'zaaktype' => 'bezwaar'];

		$this->assertSame($owned, $gateway->narrow(registration: [], result: $owned));
	}//end testAnEmptyAllowlistPassesTheResponseThrough()

	/**
	 * A list response is narrowed row by row, so a reading agent does not receive
	 * every field of every row either.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-declared-tool-surface-and-the-prompt-library/specs/agent-tool-governance/spec.md#scenario-a-reading-agent-does-not-receive-every-field
	 */
	public function testAListResponseIsNarrowedRowByRow(): void {
		$gateway = $this->gateway(['principal' => 'agent-user', 'tools' => [], 'enabled' => true]);

		$narrowed = $gateway->narrow(
			registration: ['fieldAllowlist' => ['identificatie']],
			result: [
				['identificatie' => 'ZAAK-1', 'initiatorBsn' => '123456789'],
				['identificatie' => 'ZAAK-2', 'initiatorBsn' => '987654321'],
			]
		);

		$this->assertSame([['identificatie' => 'ZAAK-1'], ['identificatie' => 'ZAAK-2']], $narrowed);
	}//end testAListResponseIsNarrowedRowByRow()

	/**
	 * A switched-off registration calls nothing, including the reads it was granted.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-declared-tool-surface-and-the-prompt-library/specs/agent-tool-governance/spec.md#requirement-an-outside-agent-must-reach-declared-tools-through-a-registration
	 */
	public function testASwitchedOffRegistrationCallsNothing(): void {
		$gateway = $this->gateway(
			['principal' => 'agent-user', 'tools' => ['dossiq.case.get'], 'enabled' => false]
		);

		$this->expectException(OutsideCallRefusedException::class);

		$gateway->call(principal: 'agent-user', toolId: 'dossiq.case.get', arguments: []);
	}//end testASwitchedOffRegistrationCallsNothing()

	/**
	 * A tool nobody declared reachable is refused by the surface gate, even when a
	 * registration names it. A registration cannot publish a tool.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-declared-tool-surface-and-the-prompt-library/specs/agent-tool-governance/spec.md#scenario-the-owning-app-declares-hermiq-publishes
	 */
	public function testARegistrationCannotReachAnUndeclaredTool(): void {
		$gateway = $this->gateway(
			['principal' => 'agent-user', 'tools' => ['dossiq.case.delete'], 'enabled' => true]
		);

		try {
			$gateway->call(principal: 'agent-user', toolId: 'dossiq.case.delete', arguments: []);
			$this->fail('An undeclared tool was reachable.');
		} catch (OutsideCallRefusedException $refusal) {
			$this->assertSame(OutsideCallRefusedException::GATE_SURFACE, $refusal->gate());
		}

	}//end testARegistrationCannotReachAnUndeclaredTool()

	/**
	 * The gateway never impersonates. A registration that could switch principals
	 * would be exactly the standing right this design refuses to give it, so the
	 * class is checked for the impersonation seam rather than trusted not to have
	 * grown one.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-declared-tool-surface-and-the-prompt-library/specs/agent-tool-governance/spec.md#scenario-revocation-reaches-the-agent-without-an-edit
	 */
	public function testTheGatewayNeverImpersonates(): void {
		$source = (string)file_get_contents(
			__DIR__ . '/../../../../lib/Service/OutsideAgent/OutsideAgentGateway.php'
		);

		$this->assertStringNotContainsString('setUser(', $source);
		$this->assertStringNotContainsString('IUserSession', $source);
	}//end testTheGatewayNeverImpersonates()
}//end class
