<?php

/**
 * Unit tests for TalkSafeText.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Talk
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/human-approval-gate/spec.md#requirement-the-talk-request-names-the-tool-and-its-reach-req-apprev-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Talk;

use OCA\Hermiq\Service\Talk\TalkApprovalNotifier;
use OCA\Hermiq\Service\Talk\TalkSafeText;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Only plain text reaches a Talk message.
 *
 * @covers \OCA\Hermiq\Service\Talk\TalkSafeText
 * @covers \OCA\Hermiq\Service\Talk\TalkApprovalNotifier
 */
class TalkSafeTextTest extends TestCase {

	/**
	 * Ids: registry ids pass; mention, link and markdown characters do not.
	 *
	 * @return void
	 */
	public function testThePlainIdPattern(): void {
		$safe = new TalkSafeText();
		foreach (['files.deleteFile', 'a', 'x_y-z.9', str_repeat('a', 160)] as $id) {
			$this->assertSame($id, $safe->identifier(identifier: $id));
		}

		foreach ([str_repeat('a', 161), '', '.files', '-x', '@all', 'a@b', 'a:b', 'a/b', 'a*b', 'a`b', 'a b', "a\nb", "ab\n", 'é'] as $id) {
			$this->assertNull($safe->identifier(identifier: $id), 'Should refuse: ' . $id);
		}
	}//end testThePlainIdPattern()

	/**
	 * Labels: whitespace collapses; a mention, link, markdown or dot does not pass.
	 *
	 * @return void
	 */
	public function testThePlainLabelPattern(): void {
		$safe = new TalkSafeText();
		$this->assertSame('Close the case', $safe->label(text: "  Close\nthe case\u{200B} "));
		$this->assertSame(str_repeat('a', 80), $safe->label(text: str_repeat('a', 80)));

		foreach ([str_repeat('a', 81), '', '   ', '@all', 'Approve @all', '[x](https://e.example)', '**x**', 'Visit e.example', '`x`'] as $text) {
			$this->assertNull($safe->label(text: $text), 'Should refuse: ' . $text);
		}
	}//end testThePlainLabelPattern()

	/**
	 * A plain name is quoted, an unsafe one becomes the fallback.
	 *
	 * @return void
	 */
	public function testQuotedUsesTheFallback(): void {
		$safe = new TalkSafeText();

		$this->assertSame('“Weekly digest”', $safe->quoted(name: 'Weekly digest', fallback: 'an action'));
		$this->assertSame('“files.deleteFile”', $safe->quoted(name: 'files.deleteFile', fallback: 'an action'));
		$this->assertSame('an action', $safe->quoted(name: 'Ping @all now', fallback: 'an action'));
	}//end testQuotedUsesTheFallback()

	/**
	 * The reaction request in the agent's room names only a plain name.
	 *
	 * @return void
	 */
	public function testTheReactionRequestIsPlainText(): void {
		$class = new ReflectionClass(TalkApprovalNotifier::class);
		$notifier = $class->newInstanceWithoutConstructor();
		$text = $class->getMethod('requestText');

		$this->assertStringStartsWith('⏸️ **Approval needed** for “Weekly digest”.', $text->invoke($notifier, 'Weekly digest'));
		$this->assertStringStartsWith('⏸️ **Approval needed** for an action.', $text->invoke($notifier, '@all **now**'));
	}//end testTheReactionRequestIsPlainText()
}//end class
