<?php

/**
 * Edge-case tests for AiFeatureDpoAckGuard.
 *
 * Pins the exact refusal sentences, the trimming of slug and tenant before the
 * acknowledgement key is built, the precedence of `tenantId` over the legacy
 * `tenant_id`, and that the verdict does not depend on the action or the caller.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Lifecycle
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

namespace OCA\Hermiq\Tests\Unit\Lifecycle;

use OCA\Hermiq\Lifecycle\AiFeatureDpoAckGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Edge cases of the DPO-ack lifecycle guard.
 *
 * @covers \OCA\Hermiq\Lifecycle\AiFeatureDpoAckGuard
 */
class AiFeatureDpoAckGuardEdgeTest extends TestCase {

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
		$this->appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$this->guard     = new AiFeatureDpoAckGuard(appConfig: $this->appConfig);
	}//end setUp()

	/**
	 * Expect exactly one acknowledgement lookup on the given key, answered with the given value.
	 *
	 * @param string $key   The expected IAppConfig key.
	 * @param string $value The stored acknowledgement.
	 *
	 * @return void
	 */
	private function expectLookup(string $key, string $value): void {
		$this->appConfig->expects($this->once())
			->method('getValueString')
			->with('hermiq', $key, '')
			->willReturn($value);
	}//end expectLookup()

	/**
	 * The prohibited-practice refusal names Art. 5 and says no acknowledgement helps.
	 *
	 * @return void
	 */
	public function testUnacceptableRiskRefusalSentence(): void {
		$this->appConfig->expects($this->never())->method('getValueString');

		$result = $this->guard->check(object: ['slug' => 'x', 'riskCategory' => 'unacceptable'], action: 'enable', userId: 'dpo');

		$this->assertFalse(condition: $result->isAllowed());
		$this->assertSame(
			expected: 'This AI feature is classified "unacceptable" (a prohibited practice under '
			. 'EU AI Act Art. 5) and cannot be enabled, regardless of DPO acknowledgement.',
			actual: $result->getMessage()
		);
	}//end testUnacceptableRiskRefusalSentence()

	/**
	 * The prohibition is checked before the slug: no slug still yields the Art. 5 refusal.
	 *
	 * @return void
	 */
	public function testUnacceptableRiskOutranksAMissingSlug(): void {
		$result = $this->guard->check(object: ['riskCategory' => 'unacceptable'], action: 'enable', userId: 'dpo');

		$this->assertStringContainsString(needle: 'EU AI Act Art. 5', haystack: (string) $result->getMessage());
	}//end testUnacceptableRiskOutranksAMissingSlug()

	/**
	 * A whitespace-only slug is no slug: refused with its own sentence, config never read.
	 *
	 * @return void
	 */
	public function testWhitespaceSlugIsRefusedWithoutALookup(): void {
		$this->appConfig->expects($this->never())->method('getValueString');

		$result = $this->guard->check(object: ['slug' => "  \t ", 'tenantId' => 'acme'], action: 'enable', userId: 'dpo');

		$this->assertFalse(condition: $result->isAllowed());
		$this->assertSame(
			expected: 'This AI feature has no slug, so the DPO acknowledgement cannot be verified.',
			actual: $result->getMessage()
		);
	}//end testWhitespaceSlugIsRefusedWithoutALookup()

	/**
	 * An absent acknowledgement is refused with the DPO sentence.
	 *
	 * @return void
	 */
	public function testMissingAcknowledgementRefusalSentence(): void {
		$this->expectLookup(key: 'dpo_ack.feature', value: '');

		$result = $this->guard->check(object: ['slug' => 'feature'], action: 'enable', userId: 'dpo');

		$this->assertFalse(condition: $result->isAllowed());
		$this->assertSame(
			expected: 'This AI feature has not been acknowledged by the Data Protection Officer and cannot be enabled.',
			actual: $result->getMessage()
		);
	}//end testMissingAcknowledgementRefusalSentence()

	/**
	 * Slug and tenant are trimmed before they form the key.
	 *
	 * @return void
	 */
	public function testSlugAndTenantAreTrimmedInTheKey(): void {
		$this->expectLookup(key: 'dpo_ack.acme.chat-companion', value: 'dpo@now');

		$result = $this->guard->check(object: ['slug' => ' chat-companion ', 'tenantId' => ' acme '], action: 'enable', userId: 'dpo');

		$this->assertTrue(condition: $result->isAllowed());
		$this->assertNull(actual: $result->getMessage());
	}//end testSlugAndTenantAreTrimmedInTheKey()

	/**
	 * `tenantId` wins over the legacy `tenant_id` when both are present.
	 *
	 * @return void
	 */
	public function testTenantIdOutranksTheLegacyTenantId(): void {
		$this->expectLookup(key: 'dpo_ack.new.feature', value: 'dpo@now');

		$result = $this->guard->check(object: ['slug' => 'feature', 'tenantId' => 'new', 'tenant_id' => 'old'], action: 'enable', userId: 'dpo');

		$this->assertTrue(condition: $result->isAllowed());
	}//end testTenantIdOutranksTheLegacyTenantId()

	/**
	 * A blank `tenantId` is present, so `tenant_id` is not consulted and the unscoped key is used.
	 *
	 * @return void
	 */
	public function testBlankTenantIdFallsToTheUnscopedKey(): void {
		$this->expectLookup(key: 'dpo_ack.feature', value: 'dpo@now');

		$result = $this->guard->check(object: ['slug' => 'feature', 'tenantId' => '  ', 'tenant_id' => 'old'], action: 'enable', userId: 'dpo');

		$this->assertTrue(condition: $result->isAllowed());
	}//end testBlankTenantIdFallsToTheUnscopedKey()

	/**
	 * A scalar non-string slug and tenant are cast into the key.
	 *
	 * @return void
	 */
	public function testNumericSlugAndTenantAreCast(): void {
		$this->expectLookup(key: 'dpo_ack.7.42', value: 'dpo@now');

		$result = $this->guard->check(object: ['slug' => 42, 'tenantId' => 7], action: 'enable', userId: 'dpo');

		$this->assertTrue(condition: $result->isAllowed());
	}//end testNumericSlugAndTenantAreCast()

	/**
	 * A high-risk feature is not prohibited: an acknowledgement lets it through.
	 *
	 * @return void
	 */
	public function testHighRiskIsAllowedOnceAcknowledged(): void {
		$this->expectLookup(key: 'dpo_ack.feature', value: 'dpo@now');

		$result = $this->guard->check(object: ['slug' => 'feature', 'riskCategory' => 'high'], action: 'enable', userId: 'dpo');

		$this->assertTrue(condition: $result->isAllowed());
	}//end testHighRiskIsAllowedOnceAcknowledged()

	/**
	 * The verdict ignores the action and the caller.
	 *
	 * @return void
	 */
	public function testActionAndCallerDoNotChangeTheVerdict(): void {
		$this->appConfig->expects($this->exactly(count: 2))
			->method('getValueString')
			->with('hermiq', 'dpo_ack.feature', '')
			->willReturn('');

		$enable  = $this->guard->check(object: ['slug' => 'feature'], action: 'enable', userId: 'dpo');
		$disable = $this->guard->check(object: ['slug' => 'feature'], action: 'disable', userId: 'someone-else');

		$this->assertFalse(condition: $enable->isAllowed());
		$this->assertFalse(condition: $disable->isAllowed());
		$this->assertSame(expected: $enable->getMessage(), actual: $disable->getMessage());
	}//end testActionAndCallerDoNotChangeTheVerdict()
}//end class
