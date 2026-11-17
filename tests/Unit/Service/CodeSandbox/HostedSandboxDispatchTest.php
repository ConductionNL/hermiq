<?php

/**
 * A hosted run goes through integriq only, and every failure is named.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/tools-code-sandbox/specs/code-sandbox/spec.md#requirement-the-admin-chooses-where-code-runs-req-sandbox-004
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\CodeSandbox;

use OCA\Hermiq\Service\CodeSandbox\CodeSandboxUnavailableException;
use OCA\Hermiq\Service\CodeSandbox\HostedSandboxDispatch;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\Hermiq\Service\CodeSandbox\HostedSandboxDispatch
 * @uses   \OCA\Hermiq\Support\FleetAppId
 * @uses   \OCA\Hermiq\Service\CodeSandbox\CodeSandboxUnavailableException
 */
class HostedSandboxDispatchTest extends TestCase {

	/**
	 * A dispatch over a CallService answer and the given sources.
	 *
	 * @param mixed                    $answer  The call log, or a Throwable to throw.
	 * @param array<int, mixed>|null   $sources The sources; null when the object service fails.
	 * @param bool                     $integriq Whether integriq is installed.
	 *
	 * @return HostedSandboxDispatch
	 */
	private function dispatch(mixed $answer, ?array $sources, bool $integriq=true): HostedSandboxDispatch {
		$callService = new class($answer) {
			/**
			 * Constructor.
			 *
			 * @param mixed $answer The answer.
			 */
			public function __construct(private mixed $answer) {
			}//end __construct()

			/**
			 * Answer or throw.
			 *
			 * @param mixed  $source         The source.
			 * @param string $endpoint       The endpoint.
			 * @param string $method         The method.
			 * @param array  $config         The config.
			 * @param bool   $withholdBodies Bodies withheld.
			 *
			 * @return ObjectEntity
			 */
			public function call(mixed $source, string $endpoint='', string $method='GET', array $config=[], bool $withholdBodies=false): ObjectEntity {
				if ($this->answer instanceof \Throwable) {
					throw $this->answer;
				}

				return $this->answer;
			}//end call()
		};
		$objects = new class($sources) {
			/**
			 * Constructor.
			 *
			 * @param array|null $sources The sources.
			 */
			public function __construct(private ?array $sources) {
			}//end __construct()

			/**
			 * The sources, or a failure.
			 *
			 * @param array $config The query.
			 * @param bool  $_rbac  RBAC.
			 *
			 * @return array<int, mixed>
			 */
			public function findAll(array $config=[], bool $_rbac=true): array {
				if ($this->sources === null) {
					throw new RuntimeException('register down');
				}

				return $this->sources;
			}//end findAll()
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($callService, $objects, $integriq): object {
				if ($id === 'OCA\Integriq\Service\CallService' && $integriq === true) {
					return $callService;
				}

				if ($id === HostedSandboxDispatch::OBJECT_SERVICE) {
					return $objects;
				}

				throw new RuntimeException('not installed');
			}
		);

		return new HostedSandboxDispatch(container: $container, logger: new NullLogger());
	}//end dispatch()

	/**
	 * A source entity.
	 *
	 * @param string               $uuid The uuid.
	 * @param array<string, mixed> $data The data.
	 *
	 * @return ObjectEntity
	 */
	private function source(string $uuid, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($data);
		return $entity;
	}//end source()

	/**
	 * A call log with a status and a body.
	 *
	 * @param int    $status The status.
	 * @param string $body   The body.
	 *
	 * @return ObjectEntity
	 */
	private function callLog(int $status, string $body): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setObject(['response' => ['statusCode' => $status, 'body' => $body]]);
		return $entity;
	}//end callLog()

	/**
	 * The source is found by uuid, slug or id, and the answer's body comes back.
	 *
	 * @return void
	 */
	public function testTheSourceIsFoundByUuidSlugOrId(): void {
		$sources = ['junk', $this->source(uuid: 'u-1', data: ['slug' => 'hosted-sandbox', 'id' => 'i-1'])];
		$dispatch = $this->dispatch(answer: $this->callLog(status: 200, body: '{"exitCode":0}'), sources: $sources);

		foreach (['u-1', 'hosted-sandbox', 'i-1'] as $sourceId) {
			$this->assertTrue($dispatch->isAvailable(sourceId: $sourceId));
			$this->assertSame('{"exitCode":0}', $dispatch->run(sourceId: $sourceId, payload: [], timeoutSeconds: 10));
		}

		$this->assertFalse($dispatch->isAvailable(sourceId: 'other'));
		$this->assertFalse($dispatch->isAvailable(sourceId: ' '));
	}//end testTheSourceIsFoundByUuidSlugOrId()

	/**
	 * Without integriq, without the source, or with unreadable sources nothing is sent.
	 *
	 * @return void
	 */
	public function testNothingIsSentWithoutIntegriqOrTheSource(): void {
		$sources = [$this->source(uuid: 'u-1', data: ['slug' => 'hosted-sandbox'])];
		foreach ([
			$this->dispatch(answer: new RuntimeException('must not be called'), sources: $sources, integriq: false),
			$this->dispatch(answer: new RuntimeException('must not be called'), sources: []),
			$this->dispatch(answer: new RuntimeException('must not be called'), sources: null),
		] as $dispatch) {
			$this->assertFalse($dispatch->isAvailable(sourceId: 'hosted-sandbox'));
			try {
				$dispatch->run(sourceId: 'hosted-sandbox', payload: [], timeoutSeconds: 10);
				$this->fail('A run was sent.');
			} catch (CodeSandboxUnavailableException $e) {
				$this->assertSame(HostedSandboxDispatch::SOURCE_MISSING, $e->getMessage());
			}
		}
	}//end testNothingIsSentWithoutIntegriqOrTheSource()

	/**
	 * A refused or failed call is an error with a neutral message.
	 *
	 * @return void
	 */
	public function testARefusedOrFailedCallIsAnError(): void {
		$sources = [$this->source(uuid: 'u-1', data: [])];

		try {
			$this->dispatch(answer: $this->callLog(status: 502, body: 'bad gateway'), sources: $sources)->run(sourceId: 'u-1', payload: [], timeoutSeconds: 10);
			$this->fail('A 502 was accepted.');
		} catch (RuntimeException $e) {
			$this->assertNotInstanceOf(CodeSandboxUnavailableException::class, $e);
			$this->assertSame('The code sandbox could not run the code (HTTP 502).', $e->getMessage());
		}

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('The hosted code sandbox could not be reached.');
		$this->dispatch(answer: new RuntimeException('connect timeout with secret'), sources: $sources)->run(sourceId: 'u-1', payload: [], timeoutSeconds: 10);
	}//end testARefusedOrFailedCallIsAnError()
}//end class
