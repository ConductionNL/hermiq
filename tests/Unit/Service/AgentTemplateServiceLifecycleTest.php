<?php

/**
 * Unit tests for AgentTemplateService's list/update/export paths, the
 * model-policy fallbacks and the skill-reference resolution edges.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/agent-template-gallery/spec.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\AgentTemplateSerializer;
use OCA\Hermiq\Service\AgentTemplateService;
use OCA\Hermiq\Service\EffectiveModelPolicy;
use OCA\Hermiq\Service\SkillService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ContentScanService;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the AgentTemplateService paths the gallery tests leave open.
 *
 * @spec openspec/specs/agent-template-gallery/spec.md
 */
#[CoversClass(AgentTemplateService::class)]
#[UsesClass(AgentTemplateSerializer::class)]
class AgentTemplateServiceLifecycleTest extends TestCase {

	/**
	 * Mock ObjectService.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService $objectService;

	/**
	 * Mock content scanner.
	 *
	 * @var ContentScanService&MockObject
	 */
	private ContentScanService $scanner;

	/**
	 * Mock model policy.
	 *
	 * @var EffectiveModelPolicy&MockObject
	 */
	private EffectiveModelPolicy $policy;

	/**
	 * Mock skill service.
	 *
	 * @var SkillService&MockObject
	 */
	private SkillService $skills;

	/**
	 * Wire the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objectService = $this->createMock(ObjectService::class);
		$this->scanner = $this->createMock(ContentScanService::class);
		$this->policy = $this->createMock(EffectiveModelPolicy::class);
		$this->skills = $this->createMock(SkillService::class);
	}//end setUp()

	/**
	 * The service under test.
	 *
	 * @return AgentTemplateService
	 */
	private function service(): AgentTemplateService {
		return new AgentTemplateService(
			$this->objectService,
			new AgentTemplateSerializer(),
			$this->scanner,
			$this->policy,
			$this->skills
		);
	}//end service()

	/**
	 * An object entity with the given payload.
	 *
	 * @param string $uuid The uuid.
	 * @param array<string, mixed> $data The payload.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $uuid, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($data);
		return $entity;
	}//end entity()

	/**
	 * Make saveObject() echo its payload back and record each call.
	 *
	 * @param array<int, array<string, mixed>> $calls Captured calls (by reference).
	 *
	 * @return void
	 */
	private function captureSaves(array &$calls): void {
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ?array $extend = [], mixed $register = null, mixed $schema = null, ?string $uuid = null) use (&$calls): ObjectEntity {
				$payload = is_array($object) ? $object : $object->getObject();
				$calls[] = ['object' => $payload, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid];
				return $this->entity(($uuid ?? 'new-' . count($calls)), $payload);
			}
		);
	}//end captureSaves()

	/**
	 * list() reads the template schema and keeps only ObjectEntity rows.
	 *
	 * @return void
	 */
	public function testListKeepsOnlyObjectEntities(): void {
		$one = $this->entity('t-1', ['name' => 'One']);
		$this->objectService->expects($this->once())->method('setRegister')->with('hermiq')->willReturnSelf();
		$this->objectService->expects($this->once())->method('setSchema')->with('agenttemplate')->willReturnSelf();
		$this->objectService->expects($this->once())->method('findAll')
			->with(['limit' => 200])
			->willReturn([$one, ['name' => 'raw array'], 'junk']);

		$this->assertSame([$one], $this->service()->list());
	}//end testListKeepsOnlyObjectEntities()

	/**
	 * update() returns null for an unknown template and never writes.
	 *
	 * @return void
	 */
	public function testUpdateUnknownTemplateReturnsNull(): void {
		$this->objectService->method('find')->willReturn(null);
		$this->objectService->expects($this->never())->method('saveObject');

		$this->assertNull($this->service()->update('missing', ['name' => 'x']));
	}//end testUpdateUnknownTemplateReturnsNull()

	/**
	 * update() merges the payload over the stored template, strips lifecycle keys and
	 * writes back under the same uuid.
	 *
	 * @return void
	 */
	public function testUpdateMergesAndStripsProtectedKeys(): void {
		$this->objectService->method('find')->willReturn(
			$this->entity('t-9', ['name' => 'Old', 'description' => 'Keep', 'state' => 'quarantined', 'source' => 'hub'])
		);
		$calls = [];
		$this->captureSaves($calls);

		$result = $this->service()->update('t-9', ['name' => 'New', 'state' => 'active', 'source' => 'local', 'owner' => 'eve']);

		$this->assertInstanceOf(ObjectEntity::class, $result);
		$this->assertCount(1, $calls);
		$this->assertSame('t-9', $calls[0]['uuid']);
		$this->assertSame('agenttemplate', $calls[0]['schema']);
		$this->assertSame('New', $calls[0]['object']['name']);
		$this->assertSame('Keep', $calls[0]['object']['description']);
		// Lifecycle fields survive from the stored template, not from the caller.
		$this->assertSame('quarantined', $calls[0]['object']['state']);
		$this->assertSame('hub', $calls[0]['object']['source']);
		$this->assertArrayNotHasKey('owner', $calls[0]['object']);
	}//end testUpdateMergesAndStripsProtectedKeys()

	/**
	 * exportTemplate() returns null for an unknown template and a JSON package otherwise.
	 *
	 * @return void
	 */
	public function testExportTemplate(): void {
		$this->objectService->method('find')->willReturnCallback(
			fn (int|string $id): ?ObjectEntity => ($id === 'known')
				? $this->entity('known', ['name' => 'Exported', 'systemPrompt' => 'Be kind'])
				: null
		);

		$service = $this->service();
		$this->assertNull($service->exportTemplate('nope'));

		$package = $service->exportTemplate('known');
		$this->assertIsString($package);
		$decoded = json_decode($package, true);
		$this->assertSame('Exported', $decoded['name']);
		$this->assertSame('Be kind', $decoded['systemPrompt']);
	}//end testExportTemplate()

	/**
	 * A nameless local import gets the fallback name, keeps its schedule hint and
	 * records the agent it was derived from.
	 *
	 * @return void
	 */
	public function testImportLocalNamelessWithScheduleAndDerivedFrom(): void {
		$calls = [];
		$this->captureSaves($calls);
		$this->scanner->expects($this->never())->method('scan');

		$package = json_encode([
			'name' => '',
			'systemPrompt' => 'p',
			'suggestedSchedule' => ['kind' => 'cron', 'cronExpr' => '0 9 * * 1'],
		]);
		$this->service()->importPackage((string)$package, 'local', 'alice', 'agent-42');

		$this->assertCount(1, $calls);
		$saved = $calls[0]['object'];
		$this->assertSame('Untitled template', $saved['name']);
		$this->assertSame('active', $saved['state']);
		$this->assertSame('agent-42', $saved['derivedFrom']);
		$this->assertSame('0 9 * * 1', $saved['suggestedSchedule']['cronExpr']);
	}//end testImportLocalNamelessWithScheduleAndDerivedFrom()

	/**
	 * A suspicious scan verdict produces the "suspicious pattern(s)" quarantine reason.
	 *
	 * @return void
	 */
	public function testImportSuspiciousScanReason(): void {
		$calls = [];
		$this->captureSaves($calls);
		$this->scanner->method('scan')->willReturn([
			'severity' => ContentScanService::SEVERITY_SUSPICIOUS,
			'findings' => [['a'], ['b']],
		]);

		$this->service()->importPackage((string)json_encode(['name' => 'S', 'systemPrompt' => 'x']), 'org', 'bob');

		$saved = $calls[0]['object'];
		$this->assertSame('quarantined', $saved['state']);
		$this->assertSame(
			'Imported from org; content scan flagged 2 suspicious pattern(s) — review before activation.',
			$saved['quarantineReason']
		);
	}//end testImportSuspiciousScanReason()

	/**
	 * approveQuarantined() returns null for an unknown template and the template
	 * unchanged (no write, no scan) when it is not quarantined.
	 *
	 * @return void
	 */
	public function testApproveUnknownAndNotQuarantined(): void {
		$active = $this->entity('a-1', ['state' => 'active']);
		$this->objectService->method('find')->willReturnCallback(
			fn (int|string $id): ?ObjectEntity => ($id === 'a-1') ? $active : null
		);
		$this->objectService->expects($this->never())->method('saveObject');
		$this->scanner->expects($this->never())->method('scan');

		$service = $this->service();
		$this->assertNull($service->approveQuarantined('missing'));
		$this->assertSame($active, $service->approveQuarantined('a-1'));
	}//end testApproveUnknownAndNotQuarantined()

	/**
	 * With no default model pinned, an out-of-policy suggestion falls back to the first
	 * allowed provider with an empty model, and the substitution is reported.
	 *
	 * @return void
	 */
	public function testInstantiateFallsBackToFirstAllowedProvider(): void {
		$this->objectService->method('find')->willReturn(
			$this->entity('t-1', ['name' => 'T', 'suggestedProvider' => 'rogue', 'suggestedModel' => 'm1', 'skillRefs' => 'not-a-list'])
		);
		$this->policy->method('effectivePolicyFor')->with('org-1')->willReturn([
			'defaultModel' => null,
			'allowed' => [['provider' => 'mistral', 'model' => 'small']],
		]);
		$this->policy->expects($this->once())->method('isAllowed')
			->with('org-1', 'rogue', 'm1')->willReturn(false);
		$calls = [];
		$this->captureSaves($calls);

		$result = $this->service()->instantiate('t-1', 'org-1');

		$this->assertSame('mistral', $result['resolvedProvider']);
		$this->assertSame('', $result['resolvedModel']);
		$this->assertTrue($result['modelCoerced']);
		$this->assertSame([], $result['unresolvedSkillRefs']);
		$this->assertSame('agent', $calls[0]['schema']);
		$this->assertSame('mistral', $calls[0]['object']['provider']);
	}//end testInstantiateFallsBackToFirstAllowedProvider()

	/**
	 * Skill refs: non-array entries are skipped, an empty id and a non-active skill are
	 * reported unresolved, and only the active skill is installed.
	 *
	 * @return void
	 */
	public function testInstantiateResolvesSkillRefsBestEffort(): void {
		$this->objectService->method('find')->willReturn(
			$this->entity('t-2', [
				'name' => 'T',
				'skillRefs' => [
					'garbage',
					['skillId' => '', 'name' => 'Nameless'],
					['skillId' => 'draft-skill', 'name' => 'Draft'],
					['skillId' => 'live-skill', 'name' => 'Live'],
				],
			])
		);
		$this->policy->method('effectivePolicyFor')->willReturn(['defaultModel' => null, 'allowed' => []]);
		$this->skills->method('getSkill')->willReturnCallback(
			fn (string $skillId): ObjectEntity => $this->entity($skillId, ['state' => ($skillId === 'live-skill') ? 'active' : 'draft'])
		);
		$this->skills->expects($this->once())->method('installOnAgent')->with('live-skill', 'new-1');
		$calls = [];
		$this->captureSaves($calls);

		$result = $this->service()->instantiate('t-2', '');

		$this->assertSame(
			[
				['skillId' => '', 'name' => 'Nameless'],
				['skillId' => 'draft-skill', 'name' => 'Draft'],
			],
			$result['unresolvedSkillRefs']
		);
		// No policy allows anything: the agent is left inert, not wired to a guess.
		$this->assertSame('', $result['resolvedProvider']);
		$this->assertFalse($result['modelCoerced']);
	}//end testInstantiateResolvesSkillRefsBestEffort()
	/**
	 * exportFromAgent() builds skillRefs from the agent's installs: non-string and empty
	 * ids are skipped, a known skill contributes its name, an unknown one an empty name.
	 *
	 * @return void
	 */
	public function testExportFromAgentBuildsSkillRefsFromInstalls(): void {
		$this->objectService->method('find')->willReturn(
			$this->entity('agent-1', [
				'name' => 'Helper',
				'prompt' => 'Help',
				'skillInstalls' => [7, '', 'known', 'gone'],
			])
		);
		$this->skills->method('getSkill')->willReturnCallback(
			fn (string $skillId): ?ObjectEntity => ($skillId === 'known') ? $this->entity('known', ['name' => 'Known skill']) : null
		);

		$decoded = json_decode((string)$this->service()->exportFromAgent('agent-1'), true);

		$this->assertSame(
			[
				['skillId' => 'known', 'name' => 'Known skill'],
				['skillId' => 'gone', 'name' => ''],
			],
			$decoded['skillRefs']
		);
		$this->assertSame('Help', $decoded['systemPrompt']);
	}//end testExportFromAgentBuildsSkillRefsFromInstalls()

	/**
	 * An agent whose skillInstalls is not a list exports no skillRefs.
	 *
	 * @return void
	 */
	public function testExportFromAgentWithMalformedInstalls(): void {
		$this->objectService->method('find')->willReturn(
			$this->entity('agent-2', ['name' => 'X', 'skillInstalls' => 'oops'])
		);
		$this->skills->expects($this->never())->method('getSkill');

		$decoded = json_decode((string)$this->service()->exportFromAgent('agent-2'), true);

		$this->assertSame([], $decoded['skillRefs']);
	}//end testExportFromAgentWithMalformedInstalls()
}//end class
