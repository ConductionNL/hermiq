<?php

/**
 * The check an agent draft from chat goes through before the agent form opens
 * (agents-plain-language-builder): one finding per field that will not work as
 * written, and a 422 for a draft that cannot be read.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Agent
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/agent-management-ui/spec.md#requirement-a-draft-opens-in-the-full-agent-form-after-a-check-req-agbuild-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Agent;

use OCA\Hermiq\Service\Agent\AgentDraftService;
use OCA\Hermiq\Service\Agent\AgentDraftUnreadableException;
use OCA\Hermiq\Service\TenantModelPolicyService;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Service\Mcp\ToolRegistryFacade;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;

/**
 * Tests for AgentDraftService.
 *
 * @spec openspec/specs/agent-management-ui/spec.md#requirement-a-draft-opens-in-the-full-agent-form-after-a-check-req-agbuild-002
 */
class AgentDraftServiceTest extends TestCase {

	/**
	 * The service: a catalogue of three tools, an organisation that allows only
	 * ollama/qwen2.5, and a person who is in the group legal only.
	 *
	 * @return AgentDraftService
	 */
	private function service(): AgentDraftService {
		$tools = $this->createMock(ToolRegistryFacade::class);
		$tools->method('describeTools')->willReturn(
			[
				['name' => 'openregister.searchObjects', 'app' => 'openregister'],
				['name' => 'hermiq.listFiles', 'app' => 'hermiq'],
				['name' => 'talk.postMessage', 'app' => 'spreed'],
			]
		);

		$policies = $this->createMock(TenantModelPolicyService::class);
		$policies->method('isAllowed')->willReturnCallback(
			static fn (string $organisation, string $provider, string $model): bool => $organisation === 'org-1' && $provider === 'ollama' && $model === 'qwen2.5'
		);
		$policies->method('effectivePolicyFor')->willReturn(
			['source' => 'organisation', 'allowed' => [['provider' => 'ollama', 'models' => ['qwen2.5', 'llama3.1']]], 'defaultModel' => null]
		);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(false);
		$groups->method('groupExists')->willReturnCallback(static fn (string $gid): bool => in_array($gid, ['legal', 'finance'], true));
		$groups->method('isInGroup')->willReturnCallback(static fn (string $uid, string $gid): bool => $gid === 'legal');

		$organisations = $this->createMock(OrganisationMapper::class);
		$organisations->method('getActiveOrganisationWithFallback')->willReturn('org-1');

		return new AgentDraftService(tools: $tools, policies: $policies, groups: $groups, organisations: $organisations);
	}//end service()

	/**
	 * The team lead's draft from the spec, as the builder would write it.
	 *
	 * @param array<string, mixed> $overrides Fields to change.
	 *
	 * @return string
	 */
	private function draft(array $overrides = []): string {
		return (string)json_encode(
			array_merge(
				[
					'name' => 'Objections digest',
					'description' => 'Summarises new objections every Monday.',
					'prompt' => 'Summarise the objections filed last week for the legal team.',
					'provider' => 'ollama',
					'model' => 'qwen2.5',
					'tools' => ['openregister.searchObjects', 'talk.postMessage'],
					'sharing' => ['mode' => 'groups', 'groups' => ['legal']],
					'schedule' => ['kind' => 'cron', 'cronExpr' => '0 8 * * 1', 'prompt' => 'Write this week\'s digest.'],
				],
				$overrides
			)
		);
	}//end draft()

	/**
	 * A draft that works as written comes back whole with no findings.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-management-ui/spec.md#requirement-a-draft-opens-in-the-full-agent-form-after-a-check-req-agbuild-002
	 */
	public function testAGoodDraftHasNoFindings(): void {
		$checked = $this->service()->check(text: $this->draft(), uid: 'teamlead');

		$this->assertSame([], $checked['findings']);
		$this->assertSame('Objections digest', $checked['draft']['name']);
		$this->assertSame(['mode' => 'groups', 'groups' => ['legal']], $checked['draft']['sharing']);
		$this->assertSame('0 8 * * 1', $checked['draft']['schedule']['cronExpr']);

	}//end testAGoodDraftHasNoFindings()

	/**
	 * An unknown tool, a forbidden model, an unseen group and a bad cron give four findings.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-management-ui/spec.md#requirement-a-draft-opens-in-the-full-agent-form-after-a-check-req-agbuild-002
	 */
	public function testEachProblemIsAFindingBesideItsField(): void {
		$checked = $this->service()->check(
			text: $this->draft(
				[
					'model' => 'gpt-4o',
					'provider' => 'openai',
					'tools' => ['openregister.searchObject', 'talk.postMessage'],
					'sharing' => ['mode' => 'groups', 'groups' => ['legal', 'finance']],
					'schedule' => ['kind' => 'cron', 'cronExpr' => 'every monday'],
				]
			),
			uid: 'teamlead'
		);

		$byField = [];
		foreach ($checked['findings'] as $finding) {
			$byField[$finding['field']] = $finding;
		}

		$this->assertSame(['tools', 'model', 'groups', 'schedule'], array_keys($byField));
		$this->assertSame('openregister.searchObjects', $byField['tools']['suggestion']);
		$this->assertSame('Not allowed by your organisation', $byField['model']['message']);
		$this->assertStringStartsWith('ollama/', $byField['model']['suggestion']);
		$this->assertStringContainsString('finance', $byField['groups']['message']);
		$this->assertSame('The schedule could not be read.', $byField['schedule']['message']);

	}//end testEachProblemIsAFindingBesideItsField()

	/**
	 * Text that is not a JSON object with a name is refused with 422.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-management-ui/spec.md#requirement-a-draft-opens-in-the-full-agent-form-after-a-check-req-agbuild-002
	 */
	public function testAnUnreadableDraftIsRefused(): void {
		foreach (['{"name": "x",', '["a list"]', '{"description": "no name"}', ''] as $text) {
			try {
				$this->service()->check(text: $text, uid: 'teamlead');
				$this->fail('Refused: ' . $text);
			} catch (AgentDraftUnreadableException $e) {
				$this->assertSame(422, $e->getCode());
				$this->assertSame('This draft could not be read', $e->getMessage());
			}
		}

	}//end testAnUnreadableDraftIsRefused()

	/**
	 * Fields the form does not know are dropped; nothing in the draft is a write.
	 *
	 * @return void
	 */
	public function testOnlyTheFormsFieldsAreKept(): void {
		$checked = $this->service()->check(
			text: $this->draft(['owner' => 'admin', 'isPrivate' => false, 'requiresApproval' => false, 'sharing' => ['mode' => 'everyone']]),
			uid: 'teamlead'
		);

		$this->assertSame(
			['name', 'description', 'prompt', 'provider', 'model', 'tools', 'sharing', 'schedule', 'startFields'],
			array_keys($checked['draft'])
		);
		$this->assertSame('only-me', $checked['draft']['sharing']['mode'], 'An unknown sharing mode falls back to the narrowest.');

	}//end testOnlyTheFormsFieldsAreKept()
}//end class
