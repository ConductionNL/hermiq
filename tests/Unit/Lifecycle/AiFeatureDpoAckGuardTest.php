<?php

/**
 * Unit tests for AiFeatureDpoAckGuard (ai-feature-governance-register).
 *
 * The DPO-ack lifecycle guard is the imperative business-rule seam that blocks an
 * AiFeature `enable` transition until the Data Protection Officer has acknowledged the
 * feature (recorded in IAppConfig). The guard is fail-closed: a missing slug or an absent
 * acknowledgement denies the transition. The tenant is derived from the object
 * (`tenantId`, falling back to `tenant_id`) so the key matches the acknowledge write-path.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ai-feature-governance-register/tasks.md#task-6-1
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Lifecycle;

use OCA\Hermiq\Lifecycle\AiFeatureDpoAckGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the DPO-ack lifecycle guard.
 *
 * @spec openspec/changes/ai-feature-governance-register/tasks.md#task-6-1
 */
class AiFeatureDpoAckGuardTest extends TestCase {

	/**
	 * Mock IAppConfig.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig $appConfig;

	/**
	 * The guard under test.
	 *
	 * @var AiFeatureDpoAckGuard
	 */
	private AiFeatureDpoAckGuard $guard;

	/**
	 * Wire the guard with a mocked IAppConfig.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->guard = new AiFeatureDpoAckGuard($this->appConfig);

	}//end setUp()

	/**
	 * An acknowledged feature (non-empty IAppConfig value) allows the transition.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/ai-feature-governance-register/tasks.md#task-6-1
	 */
	public function testAcknowledgedAllows(): void {
		$this->appConfig->method('getValueString')
			->with('hermiq', 'dpo_ack.acme.autonomous-agent-run', '')
			->willReturn('dpo@2026-07-05T00:00:00+00:00');

		$result = $this->guard->check(
			['slug' => 'autonomous-agent-run', 'tenantId' => 'acme'],
			'enable',
			'dpo'
		);

		$this->assertTrue($result->isAllowed());

	}//end testAcknowledgedAllows()

	/**
	 * A not-acknowledged feature (empty IAppConfig value) denies the transition.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/ai-feature-governance-register/tasks.md#task-6-1
	 */
	public function testNotAcknowledgedDenies(): void {
		$this->appConfig->method('getValueString')->willReturn('');

		$result = $this->guard->check(
			['slug' => 'autonomous-agent-run', 'tenantId' => 'acme'],
			'enable',
			'dpo'
		);

		$this->assertFalse($result->isAllowed());
		$this->assertNotNull($result->getMessage());

	}//end testNotAcknowledgedDenies()

	/**
	 * A missing slug denies the transition (fail-closed) without reading config.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/ai-feature-governance-register/tasks.md#task-6-1
	 */
	public function testMissingSlugDenies(): void {
		$this->appConfig->expects($this->never())->method('getValueString');

		$result = $this->guard->check(['tenantId' => 'acme'], 'enable', 'dpo');

		$this->assertFalse($result->isAllowed());

	}//end testMissingSlugDenies()

	/**
	 * The tenant-scoped key is used when tenantId is present.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/ai-feature-governance-register/tasks.md#task-6-1
	 */
	public function testTenantScopedKey(): void {
		$this->appConfig->expects($this->once())
			->method('getValueString')
			->with('hermiq', 'dpo_ack.acme.chat-companion', '')
			->willReturn('dpo@now');

		$result = $this->guard->check(
			['slug' => 'chat-companion', 'tenantId' => 'acme'],
			'enable',
			'dpo'
		);

		$this->assertTrue($result->isAllowed());

	}//end testTenantScopedKey()

	/**
	 * The legacy unscoped key is used when no tenant is present (tenantId / tenant_id absent).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/ai-feature-governance-register/tasks.md#task-6-1
	 */
	public function testLegacyUnscopedKey(): void {
		$this->appConfig->expects($this->once())
			->method('getValueString')
			->with('hermiq', 'dpo_ack.chat-companion', '')
			->willReturn('dpo@now');

		$result = $this->guard->check(['slug' => 'chat-companion'], 'enable', 'dpo');

		$this->assertTrue($result->isAllowed());

	}//end testLegacyUnscopedKey()

	/**
	 * The legacy `tenant_id` key is honoured as a fallback for the tenant scope.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/ai-feature-governance-register/tasks.md#task-6-1
	 */
	public function testLegacyTenantIdFallback(): void {
		$this->appConfig->expects($this->once())
			->method('getValueString')
			->with('hermiq', 'dpo_ack.acme.chat-companion', '')
			->willReturn('dpo@now');

		$result = $this->guard->check(
			['slug' => 'chat-companion', 'tenant_id' => 'acme'],
			'enable',
			'dpo'
		);

		$this->assertTrue($result->isAllowed());

	}//end testLegacyTenantIdFallback()
}//end class
