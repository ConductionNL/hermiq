<?php

/**
 * A computer target is kept by admins and reached only through its integriq source.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/tools-browser-and-computer-use/specs/computer-use/spec.md#requirement-an-admin-registers-a-machine-reached-only-through-integriq-req-computer-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\ComputerUse;

use OCA\Hermiq\Service\ComputerUse\ComputerTargetRefusal;
use OCA\Hermiq\Service\ComputerUse\ComputerTargetService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * @covers \OCA\Hermiq\Service\ComputerUse\ComputerTargetService
 * @covers \OCA\Hermiq\Service\ComputerUse\ComputerTargetRefusal
 */
final class ComputerTargetServiceTest extends TestCase {

	/**
	 * Stored objects: uuid => [register, schema, data].
	 *
	 * @var array<string, array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	private array $objects = [];

	/**
	 * The calls integriq's CallService received.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $calls = [];

	/**
	 * What the remote-control service answers.
	 *
	 * @var array<string, mixed>|RuntimeException
	 */
	private array|RuntimeException $answer = [];

	/**
	 * Whether integriq is installed.
	 *
	 * @var bool
	 */
	private bool $integriq = true;

	protected function setUp(): void {
		$this->objects['src-1'] = ['integriq', 'source', ['name' => 'wz-burgerzaken-03']];
		$this->objects['ct-1']  = ['hermiq', 'computertarget', ['name' => 'Werkplek Burgerzaken 03', 'source' => 'src-1', 'os' => 'windows', 'enabled' => false]];
		$this->answer = ['statusCode' => 200, 'body' => json_encode(['image' => base64_encode('PNG-BYTES'), 'width' => 1920, 'height' => 1080])];
	}

	/**
	 * An entity over a stored object.
	 *
	 * @param string $uuid The uuid.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $uuid): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getUuid')->willReturn($uuid);
		$entity->method('getObject')->willReturnCallback(fn (): array => $this->objects[$uuid][2]);
		return $entity;
	}

	/**
	 * The service over the in-memory objects and a recording CallService.
	 *
	 * @return ComputerTargetService
	 */
	private function service(): ComputerTargetService {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend=[], bool $files=false, mixed $register=null, mixed $schema=null): ?ObjectEntity {
				$stored = ($this->objects[(string)$id] ?? null);
				if ($stored === null || $stored[0] !== $register || $stored[1] !== $schema) {
					return null;
				}

				return $this->entity(uuid: (string)$id);
			}
		);
		$objects->method('setRegister')->willReturnSelf();
		$objects->method('setSchema')->willReturnSelf();
		$objects->method('findAll')->willReturnCallback(
			fn (): array => array_map(fn (string $uuid): ObjectEntity => $this->entity(uuid: $uuid), array_keys(array_filter($this->objects, static fn (array $o): bool => $o[1] === 'computertarget')))
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ?array $extend=[], mixed $register=null, mixed $schema=null, ?string $uuid=null): ObjectEntity {
				$uuid = ($uuid ?? 'ct-new');
				$this->objects[$uuid] = [(string)$register, (string)$schema, (array)$object];
				return $this->entity(uuid: $uuid);
			}
		);
		$objects->method('deleteObject')->willReturnCallback(
			function (string $uuid): bool {
				unset($this->objects[$uuid]);
				return true;
			}
		);

		$test   = $this;
		$caller = new class ($test) {
			public function __construct(private readonly ComputerTargetServiceTest $test) {
			}

			public function call(object $source, string $endpoint='', string $method='GET', array $config=[]): object {
				return $this->test->answerCall(source: $source, endpoint: $endpoint, method: $method);
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($caller): object {
				if ($this->integriq === true && $id === 'OCA\Integriq\Service\CallService') {
					return $caller;
				}

				throw new RuntimeException('no ' . $id);
			}
		);

		return new ComputerTargetService($objects, $container);
	}

	/**
	 * Record a call and answer it (called by the CallService double).
	 *
	 * @param object $source   The source.
	 * @param string $endpoint The endpoint.
	 * @param string $method   The method.
	 *
	 * @return object The call log.
	 */
	public function answerCall(object $source, string $endpoint, string $method): object {
		$this->calls[] = ['source' => $source->getUuid(), 'endpoint' => $endpoint, 'method' => $method];
		if ($this->answer instanceof RuntimeException) {
			throw $this->answer;
		}

		$answer = $this->answer;
		return new class ($answer) {
			public function __construct(private readonly array $answer) {
			}

			public function getResponse(): array {
				return $this->answer;
			}
		};
	}

	public function testATestScreenshotGoesThroughTheTargetsSourceAndIsNotStored(): void {
		$before = $this->objects;
		$shot   = $this->service()->screenshot(id: 'ct-1');

		self::assertSame(['image' => base64_encode('PNG-BYTES'), 'mimeType' => 'image/png', 'width' => 1920, 'height' => 1080], $shot);
		self::assertSame([['source' => 'src-1', 'endpoint' => '/screenshot', 'method' => 'POST']], $this->calls);
		self::assertSame($before, $this->objects, 'nothing is stored');
	}

	public function testAScreenshotThatCannotBeTakenIsRefused(): void {
		$cases = [
			'unknown target' => [fn () => $this->service()->screenshot(id: 'ct-x'), 404],
			'no integriq' => [function () {
				$this->integriq = false;
				return $this->service()->screenshot(id: 'ct-1');
			}, 409],
			'machine down' => [function () {
				$this->integriq = true;
				$this->answer = new RuntimeException('timeout');
				return $this->service()->screenshot(id: 'ct-1');
			}, 502],
			'not an image' => [function () {
				$this->answer = ['statusCode' => 200, 'body' => '{"image":"%%%"}'];
				return $this->service()->screenshot(id: 'ct-1');
			}, 502],
			'error status' => [function () {
				$this->answer = ['statusCode' => 500, 'body' => json_encode(['image' => base64_encode('x')])];
				return $this->service()->screenshot(id: 'ct-1');
			}, 502],
		];
		foreach ($cases as $name => [$run, $status]) {
			try {
				$run();
				self::fail($name . ' was answered');
			} catch (ComputerTargetRefusal $refusal) {
				self::assertSame($status, $refusal->getStatus(), $name);
			}
		}
	}

	public function testATargetNeedsANameAndASourceIntegriqHolds(): void {
		$service = $this->service();
		foreach ([['source' => 'src-1'], ['name' => ' ', 'source' => 'src-1'], ['name' => 'W', 'source' => 'nope'], ['name' => 'W', 'source' => 'ct-1']] as $data) {
			try {
				$service->save(data: $data);
				self::fail('saved ' . json_encode($data));
			} catch (ComputerTargetRefusal $refusal) {
				self::assertSame(400, $refusal->getStatus());
			}
		}
	}

	public function testSaveKeepsOnlyTheTargetsFieldsAndIsOffByDefault(): void {
		$saved = $this->service()->save(data: ['name' => ' Werkplek 04 ', 'source' => 'src-1', 'os' => 'linux', 'owner' => 'eve', 'applications' => 'Suite4Sociaal Domein, versie 12']);

		self::assertSame(
			['id' => 'ct-new', 'name' => 'Werkplek 04', 'source' => 'src-1', 'os' => 'windows', 'applications' => 'Suite4Sociaal Domein, versie 12', 'enabled' => false],
			$saved
		);
		self::assertSame(['hermiq', 'computertarget'], [$this->objects['ct-new'][0], $this->objects['ct-new'][1]]);
	}

	public function testAnUpdateKeepsWhatItDoesNotNameAndAnUnknownIdIsNotFound(): void {
		$updated = $this->service()->save(data: ['enabled' => true], id: 'ct-1');
		self::assertSame(['id' => 'ct-1', 'name' => 'Werkplek Burgerzaken 03', 'source' => 'src-1', 'os' => 'windows', 'enabled' => true], $updated);

		$this->expectException(ComputerTargetRefusal::class);
		$this->service()->save(data: ['enabled' => true], id: 'ct-x');
	}

	public function testListAndDelete(): void {
		$service = $this->service();
		self::assertSame(['ct-1'], array_column($service->list(), 'id'));
		$service->delete(id: 'ct-1');
		self::assertSame([], $service->list());
	}
}
