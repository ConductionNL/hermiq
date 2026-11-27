<?php

/**
 * Unit tests for ApprovalVerdictService when the acting agent cannot be looked
 * up and when the stored decision time cannot be read.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Approval
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Approval;

use OCA\Hermiq\Service\Approval\ApprovalVerdictService;
use OCA\Hermiq\Service\Approval\ApprovalVerdictSigner;
use OCA\Hermiq\Service\ApprovalService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * An agent that cannot be looked up is judged by its own id only, and an unreadable decision time is expired.
 */
#[CoversClass(ApprovalVerdictService::class)]
#[UsesClass(ApprovalVerdictSigner::class)]
final class ApprovalVerdictServiceEdgeTest extends TestCase {

	private const BINDING = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';

	private const NOW = 1790762700;

	private ObjectService&MockObject $objects;

	/**
	 * The signer's stored config values.
	 *
	 * @var array<string, string>
	 */
	private array $values = [];

	protected function setUp(): void {
		$this->objects = $this->createMock(ObjectService::class);
	}//end setUp()

	/**
	 * The service with approval a1 holding $approval.
	 *
	 * @param array<string, mixed> $approval The stored approval.
	 *
	 * @return ApprovalVerdictService
	 */
	private function service(array $approval): ApprovalVerdictService {
		$entity = new ObjectEntity();
		$entity->setUuid('a1');
		$entity->setObject($approval);
		$approvals = $this->createMock(ApprovalService::class);
		$approvals->method('loadApproval')->with('a1')->willReturn($entity);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->values[$key] ?? $default)
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->values[$key] = $value;
				return true;
			}
		);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);

		return new ApprovalVerdictService(
			approvals: $approvals,
			objectService: $this->objects,
			signer: new ApprovalVerdictSigner(appConfig: $config),
			timeFactory: $time
		);
	}//end service()

	/**
	 * A decided toolcall approval for agent g1.
	 *
	 * @param array<string, mixed> $override Fields to change.
	 *
	 * @return array<string, mixed>
	 */
	private function approval(array $override = []): array {
		return array_merge(
			[
				'status' => 'approved',
				'sourceType' => 'toolcall',
				'agentId' => 'g1',
				'toolId' => 'integriq.replayDeadLetters',
				'binding' => self::BINDING,
				'decidedBy' => 'anna',
				'decidedAt' => '2026-09-30T10:00:00+00:00',
			],
			$override
		);
	}//end approval()

	/**
	 * The verify request for approval a1 by agent g1.
	 *
	 * @return array<string, string>
	 */
	private function request(): array {
		return [
			'approvalId' => 'a1',
			'toolId' => 'integriq.replayDeadLetters',
			'binding' => self::BINDING,
			'actingAgent' => 'g1',
			'nonce' => 'fedcba9876543210fedcba9876543210',
		];
	}//end request()

	public function testAnAgentLookupFailureStillApprovesAHumanDecision(): void {
		$this->objects->expects($this->once())
			->method('find')
			->willThrowException(new RuntimeException('register offline'));

		$verdict = $this->service($this->approval())->verify($this->request())['verdict'];

		$this->assertTrue($verdict['approved']);
		$this->assertSame('approved', $verdict['reason']);
		$this->assertSame('anna', $verdict['decidedBy']);
	}//end testAnAgentLookupFailureStillApprovesAHumanDecision()

	public function testAnAgentLookupFailureStillRefusesTheAgentApprovingItself(): void {
		$this->objects->method('find')->willThrowException(new RuntimeException('register offline'));

		$verdict = $this->service($this->approval(['decidedBy' => 'g1']))->verify($this->request())['verdict'];

		$this->assertFalse($verdict['approved']);
		$this->assertSame('approver-is-agent', $verdict['reason']);
	}//end testAnAgentLookupFailureStillRefusesTheAgentApprovingItself()

	public function testAMissingAgentObjectIsJudgedByItsIdOnly(): void {
		$this->objects->expects($this->once())->method('find')->willReturn(null);

		$verdict = $this->service($this->approval())->verify($this->request())['verdict'];

		$this->assertSame('approved', $verdict['reason']);
	}//end testAMissingAgentObjectIsJudgedByItsIdOnly()

	public function testAnUnreadableDecisionTimeIsExpiredWithNoTimestamps(): void {
		$this->objects->expects($this->never())->method('find');

		$verdict = $this->service($this->approval(['decidedAt' => 'yesterday-ish o\'clock']))->verify($this->request())['verdict'];

		$this->assertFalse($verdict['approved']);
		$this->assertSame('expired', $verdict['reason']);
		$this->assertNull($verdict['decidedAt']);
		$this->assertNull($verdict['expiresAt']);
		$this->assertSame('anna', $verdict['decidedBy']);
	}//end testAnUnreadableDecisionTimeIsExpiredWithNoTimestamps()
}//end class
