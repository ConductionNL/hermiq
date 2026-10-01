<?php

/**
 * Unit tests for saving an agent as a template (agents-export-import-and-git-sync, task 1).
 *
 * The REAL AgentTemplateService and AgentTemplateSerializer over an ObjectService
 * double that serves the agent and keeps what is saved. The saved template is
 * validated with Opis against the real AgentTemplate fragment, with a negative
 * control.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\Hermiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/agents-export-import-and-git-sync/specs/agent-template-gallery/spec.md#requirement-an-agent-can-be-saved-as-a-reusable-template-req-agexp-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\AgentTemplateSerializer;
use OCA\Hermiq\Service\AgentTemplateService;
use OCA\Hermiq\Service\SkillService;
use OCA\Hermiq\Service\TenantModelPolicyService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ContentScanService;
use OCA\OpenRegister\Service\ObjectService;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

/**
 * The owner saves a well-tuned agent as an active template that remembers where it came from.
 */
final class AgentTemplateSaveFromAgentTest extends TestCase {

	private const AGENT = '5b0c2f9e-4c1a-4d8e-9f3a-2b7c6d1e0a11';

	/**
	 * The saved payloads.
	 *
	 * @var array<int, array{schema: string, object: array<string, mixed>}>
	 */
	public array $saved = [];

	public function testTheTemplateIsActiveLocalAndRecordsTheAgent(): void {
		$template = $this->service()->saveAgentAsTemplate(agentId: self::AGENT, createdBy: 'alice');

		self::assertNotNull($template);
		$payload = $this->saved[0]['object'];
		self::assertSame('agenttemplate', $this->saved[0]['schema']);
		self::assertSame('Meeting minutes drafter', $payload['name']);
		self::assertSame('active', $payload['state']);
		self::assertSame('local', $payload['source']);
		self::assertSame(self::AGENT, $payload['derivedFrom']);
		self::assertSame('alice', $payload['createdBy']);
		self::assertSame('Draft the minutes.', $payload['systemPrompt']);
		self::assertTrue($this->validTemplate(payload: $payload), 'the saved template must pass the real AgentTemplate fragment');
	}//end testTheTemplateIsActiveLocalAndRecordsTheAgent()

	public function testTheTemplateCarriesNoUserGroupQuotaViewOrCredential(): void {
		$this->service()->saveAgentAsTemplate(agentId: self::AGENT, createdBy: 'alice');

		$encoded = (string)json_encode($this->saved[0]['object']);
		foreach (['carol', 'finance-team', 'credential-7', 'view-9', 'svc-bot'] as $secret) {
			self::assertStringNotContainsString($secret, $encoded);
		}
	}//end testTheTemplateCarriesNoUserGroupQuotaViewOrCredential()

	public function testAnAgentThatDoesNotResolveSavesNothing(): void {
		self::assertNull($this->service()->saveAgentAsTemplate(agentId: 'missing', createdBy: 'alice'));
		self::assertSame([], $this->saved);
	}//end testAnAgentThatDoesNotResolveSavesNothing()

	public function testTheFragmentRefusesADerivedFromThatIsNotAUuid(): void {
		$this->service()->saveAgentAsTemplate(agentId: self::AGENT, createdBy: 'alice');
		$payload = $this->saved[0]['object'];

		$payload['derivedFrom'] = 'not an agent';
		self::assertFalse($this->validTemplate(payload: $payload), 'negative control: the validator must be able to refuse');
	}//end testTheFragmentRefusesADerivedFromThatIsNotAUuid()

	/**
	 * The real service over the double.
	 *
	 * @return AgentTemplateService
	 */
	private function service(): AgentTemplateService {
		$test = $this;
		$agent = new ObjectEntity();
		$agent->setUuid(self::AGENT);
		$agent->setOwner('alice');
		$agent->setObject(
			[
				'name' => 'Meeting minutes drafter',
				'description' => 'Drafts minutes from a transcript.',
				'type' => 'productivity',
				'prompt' => 'Draft the minutes.',
				'provider' => 'openai',
				'model' => 'gpt-4o-mini',
				'tools' => ['hermiq.searchFiles'],
				'invitedUsers' => ['carol'],
				'groups' => ['finance-team'],
				'requestQuota' => 100,
				'tokenQuota' => 5000,
				'views' => ['view-9'],
				'actingUser' => 'svc-bot',
				'credentialId' => 'credential-7',
			]
		);

		$objects = new class($agent, $test) extends ObjectService {
			public function __construct(private ObjectEntity $agent, private AgentTemplateSaveFromAgentTest $test) {
			}

			public function find(
				int|string $id,
				?array $_extend = [],
				bool $files = false,
				mixed $register = null,
				mixed $schema = null,
				bool $_rbac = true,
				bool $_multitenancy = true,
				bool $_render = true,
				bool $_audit = true,
			): ?ObjectEntity {
				return ((string)$id === (string)$this->agent->getUuid()) ? $this->agent : null;
			}

			public function saveObject(
				array|ObjectEntity $object,
				?array $extend = [],
				mixed $register = null,
				mixed $schema = null,
				?string $uuid = null,
				bool $_rbac = true,
				bool $_multitenancy = true,
				bool $silent = false,
				bool $_validation = true,
				?array $uploadedFiles = null,
				?\OCP\IUser $currentUser = null,
				bool $failIfExists = false,
				bool $_unowned = false,
				bool $_dedupOverride = false,
			): ObjectEntity {
				$payload = is_array($object) ? $object : $object->getObject();
				$this->test->saved[] = ['schema' => (string)$schema, 'object' => $payload];
				$entity = new ObjectEntity();
				$entity->setUuid('template-1');
				$entity->setObject($payload);
				return $entity;
			}
		};

		return new AgentTemplateService(
			objectService: $objects,
			serializer: new AgentTemplateSerializer(),
			contentScanService: $this->createMock(ContentScanService::class),
			modelPolicyService: $this->createMock(TenantModelPolicyService::class),
			skillService: $this->createMock(SkillService::class),
		);
	}//end service()

	/**
	 * Validate a payload against the real AgentTemplate fragment.
	 *
	 * @param array<string, mixed> $payload The payload.
	 *
	 * @return bool
	 */
	private function validTemplate(array $payload): bool {
		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/hermiq_register.json'));
		$schema = $register->components->schemas->AgentTemplate;
		unset($schema->authorization, $schema->configuration);
		// `$ref` names an OpenRegister schema (a relation), not a JSON pointer Opis can follow.
		unset($schema->properties->skillRefs->items->properties->skillId->{'$ref'});
		if (isset($schema->properties->derivedFrom) === true) {
			unset($schema->properties->derivedFrom->{'$ref'});
		}

		return (new Validator())->validate(json_decode((string)json_encode($payload)), $schema)->isValid();
	}//end validTemplate()
}//end class
