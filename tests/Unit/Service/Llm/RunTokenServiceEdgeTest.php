<?php

/**
 * Hermiq RunTokenService refusal-edge unit tests.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Llm
 *
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

namespace OCA\Hermiq\Tests\Unit\Service\Llm;

use OCA\Hermiq\Service\Llm\RunTokenService;
use OCA\Hermiq\Service\Llm\RunTokenStore;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for the run-token paths that must refuse rather than authorise.
 *
 * @covers \OCA\Hermiq\Service\Llm\RunTokenService
 * @uses   \OCA\Hermiq\Service\Llm\RunTokenRequest
 */
class RunTokenServiceEdgeTest extends TestCase {

	/**
	 * Build the service over a store.
	 *
	 * @param RunTokenStore $store The store.
	 * @param string        $token What the random generator yields.
	 *
	 * @return RunTokenService The service.
	 */
	private function service(RunTokenStore $store, string $token = 'tok'): RunTokenService {
		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturn($token);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1000);

		return new RunTokenService(store: $store, secureRandom: $random, timeFactory: $time);
	}//end service()

	/**
	 * A record that cannot be encoded is never stored and no token is handed out.
	 *
	 * @return void
	 */
	public function testAnUnencodableRecordMintsNothing(): void {
		$store = $this->createMock(RunTokenStore::class);
		$store->expects($this->never())->method('put');
		$store->expects($this->never())->method('purge');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Could not encode the run-token record.');

		$this->service($store)->mint(runId: "run-\xB1\x31", agentId: 'agent', userId: 'alice');
	}//end testAnUnencodableRecordMintsNothing()

	/**
	 * The empty token never verifies, even when a live record matches its digest.
	 *
	 * @return void
	 */
	public function testTheEmptyTokenNeverVerifies(): void {
		$digest = hash('sha256', '');
		$store = $this->createMock(RunTokenStore::class);
		$store->expects($this->once())->method('find')->with($digest)->willReturn(
			[
				'record' => (string)json_encode(['runId' => 'r', 'agentId' => 'a', 'userId' => 'u', 'hash' => $digest]),
				'consumed' => false,
				'expiresAt' => 2000,
			]
		);

		$this->assertNull($this->service($store)->verify(''));
	}//end testTheEmptyTokenNeverVerifies()

	/**
	 * A stored record that is not a hashed JSON object is not recognised.
	 *
	 * @return void
	 */
	public function testAMalformedRecordIsNotKnown(): void {
		$store = $this->createMock(RunTokenStore::class);
		$store->method('find')->willReturnOnConsecutiveCalls(
			['record' => 'not json', 'consumed' => false, 'expiresAt' => 2000],
			['record' => '{"hash":42}', 'consumed' => false, 'expiresAt' => 2000],
			['record' => (string)json_encode(['hash' => hash('sha256', 'abc')]), 'consumed' => true, 'expiresAt' => 0]
		);
		$service = $this->service($store);

		$this->assertFalse($service->isKnown('abc'));
		$this->assertFalse($service->isKnown('abc'));
		$this->assertTrue($service->isKnown('abc'));
	}//end testAMalformedRecordIsNotKnown()

	/**
	 * Consuming the empty token touches nothing; any other token is marked by its digest.
	 *
	 * @return void
	 */
	public function testConsumeSkipsTheEmptyTokenAndMarksOthersByDigest(): void {
		$store = $this->createMock(RunTokenStore::class);
		$store->expects($this->once())->method('markConsumed')->with(hash('sha256', 'abc'));
		$service = $this->service($store);

		$service->consume('');
		$service->consume('abc');
	}//end testConsumeSkipsTheEmptyTokenAndMarksOthersByDigest()
}//end class
