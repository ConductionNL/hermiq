<?php

/**
 * @category Tests
 * @package  OCA\Hermiq\Tests\Unit
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/personal-preferences/specs/personal-preferences/spec.md#requirement-the-voorkeuren-section-offers-the-drawn-preferences-req-pref-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Engine;

use OCA\Hermiq\Service\Engine\OutboundToolRule;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Hermiq\Service\Engine\OutboundToolRule
 */
class OutboundToolRuleTest extends TestCase {

	/**
	 * Sending, replying and posting tools are outbound; reading mail is not.
	 *
	 * @return void
	 */
	public function testSendingToolsAreOutboundAndReadsAreNot(): void {
		foreach (['hermiq.sendMail', 'mail.replyMessage', 'spreed.postMessage', 'pipelinq.client.notifyContact', 'hermiq.shareFile'] as $id) {
			self::assertTrue(OutboundToolRule::isOutbound(toolId: $id), $id);
		}

		foreach (['hermiq.listMailMessages', 'hermiq.readMailMessage', 'hermiq.listMailAccounts', 'hermiq.createNote', 'hermiq.workspacePush', 'openregister.runFlow', 'pipelinq.mailing.create', ''] as $id) {
			self::assertFalse(OutboundToolRule::isOutbound(toolId: $id), $id);
		}
	}//end testSendingToolsAreOutboundAndReadsAreNot()

	/**
	 * The outbound ids among a turn's tools, each once.
	 *
	 * @return void
	 */
	public function testOutboundAmongKeepsEachOnce(): void {
		self::assertSame(
			['hermiq.sendMail'],
			OutboundToolRule::outboundAmong(toolIds: ['hermiq_sendMail' => 'hermiq.sendMail', 'x' => 'hermiq.sendMail', 'y' => 'hermiq.readFile'])
		);
	}//end testOutboundAmongKeepsEachOnce()
}//end class
