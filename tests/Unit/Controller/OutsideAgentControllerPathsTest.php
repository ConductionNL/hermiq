<?php

/**
 * Unit tests for the remaining paths of OutsideAgentController.
 *
 * An outside agent sees the declared tool surface and what its own registration
 * grants, and every call it makes is recorded whatever the outcome. These tests
 * pin the tool listing, what a call hands the gateway, that a failing gateway is
 * a logged 500 without a record, and that a failing audit write never turns a
 * finished call into an error.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Controller
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
 * @spec openspec/changes/the-declared-tool-surface-and-the-prompt-library/specs/ai-feature-admin-surface/spec.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\OutsideAgentController;
use OCA\Hermiq\Service\OutsideAgent\OutsideAgentGateway;
use OCA\Hermiq\Service\OutsideAgent\OutsideCallRefusedException;
use OCA\Hermiq\Service\OutsideAgent\OutsideToolSurface;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrail\ObjectAuditEvent;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The outside agent controller's listing, call and audit paths.
 */
#[CoversClass(OutsideAgentController::class)]
#[UsesClass(OutsideCallRefusedException::class)]
class OutsideAgentControllerPathsTest extends TestCase {

	/**
	 * The audit events recorded during a test.
	 *
	 * @var array<int, ObjectAuditEvent>
	 */
	private array $events = [];

	/**
	 * A request answering these parameters.
	 *
	 * @param array<string, mixed> $params The parameters.
	 *
	 * @return IRequest The request.
	 */
	private function request(array $params): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => (array_key_exists($key, $params) === true ? $params[$key] : $default)
		);
		return $request;
	}//end request()

	/**
	 * A session for the outside agent's principal, or for nobody.
	 *
	 * @param string|null $uid The principal, or null.
	 *
	 * @return IUserSession The session.
	 */
	private function session(?string $uid='agent-7'): IUserSession {
		$session = $this->createMock(IUserSession::class);
		if ($uid === null) {
			$session->method('getUser')->willReturn(null);
			return $session;
		}

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session->method('getUser')->willReturn($user);
		return $session;
	}//end session()

	/**
	 * An audit mapper that keeps what it records, or fails.
	 *
	 * @param bool $fails Whether recording throws.
	 *
	 * @return AuditTrailMapper The mapper.
	 */
	private function audit(bool $fails=false): AuditTrailMapper {
		$mapper = $this->createMock(AuditTrailMapper::class);
		$mapper->method('recordObjectEvent')->willReturnCallback(
			function (ObjectAuditEvent $event) use ($fails): AuditTrail {
				if ($fails === true) {
					throw new RuntimeException('audit table locked');
				}

				$this->events[] = $event;
				return new AuditTrail();
			}
		);
		return $mapper;
	}//end audit()

	/**
	 * The controller over these collaborators.
	 *
	 * @param array<string, mixed> $params  The request parameters.
	 * @param OutsideToolSurface   $surface The tool surface.
	 * @param OutsideAgentGateway  $gateway The gateway.
	 * @param array<string, mixed> $options `session`, `audit`, `logger`.
	 *
	 * @return OutsideAgentController The controller.
	 */
	private function controller(array $params, OutsideToolSurface $surface, OutsideAgentGateway $gateway, array $options=[]): OutsideAgentController {
		return new OutsideAgentController(
			$this->request($params),
			$surface,
			$gateway,
			$options['session'] ?? $this->session(),
			$options['audit'] ?? $this->audit(),
			$options['logger'] ?? $this->createMock(LoggerInterface::class)
		);
	}//end controller()

	/**
	 * The listing shows the declared tools and what the registration grants, as strings.
	 *
	 * @return void
	 */
	public function testTheListingShowsTheSurfaceAndTheGrantedTools(): void {
		$surface = $this->createMock(OutsideToolSurface::class);
		$surface->method('declaredTools')->willReturn([['id' => 'zaak.read'], ['id' => 'zaak.write']]);
		$gateway = $this->createMock(OutsideAgentGateway::class);
		$gateway->expects($this->once())->method('registrationFor')->with('agent-7')->willReturn(['tools' => ['zaak.read', 42]]);

		$response = $this->controller([], $surface, $gateway)->tools();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			['tools' => [['id' => 'zaak.read'], ['id' => 'zaak.write']], 'registered' => true, 'granted' => ['zaak.read', '42']],
			$response->getData()
		);
	}//end testTheListingShowsTheSurfaceAndTheGrantedTools()

	/**
	 * An unregistered principal, or a registration without a tool list, is granted nothing.
	 *
	 * @return void
	 */
	public function testAnUnregisteredPrincipalIsGrantedNothing(): void {
		$surface = $this->createMock(OutsideToolSurface::class);
		$surface->method('declaredTools')->willReturn([]);
		$gateway = $this->createMock(OutsideAgentGateway::class);
		$gateway->method('registrationFor')->willReturnOnConsecutiveCalls(null, ['tools' => 'all']);

		$this->assertSame(['tools' => [], 'registered' => false, 'granted' => []], $this->controller([], $surface, $gateway)->tools()->getData());
		$this->assertSame(['tools' => [], 'registered' => true, 'granted' => []], $this->controller([], $surface, $gateway)->tools()->getData());
	}//end testAnUnregisteredPrincipalIsGrantedNothing()

	/**
	 * A surface that cannot be read is a logged 500, and nobody signed in lists nothing.
	 *
	 * @return void
	 */
	public function testAnUnreadableSurfaceIsALogged500(): void {
		$surface = $this->createMock(OutsideToolSurface::class);
		$surface->method('declaredTools')->willThrowException(new RuntimeException('manifest broken'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with('Hermiq outside tool surface failed: manifest broken');

		$response = $this->controller([], $surface, $this->createMock(OutsideAgentGateway::class), ['logger' => $logger])->tools();
		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'Could not read the tool surface'], $response->getData());

		$surfaceUnused = $this->createMock(OutsideToolSurface::class);
		$surfaceUnused->expects($this->never())->method('declaredTools');
		$anonymous = $this->controller([], $surfaceUnused, $this->createMock(OutsideAgentGateway::class), ['session' => $this->session(null)])->tools();
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $anonymous->getStatus());
	}//end testAnUnreadableSurfaceIsALogged500()

	/**
	 * A call hands the gateway the tool and its arguments, and records the success.
	 *
	 * @return void
	 */
	public function testACallIsHandedToTheGatewayAndRecorded(): void {
		$gateway = $this->createMock(OutsideAgentGateway::class);
		$gateway->expects($this->once())->method('call')
			->with('agent-7', 'zaak.read', ['id' => 'z1'])
			->willReturn(['zaak' => ['id' => 'z1']]);

		$response = $this->controller(['tool' => 'zaak.read', 'arguments' => ['id' => 'z1']], $this->createMock(OutsideToolSurface::class), $gateway)->call();

		$this->assertSame(['zaak' => ['id' => 'z1']], $response->getData());
		$this->assertCount(1, $this->events);
		$this->assertSame(OutsideAgentController::AUDIT_ACTION, $this->events[0]->action);
		$this->assertSame(['principal' => 'agent-7', 'tool' => 'zaak.read', 'outcome' => 'ok', 'gate' => ''], $this->events[0]->context);
	}//end testACallIsHandedToTheGatewayAndRecorded()

	/**
	 * Arguments that are no list are sent as none.
	 *
	 * @return void
	 */
	public function testArgumentsThatAreNoListAreSentAsNone(): void {
		$gateway = $this->createMock(OutsideAgentGateway::class);
		$gateway->expects($this->once())->method('call')->with('agent-7', '', [])->willReturn([]);

		$this->controller(['arguments' => 'id=z1'], $this->createMock(OutsideToolSurface::class), $gateway)->call();
	}//end testArgumentsThatAreNoListAreSentAsNone()

	/**
	 * A refused call names the gate that refused it in both the answer and the record.
	 *
	 * @return void
	 */
	public function testARefusalNamesItsGate(): void {
		$gateway = $this->createMock(OutsideAgentGateway::class);
		$gateway->method('call')->willThrowException(
			new OutsideCallRefusedException(OutsideCallRefusedException::GATE_GRANT, 'zaak.write', 'agent-7', 'not granted')
		);

		$response = $this->controller(['tool' => 'zaak.write'], $this->createMock(OutsideToolSurface::class), $gateway)->call();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(
			['error' => "Refused by the registration-grant gate: 'zaak.write' for principal 'agent-7': not granted.", 'gate' => 'registration-grant'],
			$response->getData()
		);
		$this->assertSame(['principal' => 'agent-7', 'tool' => 'zaak.write', 'outcome' => 'refused', 'gate' => 'registration-grant'], $this->events[0]->context);
	}//end testARefusalNamesItsGate()

	/**
	 * A gateway failure is a logged 500 and is not recorded as a call.
	 *
	 * @return void
	 */
	public function testAGatewayFailureIsALogged500WithoutARecord(): void {
		$gateway = $this->createMock(OutsideAgentGateway::class);
		$gateway->method('call')->willThrowException(new RuntimeException('owning app down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with('Hermiq outside agent call failed: owning app down');

		$response = $this->controller(['tool' => 'zaak.read'], $this->createMock(OutsideToolSurface::class), $gateway, ['logger' => $logger])->call();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'The call could not be completed'], $response->getData());
		$this->assertSame([], $this->events);
	}//end testAGatewayFailureIsALogged500WithoutARecord()

	/**
	 * An audit write that fails is warned about and the call's answer still stands.
	 *
	 * @return void
	 */
	public function testAFailedAuditWriteKeepsTheAnswer(): void {
		$gateway = $this->createMock(OutsideAgentGateway::class);
		$gateway->method('call')->willReturn(['ok' => true]);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('Hermiq could not record an outside-agent call: audit table locked');
		$logger->expects($this->never())->method('error');

		$response = $this->controller(['tool' => 'zaak.read'], $this->createMock(OutsideToolSurface::class), $gateway, ['audit' => $this->audit(true), 'logger' => $logger])->call();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['ok' => true], $response->getData());
	}//end testAFailedAuditWriteKeepsTheAnswer()
}//end class
