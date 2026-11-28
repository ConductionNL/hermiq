<?php

/**
 * Tests for OpenRegisterAttachmentTextSource: OpenRegister's FileTextFacade found
 * once behind a class probe, asked as the person, and never allowed to throw.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Chat
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/chat-attachments/spec.md#requirement-a-model-without-the-capability-gets-the-text-and-the-person-is-told-req-catt-005
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Chat;

use OCA\Hermiq\Service\Chat\OpenRegisterAttachmentTextSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A stand-in for OpenRegister's FileTextFacade.
 */
class FileTextFacadeStub {

	/**
	 * The calls made, as [fileId, userId].
	 *
	 * @var list<array{0: int, 1: string}>
	 */
	public array $calls = [];

	/**
	 * Constructor.
	 *
	 * @param bool                   $available What isAvailable() answers.
	 * @param string|RuntimeException|null $text What extractText() answers or throws.
	 */
	public function __construct(
		public bool $available = true,
		public string|RuntimeException|null $text = 'Offerte 2026',
	) {
	}//end __construct()

	/**
	 * Whether the facade can answer.
	 *
	 * @return bool
	 */
	public function isAvailable(): bool {
		return $this->available;
	}//end isAvailable()

	/**
	 * The text, or a throw.
	 *
	 * @param int    $fileId The file id.
	 * @param string $userId The uid.
	 *
	 * @return string|null
	 */
	public function extractText(int $fileId, string $userId): ?string {
		$this->calls[] = [$fileId, $userId];
		if ($this->text instanceof RuntimeException) {
			throw $this->text;
		}

		return $this->text;
	}//end extractText()
}//end class

/**
 * Resolution once, the ways it yields nothing, and the person passed through.
 */
#[CoversClass(OpenRegisterAttachmentTextSource::class)]
final class OpenRegisterAttachmentTextSourceTest extends TestCase {

	/**
	 * A source whose container hands out this facade.
	 *
	 * @param object|null $facade The facade, or null for a container that throws.
	 * @param string      $class  The facade class name.
	 *
	 * @return OpenRegisterAttachmentTextSource
	 */
	private function source(?object $facade, string $class = FileTextFacadeStub::class): OpenRegisterAttachmentTextSource {
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		if ($facade === null) {
			$container->method('get')->willThrowException(new RuntimeException(message: 'no service'));
		} else {
			$container->method('get')->with($class)->willReturn($facade);
		}

		return new OpenRegisterAttachmentTextSource(
			container: $container,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			facadeClass: $class
		);
	}//end source()

	/**
	 * The facade's text comes back, asked for the person's own file.
	 *
	 * @return void
	 */
	public function testTheTextIsReadAsThePerson(): void {
		$facade = new FileTextFacadeStub();
		$source = $this->source(facade: $facade);

		$this->assertTrue(condition: $source->isAvailable());
		$this->assertSame(expected: 'Offerte 2026', actual: $source->extractText(fileId: 42, userId: 'alice'));
		$this->assertSame(expected: [[42, 'alice']], actual: $facade->calls);
	}//end testTheTextIsReadAsThePerson()

	/**
	 * The container is asked once, however often the source is used.
	 *
	 * @return void
	 */
	public function testTheFacadeIsResolvedOnce(): void {
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->expects($this->once())->method('get')->willReturn(new FileTextFacadeStub());
		$source = new OpenRegisterAttachmentTextSource(
			container: $container,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			facadeClass: FileTextFacadeStub::class
		);

		$source->extractText(fileId: 1, userId: 'alice');
		$source->extractText(fileId: 2, userId: 'alice');
		$this->assertTrue(condition: $source->isAvailable());
	}//end testTheFacadeIsResolvedOnce()

	/**
	 * An OpenRegister without the facade class leaves the source unavailable.
	 *
	 * @return void
	 */
	public function testWithoutTheFacadeClassTheSourceIsUnavailable(): void {
		$source = $this->source(facade: new FileTextFacadeStub(), class: 'OCA\\OpenRegister\\Files\\Service\\NoSuchFacade');

		$this->assertFalse(condition: $source->isAvailable());
		$this->assertNull(actual: $source->extractText(fileId: 42, userId: 'alice'));
	}//end testWithoutTheFacadeClassTheSourceIsUnavailable()

	/**
	 * A container that cannot build the facade leaves the source unavailable.
	 *
	 * @return void
	 */
	public function testAFacadeThatCannotBeResolvedLeavesTheSourceUnavailable(): void {
		$source = $this->source(facade: null);

		$this->assertFalse(condition: $source->isAvailable());
		$this->assertNull(actual: $source->extractText(fileId: 42, userId: 'alice'));
	}//end testAFacadeThatCannotBeResolvedLeavesTheSourceUnavailable()

	/**
	 * A facade that says it cannot answer is not asked for text.
	 *
	 * @return void
	 */
	public function testAFacadeThatIsNotAvailableIsNotAsked(): void {
		$facade = new FileTextFacadeStub(available: false);
		$source = $this->source(facade: $facade);

		$this->assertFalse(condition: $source->isAvailable());
		$this->assertNull(actual: $source->extractText(fileId: 42, userId: 'alice'));
		$this->assertSame(expected: [], actual: $facade->calls);
	}//end testAFacadeThatIsNotAvailableIsNotAsked()

	/**
	 * A facade that throws, or gives no or empty text, yields null.
	 *
	 * @return void
	 */
	public function testAFailingOrEmptyFacadeYieldsNull(): void {
		$throwing = new FileTextFacadeStub(text: new RuntimeException(message: 'broken'));
		$this->assertNull(actual: $this->source(facade: $throwing)->extractText(fileId: 42, userId: 'alice'));
		$this->assertNull(actual: $this->source(facade: new FileTextFacadeStub(text: null))->extractText(fileId: 42, userId: 'alice'));
		$this->assertNull(actual: $this->source(facade: new FileTextFacadeStub(text: '   '))->extractText(fileId: 42, userId: 'alice'));
	}//end testAFailingOrEmptyFacadeYieldsNull()
}//end class
