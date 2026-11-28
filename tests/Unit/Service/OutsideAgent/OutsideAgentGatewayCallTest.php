<?php

/**
 * Characterisation of OutsideAgentGateway::registrationFor and the owning-app
 * half of call(): which registration is found, what a failed read logs, and
 * how a refusal or a non-list payload from the owning app comes back.
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
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Mcp\Service\Mcp\ToolRegistryFacade;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use RuntimeException;
use Stringable;

final class OutsideAgentGatewayCallTest extends TestCase {

	/**
	 * Log lines: [level, message].
	 *
	 * @var array<int, array{0: mixed, 1: string}>
	 */
	private array $logs = [];

	/**
	 * findAll calls: [register, schema, config, rbac, multitenancy].
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $queries = [];

	public function testABlankPrincipalHasNoRegistrationAndIsNotLookedUp(): void {
		$gateway = $this->gateway(found: [], invoke: null);

		self::assertNull($gateway->registrationFor(principal: '  '));
		self::assertSame([], $this->queries);
	}//end testABlankPrincipalHasNoRegistrationAndIsNotLookedUp()

	public function testTheFirstEntityForThePrincipalIsReturnedWithItsId(): void {
		$other = $this->entity(uuid: 'r-0', data: ['principal' => 'someone-else']);
		$mine = $this->entity(uuid: 'r-1', data: ['principal' => 'bot', 'tools' => ['x']]);
		$second = $this->entity(uuid: 'r-2', data: ['principal' => 'bot']);
		$gateway = $this->gateway(found: ['not an entity', $other, $mine, $second], invoke: null);

		self::assertSame(['id' => 'r-1', 'principal' => 'bot', 'tools' => ['x']], $gateway->registrationFor(principal: 'bot'));
		self::assertSame([['agentoutsideregistration', ['filters' => ['principal' => 'bot'], 'limit' => 50], false, false]], $this->queries);
	}//end testTheFirstEntityForThePrincipalIsReturnedWithItsId()

	public function testNoMatchingEntityIsNoRegistration(): void {
		$gateway = $this->gateway(found: [$this->entity(uuid: 'r-0', data: ['principal' => 'someone-else'])], invoke: null);

		self::assertNull($gateway->registrationFor(principal: 'bot'));
	}//end testNoMatchingEntityIsNoRegistration()

	public function testAFailedReadIsLoggedAndIsNoRegistration(): void {
		$gateway = $this->gateway(found: new RuntimeException('db down'), invoke: null);

		self::assertNull($gateway->registrationFor(principal: 'bot'));
		self::assertSame([['warning', 'Hermiq could not read the outside-agent registrations: db down']], $this->logs);
	}//end testAFailedReadIsLoggedAndIsNoRegistration()

	public function testAnOwningAppThatThrowsIsItsOwnGate(): void {
		$gateway = $this->gateway(found: [$this->granted()], invoke: new RuntimeException('no such case'));

		try {
			$gateway->call(principal: 'bot', toolId: 'dossiq.case.get', arguments: ['id' => 1]);
			self::fail('Expected the owning app refusal.');
		} catch (OutsideCallRefusedException $refusal) {
			self::assertSame("Refused by the owning-app gate: 'dossiq.case.get' for principal 'bot': no such case.", $refusal->getMessage());
			self::assertSame(OutsideCallRefusedException::GATE_OWNING_APP, $refusal->gate());
			self::assertNull($refusal->getPrevious());
		}
	}//end testAnOwningAppThatThrowsIsItsOwnGate()

	public function testAnErrorEnvelopeWithoutWordsGetsTheDefaultSentence(): void {
		$gateway = $this->gateway(found: [$this->granted()], invoke: ['isError' => true, 'result' => 'nope']);

		$this->expectExceptionMessage("Refused by the owning-app gate: 'dossiq.case.get' for principal 'bot': the owning app refused this call for this principal.");

		$gateway->call(principal: 'bot', toolId: 'dossiq.case.get', arguments: []);
	}//end testAnErrorEnvelopeWithoutWordsGetsTheDefaultSentence()

	public function testANonListPayloadPassesThroughAndAMissingOneIsEmpty(): void {
		$text = $this->gateway(found: [$this->granted()], invoke: ['result' => 'plain', 'isError' => false]);
		$none = $this->gateway(found: [$this->granted()], invoke: ['isError' => false]);

		self::assertSame(['result' => 'plain', 'isError' => false], $text->call(principal: 'bot', toolId: 'dossiq.case.get', arguments: []));
		self::assertSame(['result' => [], 'isError' => false], $none->call(principal: 'bot', toolId: 'dossiq.case.get', arguments: []));
	}//end testANonListPayloadPassesThroughAndAMissingOneIsEmpty()

	public function testAnArrayPayloadIsNarrowedByTheRegistration(): void {
		$registration = $this->entity(uuid: 'r-1', data: ['principal' => 'bot', 'tools' => ['dossiq.case.get'], 'fieldAllowlist' => ['title']]);
		$gateway = $this->gateway(found: [$registration], invoke: ['result' => ['title' => 'A', 'secret' => 'B'], 'isError' => false]);

		self::assertSame(['result' => ['title' => 'A'], 'isError' => false], $gateway->call(principal: 'bot', toolId: 'dossiq.case.get', arguments: []));
	}//end testAnArrayPayloadIsNarrowedByTheRegistration()

	private function granted(): ObjectEntity {
		return $this->entity(uuid: 'r-1', data: ['principal' => 'bot', 'tools' => ['dossiq.case.get']]);
	}//end granted()

	private function entity(string $uuid, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($data);
		return $entity;
	}//end entity()

	/**
	 * The gateway over doubles.
	 *
	 * @param array<int, mixed>|RuntimeException            $found  What findAll returns, or throws.
	 * @param array<string, mixed>|RuntimeException|null    $invoke What invokeTool returns, or throws.
	 *
	 * @return OutsideAgentGateway
	 */
	private function gateway(array|RuntimeException $found, array|RuntimeException|null $invoke): OutsideAgentGateway {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('setRegister')->willReturnSelf();
		$schema = '';
		$objects->method('setSchema')->willReturnCallback(
			function (string $slug) use ($objects, &$schema): ObjectService {
				$schema = $slug;
				return $objects;
			}
		);
		$objects->method('findAll')->willReturnCallback(
			function (array $config = [], bool $_rbac = true, bool $_multitenancy = true) use ($found, &$schema): array {
				$this->queries[] = [$schema, $config, $_rbac, $_multitenancy];
				if ($found instanceof RuntimeException) {
					throw $found;
				}

				return $found;
			}
		);
		$facade = $this->createMock(ToolRegistryFacade::class);
		$facade->method('listTools')->willReturn([['name' => 'dossiq.case.get', 'annotations' => [OutsideToolSurface::OUTSIDE_ANNOTATION => true, 'readOnlyHint' => true]]]);
		if ($invoke instanceof RuntimeException) {
			$facade->method('invokeTool')->willThrowException($invoke);
		} else {
			$facade->method('invokeTool')->willReturn($invoke ?? ['result' => [], 'isError' => false]);
		}

		return new OutsideAgentGateway(objectService: $objects, surface: new OutsideToolSurface($facade, new NullLogger()), toolRegistryFacade: $facade, logger: $this->logger());
	}//end gateway()

	private function logger(): AbstractLogger {
		$logs = &$this->logs;
		return new class($logs) extends AbstractLogger {
			public function __construct(private array &$logs) {
			}

			public function log($level, string|Stringable $message, array $context = []): void {
				$this->logs[] = [$level, (string)$message];
			}
		};
	}//end logger()
}//end class
