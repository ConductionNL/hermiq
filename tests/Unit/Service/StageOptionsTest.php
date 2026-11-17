<?php

/**
 * Unit tests for StageOptions (stage dispatch option defaults).
 *
 * Covers: the timeout ceiling falling back to the caller's default, the
 * injected credential preferring the push credential, and the tool archive
 * ref defaulting to HEAD.
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

use OCA\Hermiq\Service\StageOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * StageOptions behaviour tests.
 */
#[CoversClass(StageOptions::class)]
class StageOptionsTest extends TestCase {

	/**
	 * A positive timeout wins; zero falls back to the default.
	 *
	 * @return void
	 */
	public function testCeilingOrPrefersAPositiveTimeout(): void {
		$this->assertSame(5000, (new StageOptions(timeoutMs: 5000))->ceilingOr(defaultMs: 60000));
		$this->assertSame(60000, (new StageOptions())->ceilingOr(defaultMs: 60000));
		$this->assertSame(60000, (new StageOptions(timeoutMs: -1))->ceilingOr(defaultMs: 60000));

	}//end testCeilingOrPrefersAPositiveTimeout()

	/**
	 * The push credential is injected when set, else the stage credential.
	 *
	 * @return void
	 */
	public function testInjectCredentialIdPrefersThePushCredential(): void {
		$this->assertSame('push-cred', (new StageOptions(credentialId: 'cred', pushCredentialId: 'push-cred'))->injectCredentialId());
		$this->assertSame('cred', (new StageOptions(credentialId: 'cred'))->injectCredentialId());
		$this->assertSame('', (new StageOptions())->injectCredentialId());

	}//end testInjectCredentialIdPrefersThePushCredential()

	/**
	 * The tool ref is used when set, else HEAD.
	 *
	 * @return void
	 */
	public function testToolArchiveRefDefaultsToHead(): void {
		$this->assertSame('v1.2.0', (new StageOptions(toolRef: 'v1.2.0'))->toolArchiveRef());
		$this->assertSame('HEAD', (new StageOptions(toolRepo: 'ConductionNL/tools'))->toolArchiveRef());

	}//end testToolArchiveRefDefaultsToHead()
}//end class
