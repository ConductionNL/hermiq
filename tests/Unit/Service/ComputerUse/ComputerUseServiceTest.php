<?php

/**
 * Every computer tool call needs the computer-use AI feature, a registered machine
 * that is switched on, and well-formed arguments before anything reaches integriq.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/tools-browser-and-computer-use/specs/computer-use/spec.md#requirement-computer-use-is-a-high-risk-ai-feature-that-a-grant-alone-cannot-unlock-req-computer-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\ComputerUse;

use OCA\Hermiq\Mcp\ComputerToolDescriptors;
use OCA\Hermiq\Service\AiFeature\AiFeatureLookup;
use OCA\Hermiq\Service\ComputerUse\ComputerTargetRefusal;
use OCA\Hermiq\Service\ComputerUse\ComputerTargetService;
use OCA\Hermiq\Service\ComputerUse\ComputerUseService;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Hermiq\Service\ComputerUse\ComputerUseService
 * @uses \OCA\Hermiq\Service\ComputerUse\ComputerTargetRefusal
 */
final class ComputerUseServiceTest extends TestCase {

	/**
	 * The AI feature register.
	 *
	 * @var AiFeatureLookup&MockObject
	 */
	private AiFeatureLookup&MockObject $features;

	/**
	 * The registered machines.
	 *
	 * @var ComputerTargetService&MockObject
	 */
	private ComputerTargetService&MockObject $targets;

	/**
	 * A screenshot the machine answers with.
	 *
	 * @var array<string, mixed>
	 */
	private array $shot;

	protected function setUp(): void {
		$this->features = $this->createMock(AiFeatureLookup::class);
		$this->targets  = $this->createMock(ComputerTargetService::class);
		$this->targets->method('list')->willReturn(
			[
				['id' => 'ct-1', 'name' => 'Werkplek Burgerzaken 03', 'enabled' => true],
				['id' => 'ct-2', 'name' => 'Werkplek Archief', 'enabled' => false],
			]
		);
		$this->shot = ['image' => base64_encode('PNG'), 'mimeType' => 'image/png', 'width' => 1920, 'height' => 1080];
	}

	/**
	 * The service, with the feature on or off.
	 *
	 * @param bool|null $enabled True or false for a feature in that state, null for none.
	 *
	 * @return ComputerUseService
	 */
	private function service(?bool $enabled=true): ComputerUseService {
		$feature = null;
		if ($enabled !== null) {
			$feature = new ObjectEntity();
			$feature->setObject(['slug' => 'computer-use', 'enabled' => $enabled]);
		}

		$this->features->method('findBySlugForGate')->with(slug: 'computer-use')->willReturn($feature);

		return new ComputerUseService($this->features, $this->targets, $this->createMock(LoggerInterface::class));
	}

	public function testNothingReachesTheMachineWhileTheFeatureIsNotEnabled(): void {
		$this->targets->expects(self::never())->method('screenshot');
		$this->targets->expects(self::never())->method('act');

		foreach ([false, null] as $state) {
			$this->features = $this->createMock(AiFeatureLookup::class);
			$result = $this->service(enabled: $state)->invoke(uid: 'alice', toolId: ComputerToolDescriptors::CLICK, arguments: ['target' => 'ct-1', 'x' => 1, 'y' => 2]);
			self::assertSame('computer_use_not_enabled', $result['error']['code']);
			self::assertStringContainsString('A tool grant alone does not authorise it.', $result['error']['message']);
		}
	}

	public function testAFailingFeatureLookupRefuses(): void {
		$this->features->method('findBySlugForGate')->willThrowException(new RuntimeException('register down'));
		$this->targets->expects(self::never())->method('screenshot');

		$service = new ComputerUseService($this->features, $this->targets, $this->createMock(LoggerInterface::class));
		$result  = $service->invoke(uid: 'alice', toolId: ComputerToolDescriptors::SCREENSHOT, arguments: ['target' => 'ct-1']);

		self::assertSame('computer_use_not_enabled', $result['error']['code']);
	}

	public function testAScreenshotNamesTheMachineByNameAndReturnsTheImage(): void {
		$this->targets->expects(self::once())->method('screenshot')->with(id: 'ct-1')->willReturn($this->shot);

		$result = $this->service()->invoke(uid: 'alice', toolId: ComputerToolDescriptors::SCREENSHOT, arguments: ['target' => 'werkplek burgerzaken 03']);

		self::assertSame(['target' => 'Werkplek Burgerzaken 03', 'targetId' => 'ct-1'] + $this->shot, $result);
	}

	public function testEachActionSendsExactlyWhatItAsks(): void {
		$cases = [
			[ComputerToolDescriptors::CLICK, ['target' => 'ct-1', 'x' => 10, 'y' => 20], ['type' => 'click', 'x' => 10, 'y' => 20]],
			[ComputerToolDescriptors::CLICK, ['target' => 'ct-1', 'x' => 0, 'y' => 5, 'kind' => 'double_click'], ['type' => 'double_click', 'x' => 0, 'y' => 5]],
			[ComputerToolDescriptors::TYPE, ['target' => 'ct-1', 'text' => 'Jansen'], ['type' => 'type', 'text' => 'Jansen']],
			[ComputerToolDescriptors::KEY, ['target' => 'ct-1', 'keys' => 'ctrl+s'], ['type' => 'key', 'keys' => 'ctrl+s']],
		];
		$sent = [];
		$this->targets->method('act')->willReturnCallback(
			function (string $id, array $action) use (&$sent): array {
				$sent[] = [$id, $action];
				return $this->shot;
			}
		);
		$service = $this->service();

		foreach ($cases as [$tool, $arguments]) {
			self::assertSame('ct-1', $service->invoke(uid: 'alice', toolId: $tool, arguments: $arguments)['targetId']);
		}

		self::assertSame(array_map(static fn (array $case): array => ['ct-1', $case[2]], $cases), $sent);
	}

	public function testMalformedArgumentsAreRefusedBeforeTheMachine(): void {
		$this->targets->expects(self::never())->method('act');
		$service = $this->service();

		$refused = [
			[ComputerToolDescriptors::CLICK, ['target' => 'ct-1', 'x' => '10', 'y' => 20]],
			[ComputerToolDescriptors::CLICK, ['target' => 'ct-1', 'x' => -1, 'y' => 20]],
			[ComputerToolDescriptors::CLICK, ['target' => 'ct-1', 'x' => 1, 'y' => 2, 'kind' => 'drag']],
			[ComputerToolDescriptors::TYPE, ['target' => 'ct-1', 'text' => '  ']],
			[ComputerToolDescriptors::KEY, ['target' => 'ct-1']],
			['hermiq.computerDrag', ['target' => 'ct-1']],
		];
		foreach ($refused as [$tool, $arguments]) {
			self::assertSame('invalid_arguments', $service->invoke(uid: 'alice', toolId: $tool, arguments: $arguments)['error']['code'], $tool);
		}
	}

	public function testAnUnknownOrSwitchedOffMachineIsRefused(): void {
		$this->targets->expects(self::never())->method('screenshot');
		$service = $this->service();

		$unknown = $service->invoke(uid: 'alice', toolId: ComputerToolDescriptors::SCREENSHOT, arguments: ['target' => 'Werkplek 99']);
		self::assertSame(['error' => ['code' => 'computer_refused', 'message' => 'There is no registered machine "Werkplek 99".']], $unknown);

		$off = $service->invoke(uid: 'alice', toolId: ComputerToolDescriptors::SCREENSHOT, arguments: ['target' => 'ct-2']);
		self::assertSame(['error' => ['code' => 'computer_refused', 'message' => 'The machine "Werkplek Archief" is switched off.']], $off);
	}

	public function testAMachineThatDoesNotAnswerIsAToolError(): void {
		$this->targets->method('screenshot')->willThrowException(new ComputerTargetRefusal(message: 'The machine did not answer with a screenshot.', status: 502));

		$result = $this->service()->invoke(uid: 'alice', toolId: ComputerToolDescriptors::SCREENSHOT, arguments: ['target' => 'ct-1']);

		self::assertSame(['error' => ['code' => 'computer_refused', 'message' => 'The machine did not answer with a screenshot.']], $result);
	}
}
