<?php

/**
 * Unit tests for AiFeatureService lifecycle transitions (enable / disable).
 *
 * Covers: an unknown or blank feature reports not-found without a write, a
 * feature already in the target state is an idempotent no-op, a transition
 * from a state outside the allowed from-set is invalid without a write, a
 * permitted transition persists the new lifecycle through ObjectService, and
 * a lifecycle-guard refusal (HookStoppedException) maps to blocked.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service
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

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\AiFeature\AiFeatureLookup;
use OCA\Hermiq\Service\AiFeature\FeatureTransition;
use OCA\Hermiq\Service\AiFeatureService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\HookStoppedException;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for AiFeatureService::enable() and ::disable().
 */
#[CoversClass(AiFeatureService::class)]
#[UsesClass(FeatureTransition::class)]
class AiFeatureServiceTransitionTest extends TestCase {

	/**
	 * Mock ObjectService.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $objectService;

	/**
	 * Service under test.
	 *
	 * @var AiFeatureService
	 */
	private AiFeatureService $service;

	/**
	 * Wire fresh mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objectService = $this->createMock(ObjectService::class);
		$this->service       = new AiFeatureService(
			objectService: $this->objectService,
			appConfig: $this->createMock(IAppConfig::class),
			lookup: $this->createMock(AiFeatureLookup::class),
		);
	}//end setUp()

	/**
	 * Make find() return a feature with the given payload.
	 *
	 * @param array<string, mixed> $payload The AiFeature body.
	 *
	 * @return void
	 */
	private function givenFeature(array $payload): void {
		$feature = new ObjectEntity();
		$feature->setUuid('feat-1');
		$feature->setObject($payload);
		$this->objectService->method('find')
			->with('feat-1', [], false, 'hermiq', 'agentaifeature')
			->willReturn($feature);
	}//end givenFeature()

	/**
	 * A blank id is not found and nothing is read or written.
	 *
	 * @return void
	 */
	public function testBlankIdIsNotFound(): void {
		$this->objectService->expects($this->never())->method('find');
		$this->objectService->expects($this->never())->method('saveObject');

		$this->assertSame(AiFeatureService::RESULT_NOT_FOUND, $this->service->enable(''));
	}//end testBlankIdIsNotFound()

	/**
	 * An unknown feature is not found and nothing is written.
	 *
	 * @return void
	 */
	public function testUnknownFeatureIsNotFound(): void {
		$this->objectService->method('find')->willReturn(null);
		$this->objectService->expects($this->never())->method('saveObject');

		$this->assertSame(AiFeatureService::RESULT_NOT_FOUND, $this->service->disable('missing'));
	}//end testUnknownFeatureIsNotFound()

	/**
	 * Enabling a disabled feature persists lifecycle=enabled and keeps the rest of the body.
	 *
	 * @return void
	 */
	public function testEnablePersistsTheEnabledLifecycle(): void {
		$this->givenFeature(['slug' => 'summaries', 'lifecycle' => 'disabled']);
		$this->objectService->expects($this->once())->method('saveObject')
			->with(
				$this->callback(static fn (array $o): bool => $o['lifecycle'] === 'enabled' && $o['slug'] === 'summaries'),
				[],
				'hermiq',
				'agentaifeature',
				'feat-1'
			)
			->willReturn(new ObjectEntity());

		$this->assertSame(AiFeatureService::RESULT_ENABLED, $this->service->enable('feat-1'));
	}//end testEnablePersistsTheEnabledLifecycle()

	/**
	 * A feature without a lifecycle counts as disabled, so enable is allowed.
	 *
	 * @return void
	 */
	public function testMissingLifecycleDefaultsToDisabled(): void {
		$this->givenFeature(['slug' => 'summaries']);
		$this->objectService->expects($this->once())->method('saveObject')->willReturn(new ObjectEntity());

		$this->assertSame(AiFeatureService::RESULT_ENABLED, $this->service->enable('feat-1'));
	}//end testMissingLifecycleDefaultsToDisabled()

	/**
	 * Disabling an enabled feature persists lifecycle=disabled.
	 *
	 * @return void
	 */
	public function testDisablePersistsTheDisabledLifecycle(): void {
		$this->givenFeature(['lifecycle' => 'enabled']);
		$this->objectService->expects($this->once())->method('saveObject')
			->with($this->callback(static fn (array $o): bool => $o['lifecycle'] === 'disabled'))
			->willReturn(new ObjectEntity());

		$this->assertSame(AiFeatureService::RESULT_DISABLED, $this->service->disable('feat-1'));
	}//end testDisablePersistsTheDisabledLifecycle()

	/**
	 * Already in the target state: idempotent, no write.
	 *
	 * @return void
	 */
	public function testTransitionToTheCurrentStateIsANoOp(): void {
		$this->givenFeature(['lifecycle' => 'enabled']);
		$this->objectService->expects($this->never())->method('saveObject');

		$this->assertSame(AiFeatureService::RESULT_ENABLED, $this->service->enable('feat-1'));
	}//end testTransitionToTheCurrentStateIsANoOp()

	/**
	 * A from-state outside the allowed set is invalid, without a write.
	 *
	 * @return void
	 */
	public function testTransitionFromAnUnlistedStateIsInvalid(): void {
		$this->givenFeature(['lifecycle' => 'retired']);
		$this->objectService->expects($this->never())->method('saveObject');

		$this->assertSame(AiFeatureService::RESULT_INVALID, $this->service->enable('feat-1'));
		$this->assertSame(AiFeatureService::RESULT_INVALID, $this->service->disable('feat-1'));
	}//end testTransitionFromAnUnlistedStateIsInvalid()

	/**
	 * A guard refusal on save surfaces as blocked, not as an exception.
	 *
	 * @return void
	 */
	public function testGuardRefusalIsReportedAsBlocked(): void {
		$this->givenFeature(['lifecycle' => 'disabled']);
		$this->objectService->method('saveObject')
			->willThrowException(new HookStoppedException('DPO acknowledgement required'));

		$this->assertSame(AiFeatureService::RESULT_BLOCKED, $this->service->enable('feat-1'));
	}//end testGuardRefusalIsReportedAsBlocked()
}//end class
