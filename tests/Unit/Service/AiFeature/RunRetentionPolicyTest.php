<?php

/**
 * Hermiq RunRetentionPolicy unit tests.
 *
 * Covers the instance default that always exists, the per-feature override, the
 * copied-not-referenced stamp, and the difference between a job that has never run
 * and one that ran and removed nothing.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\AiFeature
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
 * @spec openspec/changes/what-the-model-reads-and-what-is-kept/specs/run-audit-log/spec.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\AiFeature;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use OCA\Hermiq\Service\AiFeature\RunRetentionPolicy;
use OCA\Hermiq\Service\AiFeatureService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * RunRetentionPolicy unit tests.
 *
 * @spec openspec/changes/what-the-model-reads-and-what-is-kept/specs/run-audit-log/spec.md
 */
class RunRetentionPolicyTest extends TestCase {

	/**
	 * An in-memory IAppConfig double, so a written default can be read back.
	 *
	 * @param array<string, string> $initial The initial stored values, by key.
	 *
	 * @return IAppConfig The double.
	 */
	private function appConfig(array $initial = []): IAppConfig {
		$config = $this->createMock(IAppConfig::class);
		$store = $initial;

		$config->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use (&$store): string {
				return ($store[$key] ?? $default);
			}
		);

		$config->method('setValueString')->willReturnCallback(
			static function (string $app, string $key, string $value) use (&$store): bool {
				$store[$key] = $value;
				return true;
			}
		);

		return $config;
	}//end appConfig()

	/**
	 * An AiFeatureService double answering findBySlug from a slug-keyed map.
	 *
	 * @param array<string, array<string, mixed>> $features The features, by slug.
	 *
	 * @return AiFeatureService The double.
	 */
	private function features(array $features): AiFeatureService {
		$service = $this->createMock(AiFeatureService::class);
		$service->method('findBySlug')->willReturnCallback(
			static function (string $slug) use ($features): ?ObjectEntity {
				if (isset($features[$slug]) === false) {
					return null;
				}

				$entity = new ObjectEntity();
				$entity->setObject(array_merge(['slug' => $slug], $features[$slug]));
				return $entity;
			}
		);

		return $service;
	}//end features()

	/**
	 * An instance that has never been configured still has a retention, because a
	 * retention nobody set is a retention of forever.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/what-the-model-reads-and-what-is-kept/specs/run-audit-log/spec.md#requirement-every-ai-run-must-carry-the-retention-that-applied-when-it-was-written
	 */
	public function testAnUnconfiguredInstanceStillHasARetention(): void {
		$policy = new RunRetentionPolicy($this->appConfig(), $this->features([]));

		$this->assertSame(RunRetentionPolicy::DEFAULT_DAYS, $policy->defaultDays());
	}//end testAnUnconfiguredInstanceStillHasARetention()

	/**
	 * A run carries the default that applied when it was written.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/what-the-model-reads-and-what-is-kept/specs/run-audit-log/spec.md#scenario-a-run-knows-its-own-expiry
	 */
	public function testARunKnowsItsOwnExpiry(): void {
		$policy = new RunRetentionPolicy($this->appConfig(['runRetentionDays' => '90']), $this->features([]));

		$stamped = $policy->stamp(
			context: ['status' => 'ok'],
			featureSlug: null,
			now: new DateTimeImmutable('2026-01-01T00:00:00+00:00')
		);

		$this->assertSame(90, $stamped['retentionDays']);
		$this->assertStringStartsWith('2026-04-01', $stamped['retentionExpiresAt']);
	}//end testARunKnowsItsOwnExpiry()

	/**
	 * Changing the default does not move a promise already made: the stamp is a
	 * copy, and an already-written run keeps the number it was written with.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/what-the-model-reads-and-what-is-kept/specs/run-audit-log/spec.md#scenario-changing-the-default-does-not-move-an-old-promise
	 */
	public function testChangingTheDefaultDoesNotMoveAnOldPromise(): void {
		$config = $this->appConfig(['runRetentionDays' => '90']);
		$policy = new RunRetentionPolicy($config, $this->features([]));

		$old = $policy->stamp(context: [], now: new DateTimeImmutable('2026-01-01T00:00:00+00:00'));

		$policy->setDefaultDays(days: 30);

		$new = $policy->stamp(context: [], now: new DateTimeImmutable('2026-01-01T00:00:00+00:00'));

		$this->assertSame(90, $old['retentionDays']);
		$this->assertSame(30, $new['retentionDays']);
		$this->assertStringStartsWith('2026-04-01', $old['retentionExpiresAt']);
		$this->assertStringStartsWith('2026-01-31', $new['retentionExpiresAt']);
	}//end testChangingTheDefaultDoesNotMoveAnOldPromise()

	/**
	 * A feature may keep less than the instance does.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/what-the-model-reads-and-what-is-kept/specs/run-audit-log/spec.md#scenario-a-feature-may-keep-less
	 */
	public function testAFeatureMayKeepLess(): void {
		$policy = new RunRetentionPolicy(
			$this->appConfig(['runRetentionDays' => '90']),
			$this->features(['samenvatten' => ['retentionDays' => 7]])
		);

		$this->assertSame(7, $policy->retentionDaysFor(featureSlug: 'samenvatten'));
		$this->assertSame(90, $policy->retentionDaysFor(featureSlug: 'vertalen'));
	}//end testAFeatureMayKeepLess()

	/**
	 * A retention outside the permitted range is refused rather than stored, so a
	 * typed zero cannot quietly delete every run before anybody reads it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/what-the-model-reads-and-what-is-kept/specs/run-audit-log/spec.md#requirement-every-ai-run-must-carry-the-retention-that-applied-when-it-was-written
	 */
	public function testARetentionOutsideTheRangeIsRefused(): void {
		$policy = new RunRetentionPolicy($this->appConfig(), $this->features([]));

		$this->expectException(InvalidArgumentException::class);

		$policy->setDefaultDays(days: 0);
	}//end testARetentionOutsideTheRangeIsRefused()

	/**
	 * A job that has never run reads as never run, not as a successful run of zero.
	 * Those are different answers to "is retention enforced here".
	 *
	 * @return void
	 *
	 * @spec openspec/changes/what-the-model-reads-and-what-is-kept/specs/run-audit-log/spec.md#scenario-a-job-that-has-never-run-is-visible-as-such
	 */
	public function testAJobThatHasNeverRunIsVisibleAsSuch(): void {
		$policy = new RunRetentionPolicy($this->appConfig(), $this->features([]));

		$report = $policy->lastCleanup();

		$this->assertFalse($report['ran']);
		$this->assertNull($report['removed']);
	}//end testAJobThatHasNeverRunIsVisibleAsSuch()

	/**
	 * After a cleanup, the last run is an answerable question: a time and a count.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/what-the-model-reads-and-what-is-kept/specs/run-audit-log/spec.md#scenario-the-last-cleanup-is-an-answerable-question
	 */
	public function testTheLastCleanupIsAnAnswerableQuestion(): void {
		$policy = new RunRetentionPolicy($this->appConfig(), $this->features([]));

		$policy->recordCleanup(removed: 12, at: new DateTimeImmutable('2026-02-03T04:05:06', new DateTimeZone('UTC')));

		$report = $policy->lastCleanup();

		$this->assertTrue($report['ran']);
		$this->assertSame(12, $report['removed']);
		$this->assertStringStartsWith('2026-02-03', $report['at']);
	}//end testTheLastCleanupIsAnAnswerableQuestion()

	/**
	 * A cleanup that removed nothing still reads as a run, which is what separates
	 * it from a job that has never run.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/what-the-model-reads-and-what-is-kept/specs/run-audit-log/spec.md#scenario-a-job-that-has-never-run-is-visible-as-such
	 */
	public function testACleanupThatRemovedNothingStillReadsAsARun(): void {
		$policy = new RunRetentionPolicy($this->appConfig(), $this->features([]));

		$policy->recordCleanup(removed: 0);

		$report = $policy->lastCleanup();

		$this->assertTrue($report['ran']);
		$this->assertSame(0, $report['removed']);
	}//end testACleanupThatRemovedNothingStillReadsAsARun()
}//end class
