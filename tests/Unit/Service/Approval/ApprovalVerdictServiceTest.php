<?php

/**
 * The verdict Hermiq signs for a toolcall approval (approval-verification-contract).
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Approval
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/human-approval-gate/spec.md#requirement-hermiq-answers-a-signed-verdict-on-a-toolcall-approval-req-apver-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Approval;

use InvalidArgumentException;
use OCA\Hermiq\Service\Approval\ApprovalVerdictService;
use OCA\Hermiq\Service\Approval\ApprovalVerdictSigner;
use OCA\Hermiq\Service\ApprovalService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Tests every reason the verdict can carry, and its signature.
 *
 * @spec openspec/specs/human-approval-gate/spec.md#requirement-hermiq-answers-a-signed-verdict-on-a-toolcall-approval-req-apver-001
 */
class ApprovalVerdictServiceTest extends TestCase {

	private const BINDING = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

	/**
	 * "Now": 2026-09-30T10:05:00Z.
	 */
	private const NOW = 1790762700;

	/**
	 * App values written by the signer.
	 *
	 * @var array<string, string>
	 */
	private array $values = [];

	/**
	 * The service under test, given one approval and one agent.
	 *
	 * @param array<string, mixed>|null $approval The approval payload, or null for none.
	 * @param array<string, mixed>      $agent    The agent payload.
	 *
	 * @return ApprovalVerdictService
	 */
	private function service(?array $approval, array $agent = ['actingUser' => 'robot']): ApprovalVerdictService {
		$approvals = $this->createMock(ApprovalService::class);
		$entity    = null;
		if ($approval !== null) {
			$entity = new ObjectEntity();
			$entity->setUuid('a1');
			$entity->setObject($approval);
		}

		$approvals->method('loadApproval')->willReturnCallback(fn (string $uuid): ?ObjectEntity => ($uuid === 'a1' ? $entity : null));

		$agentEntity = new ObjectEntity();
		$agentEntity->setUuid('g1');
		$agentEntity->setObject($agent);
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturn($agentEntity);

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
			objectService: $objects,
			signer: new ApprovalVerdictSigner(appConfig: $config),
			timeFactory: $time
		);

	}//end service()

	/**
	 * An approved toolcall approval for integriq.replayDeadLetters, agent g1.
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
	 * The request integriq sends.
	 *
	 * @param array<string, mixed> $override Fields to change.
	 *
	 * @return array<string, mixed>
	 */
	private function request(array $override = []): array {
		return array_merge(
			[
				'approvalId' => 'a1',
				'toolId' => 'integriq.replayDeadLetters',
				'binding' => self::BINDING,
				'actingAgent' => 'g1',
				'nonce' => '0123456789abcdef0123456789abcdef',
			],
			$override
		);

	}//end request()

	/**
	 * An approved batch: approved, echoed, signed with the published key.
	 *
	 * @return void
	 */
	public function testAnApprovedBatchIsConfirmedAndSigned(): void {
		$answer  = $this->service(approval: $this->approval())->verify(request: $this->request());
		$verdict = $answer['verdict'];

		foreach ($this->request() as $field => $value) {
			$this->assertSame($value, $verdict[$field], $field);
		}

		$this->assertTrue($verdict['approved']);
		$this->assertSame('approved', $verdict['reason']);
		$this->assertSame('anna', $verdict['decidedBy']);
		$this->assertSame('2026-09-30T10:00:00+00:00', $verdict['decidedAt']);
		$this->assertSame('2026-09-30T11:00:00+00:00', $verdict['expiresAt']);
		$this->assertSame('2026-09-30T10:05:00+00:00', $verdict['issuedAt']);

		// The check integriq runs, verbatim: canonical JSON with sorted keys.
		ksort($verdict);
		$message   = (string)json_encode($verdict, (JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
		$publicKey = (string)base64_decode($this->values[ApprovalVerdictSigner::PUBLIC_KEY], true);
		$this->assertTrue(sodium_crypto_sign_verify_detached((string)base64_decode($answer['signature'], true), $message, $publicKey));

	}//end testAnApprovedBatchIsConfirmedAndSigned()

	/**
	 * Each failing check names its reason, and never says approved.
	 *
	 * @return array<string, array{array<string, mixed>|null, array<string, mixed>, string}>
	 */
	public static function refusals(): array {
		return [
			'no such approval' => [null, ['approvalId' => 'nope'], 'unknown'],
			'not a toolcall' => [['sourceType' => 'tool'], [], 'unknown'],
			'no binding stored' => [['binding' => null], [], 'unknown'],
			'another batch' => [[], ['binding' => str_repeat('c', 64)], 'binding-mismatch'],
			'another tool' => [[], ['toolId' => 'integriq.discardDeadLetters'], 'binding-mismatch'],
			'another agent' => [[], ['actingAgent' => 'g2'], 'binding-mismatch'],
			'pending' => [['status' => 'pending', 'decidedBy' => null, 'decidedAt' => null], [], 'pending'],
			'denied' => [['status' => 'denied'], [], 'rejected'],
			'overridden' => [['status' => 'overridden'], [], 'rejected'],
			'expired' => [['decidedAt' => '2026-09-30T08:00:00+00:00'], [], 'expired'],
			'decided by the agent' => [['decidedBy' => 'g1'], [], 'approver-is-agent'],
			'decided by the agent principal' => [['decidedBy' => 'robot'], [], 'approver-is-agent'],
			'no decider' => [['decidedBy' => ''], [], 'approver-is-agent'],
		];

	}//end refusals()

	/**
	 * A refusal is still a signed 200-style answer with approved false.
	 *
	 * @param array<string, mixed>|null $approval Approval overrides, or null for none.
	 * @param array<string, mixed>      $request  Request overrides.
	 * @param string                    $reason   The expected reason.
	 *
	 * @return void
	 *
	 * @dataProvider refusals
	 */
	public function testARefusalNamesItsReason(?array $approval, array $request, string $reason): void {
		$answer = $this->service(approval: ($approval === null ? null : $this->approval(override: $approval)))
			->verify(request: $this->request(override: $request));

		$this->assertFalse($answer['verdict']['approved']);
		$this->assertSame($reason, $answer['verdict']['reason']);
		$this->assertSame($this->request(override: $request)['nonce'], $answer['verdict']['nonce']);
		$this->assertNotSame('', $answer['signature']);

	}//end testARefusalNamesItsReason()

	/**
	 * Refusals that do not match the caller's own toolcall approval.
	 *
	 * @return array<string, array{array<string, mixed>, array<string, mixed>}>
	 */
	public static function identityRefusals(): array {
		return [
			'not a toolcall' => [['sourceType' => 'schedule'], []],
			'another batch' => [[], ['binding' => str_repeat('c', 64)]],
			'another tool' => [[], ['toolId' => 'integriq.discardDeadLetters']],
			'another agent' => [[], ['actingAgent' => 'g2']],
		];

	}//end identityRefusals()

	/**
	 * The endpoint is public: an approval id alone must not reveal who decided it, or when.
	 *
	 * @param array<string, mixed> $approval Approval overrides.
	 * @param array<string, mixed> $request  Request overrides.
	 *
	 * @return void
	 *
	 * @dataProvider identityRefusals
	 */
	public function testAnIdentityRefusalRevealsNoDecision(array $approval, array $request): void {
		$verdict = $this->service(approval: $this->approval(override: $approval))
			->verify(request: $this->request(override: $request))['verdict'];

		$this->assertContains(needle: $verdict['reason'], haystack: ['unknown', 'binding-mismatch']);
		$this->assertNull(actual: $verdict['decidedBy']);
		$this->assertNull(actual: $verdict['decidedAt']);
		$this->assertNull(actual: $verdict['expiresAt']);

	}//end testAnIdentityRefusalRevealsNoDecision()

	/**
	 * The legacy `user` field is the principal when `actingUser` is unset.
	 *
	 * @return void
	 */
	public function testTheLegacyUserIsAPrincipalToo(): void {
		$answer = $this->service(approval: $this->approval(override: ['decidedBy' => 'svc']), agent: ['user' => 'svc'])
			->verify(request: $this->request());

		$this->assertSame('approver-is-agent', $answer['verdict']['reason']);

	}//end testTheLegacyUserIsAPrincipalToo()

	/**
	 * A request missing a field, or with a non-string one, cannot be echoed.
	 *
	 * @return void
	 */
	public function testAnIncompleteRequestIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);

		$request = $this->request();
		unset($request['nonce']);
		$this->service(approval: $this->approval())->verify(request: $request);

	}//end testAnIncompleteRequestIsRefused()

	/**
	 * A non-string field is refused the same way.
	 *
	 * @return void
	 */
	public function testANonStringFieldIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);

		$this->service(approval: $this->approval())->verify(request: $this->request(override: ['binding' => ['x']]));

	}//end testANonStringFieldIsRefused()
}//end class
