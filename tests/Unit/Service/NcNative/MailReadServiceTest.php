<?php

/**
 * Tests for the nc-mail-read-tools MailReadService.
 *
 * The behaviour that matters most here is the AI-FEATURE GATE, and it is asserted
 * as a control pair rather than a presence check. These tools are honestly
 * `readOnlyHint: true`, so the write default-deny that protects
 * `nc-native-write-tools` does NOT protect them — this gate is the only thing
 * standing between a tool grant and an agent reading a user's inbox. A test that
 * only proved "disabled refuses" would pass just as well against a service that
 * refused everything, so the enabled half is asserted too.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\NcNative
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\NcNative;

use OCA\Hermiq\Service\AiFeatureService;
use OCA\Hermiq\Service\NcNative\MailReadService;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Unit tests for MailReadService.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\NcNative
 */
class MailReadServiceTest extends TestCase {

	/**
	 * Build the service with a feature register in the given state.
	 *
	 * @param bool|null $enabled True/false for a feature in that state, null for absent.
	 * @param bool $throws Whether the feature lookup throws.
	 *
	 * @return MailReadService
	 */
	private function service(?bool $enabled, bool $throws = false): MailReadService {
		$features = $this->createMock(AiFeatureService::class);

		if ($throws === true) {
			$features->method('findBySlug')->willThrowException(new RuntimeException('register down'));
		} elseif ($enabled === null) {
			$features->method('findBySlug')->willReturn(null);
		} else {
			$feature = new ObjectEntity();
			$feature->setObject(['enabled' => $enabled]);
			$features->method('findBySlug')->willReturn($feature);
		}

		return new MailReadService(
			$features,
			$this->createMock(ContainerInterface::class),
			$this->createMock(LoggerInterface::class)
		);

	}//end service()

	/**
	 * Every mail tool refuses when the AI feature is not enabled.
	 *
	 * @return void
	 */
	public function testDisabledFeatureRefusesEveryTool(): void {
		$service = $this->service(false);

		$results = [
			$service->listAccounts('alice'),
			$service->listMessages('alice', ['mailboxId' => 1]),
			$service->readMessage('alice', ['id' => 1]),
		];

		foreach ($results as $result) {
			$this->assertSame('mail_reading_not_enabled', $result['error']['code']);
		}

	}//end testDisabledFeatureRefusesEveryTool()

	/**
	 * An ABSENT feature refuses too — the gate fails closed rather than treating
	 * "never configured" as permission.
	 *
	 * @return void
	 */
	public function testAbsentFeatureRefuses(): void {
		$result = $this->service(null)->listAccounts('alice');

		$this->assertSame('mail_reading_not_enabled', $result['error']['code']);

	}//end testAbsentFeatureRefuses()

	/**
	 * A feature register that cannot be read refuses rather than defaulting open.
	 *
	 * @return void
	 */
	public function testUnreadableFeatureRegisterRefuses(): void {
		$result = $this->service(null, true)->listAccounts('alice');

		$this->assertSame('mail_reading_not_enabled', $result['error']['code']);

	}//end testUnreadableFeatureRegisterRefuses()

	/**
	 * 🔴 The positive half of the control pair. With the feature ENABLED the gate
	 * lets the call through and it fails for a DIFFERENT reason — Mail being
	 * absent in this environment. Without this assertion, every test above would
	 * pass equally well against a service that refused everything unconditionally,
	 * and the gate would look load-bearing while proving nothing.
	 *
	 * @return void
	 */
	public function testEnabledFeaturePassesTheGateAndFailsOnMailInstead(): void {
		$result = $this->service(true)->listAccounts('alice');

		$this->assertArrayHasKey('error', $result);
		$this->assertSame(
			'mail_not_available',
			$result['error']['code'],
			'With the feature enabled the gate must be passed — the remaining failure must be Mail, not the gate.'
		);

	}//end testEnabledFeaturePassesTheGateAndFailsOnMailInstead()

	/**
	 * Mail being absent is a structured error for every tool, never an exception:
	 * `invokeTool()` must never throw, so an agent run continues.
	 *
	 * @return void
	 */
	public function testMailAbsentIsAStructuredErrorForEveryTool(): void {
		$service = $this->service(true);

		foreach (
			[
				$service->listAccounts('alice'),
				$service->listMessages('alice', ['mailboxId' => 1]),
				$service->readMessage('alice', ['id' => 1]),
			] as $result
		) {
			$this->assertSame('mail_not_available', $result['error']['code']);
		}

	}//end testMailAbsentIsAStructuredErrorForEveryTool()

	/**
	 * A missing mailbox id is refused before Mail is touched.
	 *
	 * @return void
	 */
	public function testListMessagesRequiresAMailboxId(): void {
		$result = $this->service(true)->listMessages('alice', []);

		$this->assertSame('invalid_argument', $result['error']['code']);

	}//end testListMessagesRequiresAMailboxId()

	/**
	 * A missing message id is refused before Mail is touched.
	 *
	 * @return void
	 */
	public function testReadMessageRequiresAnId(): void {
		$result = $this->service(true)->readMessage('alice', []);

		$this->assertSame('invalid_argument', $result['error']['code']);

	}//end testReadMessageRequiresAnId()

	/**
	 * The page-size ceiling is a server-side constant, not a suggestion. An
	 * unbounded listing is the difference between an assistant reading a thread
	 * and an assistant enumerating an inbox.
	 *
	 * @return void
	 */
	public function testPageSizeCeilingIsDeclaredAndModest(): void {
		$this->assertSame(50, MailReadService::MAX_PAGE_SIZE);
		$this->assertSame('mail-reading', MailReadService::FEATURE_SLUG);

	}//end testPageSizeCeilingIsDeclaredAndModest()

	/**
	 * No method on this service writes, sends, deletes, flags or moves anything.
	 *
	 * A structural assertion: the guarantee is that the capability does not exist,
	 * and that is only true if no method offers it.
	 *
	 * @return void
	 */
	public function testServiceExposesNoWriteVerb(): void {
		foreach (get_class_methods(MailReadService::class) as $method) {
			foreach (['delete', 'send', 'move', 'flag', 'draft', 'markRead'] as $forbidden) {
				$this->assertStringNotContainsStringIgnoringCase(
					$forbidden,
					$method,
					"MailReadService::{$method} — nc-mail-read-tools is read-only."
				);
			}
		}

	}//end testServiceExposesNoWriteVerb()

}//end class
