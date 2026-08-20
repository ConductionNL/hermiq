<?php

/**
 * Unit tests for RegisterAgentLeafListener (agent-object-leaf).
 *
 * Asserts the listener contributes exactly one `hermiq-agent` leaf, render-only
 * (null provider), declaring the render-surface + agent-runner kinds and gated on
 * the `hermiq` app, and that a non-matching event is ignored.
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
 * @spec openspec/changes/hermiq-agent-leaf/specs/agent-object-leaf/spec.md#requirement-agent-integration-leaf-registration
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Listener;

use OCA\Hermiq\Listener\RegisterAgentLeafListener;
use OCA\OpenRegister\Event\RegisterLeafProvidersEvent;
use OCA\OpenRegister\Service\Integration\LeafDescriptor;
use OCP\EventDispatcher\Event;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for RegisterAgentLeafListener.
 *
 * @spec openspec/changes/hermiq-agent-leaf/tasks.md#2-agent-render-leaf-adr-019
 */
class RegisterAgentLeafListenerTest extends TestCase {

	/**
	 * Build the listener with an identity l10n and a null logger.
	 *
	 * @return RegisterAgentLeafListener
	 */
	private function listener(): RegisterAgentLeafListener {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

		return new RegisterAgentLeafListener($l10n, $this->createMock(LoggerInterface::class));
	}//end listener()

	/**
	 * The listener registers the hermiq-agent leaf, render-only, with the expected kinds.
	 *
	 * @return void
	 */
	public function testRegistersHermiqAgentLeaf(): void {
		$event = new RegisterLeafProvidersEvent();

		$this->listener()->handle($event);

		$leaves = $event->getLeaves();
		$this->assertCount(1, $leaves);

		/** @var LeafDescriptor $descriptor */
		$descriptor = $leaves[0]['descriptor'];
		$this->assertInstanceOf(LeafDescriptor::class, $descriptor);
		$this->assertSame('hermiq-agent', $descriptor->getId());
		$this->assertSame('hermiq', $descriptor->getRequiredApp());
		$this->assertTrue($descriptor->hasKind(LeafDescriptor::KIND_RENDER_SURFACE));
		$this->assertTrue($descriptor->hasKind(LeafDescriptor::KIND_AGENT_RUNNER));
		$this->assertContains('detail-page', $descriptor->getSurfaces());

		// Vue 3 leaf under a Vue 2.7 host renders via the JS `mount`/`unmount`
		// hand-off, so the server descriptor declares renderMode 'mount' to match
		// the JS registration under the shared id (openregister#2127, gate-24).
		$this->assertSame(LeafDescriptor::RENDER_MODE_MOUNT, $descriptor->getRenderMode());

		// Render-only: no data provider.
		$this->assertNull($leaves[0]['provider']);
	}//end testRegistersHermiqAgentLeaf()

	/**
	 * A non-matching event contributes nothing and does not throw.
	 *
	 * @return void
	 */
	public function testIgnoresNonMatchingEvent(): void {
		$this->expectNotToPerformAssertions();
		$this->listener()->handle(new Event());
	}//end testIgnoresNonMatchingEvent()
}//end class
