<?php

/**
 * Failure-path test for RegisterAgentLeafListener.
 *
 * The leaf catalogue is built by every app's listener in turn, so one app's
 * failure must not take the catalogue down: Hermiq logs and skips its own leaf.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/hermiq-agent-leaf/tasks.md#2-agent-render-leaf-adr-019
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Listener;

use OCA\Hermiq\Listener\RegisterAgentLeafListener;
use OCA\OpenRegister\Event\RegisterLeafProvidersEvent;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests that a failing registration is logged and skipped.
 *
 * @covers \OCA\Hermiq\Listener\RegisterAgentLeafListener
 *
 * @spec openspec/changes/hermiq-agent-leaf/tasks.md#2-agent-render-leaf-adr-019
 */
class RegisterAgentLeafListenerFailureTest extends TestCase {

	/**
	 * A failure while building the descriptor registers nothing and is logged once.
	 *
	 * @return void
	 */
	public function testAFailingRegistrationIsLoggedAndSkipped(): void {
		$failure = new RuntimeException('translations unavailable');

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willThrowException($failure);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with(
				'Hermiq could not register the hermiq-agent leaf: translations unavailable',
				['exception' => $failure]
			);

		$event = new RegisterLeafProvidersEvent();
		(new RegisterAgentLeafListener($l10n, $logger))->handle($event);

		$this->assertSame([], $event->getLeaves());

	}//end testAFailingRegistrationIsLoggedAndSkipped()
}//end class
