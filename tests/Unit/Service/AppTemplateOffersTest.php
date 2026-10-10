<?php

/**
 * Unit tests for AppTemplateOffers (agents-bound-to-their-app, task 6).
 *
 * The event travels through a real dispatcher (Symfony's, which Nextcloud's own
 * dispatcher wraps) to a fixture listener implementing OCP's IEventListener, the
 * contract an offering app implements. The templates go through the REAL
 * AgentTemplateService and AgentTemplateSerializer over an in-memory
 * ObjectService that keeps what it saved, so running the collect twice is a real
 * second run. Every saved payload is validated with Opis against the real
 * AgentTemplate fragment, with a negative control.
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
 * @spec openspec/specs/agent-template-gallery/spec.md#requirement-an-installed-app-can-offer-an-agent-template-for-itself-req-appag-005
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Event\CollectAgentTemplatesEvent;
use OCA\Hermiq\Service\AgentTemplateSerializer;
use OCA\Hermiq\Service\AgentTemplateService;
use OCA\Hermiq\Service\AppTemplateOffers;
use OCA\Hermiq\Service\SkillService;
use OCA\Hermiq\Service\TenantModelPolicyService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ContentScanService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\EventDispatcher\IEventListener;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcher as SymfonyDispatcher;

/**
 * An installed app offers an agent template for itself; hermiq imports it quarantined.
 */
final class AppTemplateOffersTest extends TestCase {

	/**
	 * The packages the fixture listener offers, by app id.
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	private array $offered = [];

	/**
	 * Whether the second fixture listener throws.
	 *
	 * @var bool
	 */
	private bool $throwingListener = false;

	/**
	 * The in-memory ObjectService.
	 *
	 * @var ObjectService
	 */
	private ObjectService $objects;

	protected function setUp(): void {
		$this->objects = $this->objectService();
		$this->offered = [];
		$this->throwingListener = false;
	}//end setUp()

	public function testAnOfferedTemplateLandsQuarantinedWithTheReasonAndTheOfferingApp(): void {
		$this->offered = [['shillinq', $this->financeHelper()]];

		$result = $this->offers()->collect();

		self::assertSame(1, $result['imported']);
		$templates = $this->saved();
		self::assertCount(1, $templates);
		$template = $templates[0];
		self::assertSame('Finance helper', $template['name']);
		self::assertSame('quarantined', $template['state']);
		self::assertSame('app', $template['source']);
		self::assertSame('shillinq', $template['offeredBy']);
		self::assertSame('Offered by the app shillinq. Review before use.', $template['quarantineReason']);
		self::assertSame(hash('sha256', $this->financeHelper()), $template['offerHash']);
		self::assertTrue($this->validTemplate(payload: $template), 'the stored payload must pass the real AgentTemplate fragment');
	}//end testAnOfferedTemplateLandsQuarantinedWithTheReasonAndTheOfferingApp()

	public function testCollectingTwiceCreatesNoDuplicate(): void {
		$this->offered = [['shillinq', $this->financeHelper()]];

		$this->offers()->collect();
		$second = $this->offers()->collect();

		self::assertSame(0, $second['imported']);
		self::assertSame(1, $second['unchanged']);
		self::assertCount(1, $this->saved(), 'an unchanged package is not imported twice');
	}//end testCollectingTwiceCreatesNoDuplicate()

	public function testAChangedPackageReplacesTheTemplateAndSendsItBackToReview(): void {
		$this->offered = [['shillinq', $this->financeHelper()]];
		$this->offers()->collect();
		$uuid = array_key_first($this->objects->store);
		// An admin approved it in the meantime.
		$this->objects->store[$uuid]['state'] = 'active';
		$this->objects->store[$uuid]['quarantineReason'] = '';

		$this->offered = [['shillinq', $this->financeHelper(prompt: 'You help with invoices and budgets.')]];
		$result = $this->offers()->collect();

		self::assertSame(1, $result['updated']);
		self::assertCount(1, $this->saved(), 'the changed offer replaces the template, it does not add one');
		$template = $this->objects->store[$uuid];
		self::assertSame('quarantined', $template['state'], 'changed content goes back to review');
		self::assertSame('You help with invoices and budgets.', $template['systemPrompt']);
		self::assertTrue($this->validTemplate(payload: $template));
	}//end testAChangedPackageReplacesTheTemplateAndSendsItBackToReview()

	public function testAnOfferForAnAppThatIsNotInstalledIsRefused(): void {
		$this->offered = [['notinstalled', $this->financeHelper()]];

		$result = $this->offers()->collect();

		self::assertSame(1, $result['refused']);
		self::assertCount(0, $this->saved());
	}//end testAnOfferForAnAppThatIsNotInstalledIsRefused()

	public function testAnOfferWithoutANameOrWithABadAppIdIsRefusedAndTheOthersStillLand(): void {
		$this->offered = [
			['shillinq', '{"description": "no name"}'],
			['Shillinq/../x', $this->financeHelper()],
			['shillinq', 'not json'],
			['dossiq', $this->financeHelper(name: 'Case helper')],
		];

		$result = $this->offers()->collect();

		self::assertSame(3, $result['refused']);
		self::assertSame(1, $result['imported']);
		self::assertSame(['Case helper'], array_column($this->saved(), 'name'));
	}//end testAnOfferWithoutANameOrWithABadAppIdIsRefusedAndTheOthersStillLand()

	public function testAListenerThatThrowsDoesNotStopTheCollect(): void {
		$this->offered = [['shillinq', $this->financeHelper()]];
		$this->throwingListener = true;

		$result = $this->offers()->collect();

		self::assertSame(1, $result['imported'], 'offers made before the failing listener still land');
	}//end testAListenerThatThrowsDoesNotStopTheCollect()

	public function testAnAgentCreatedFromTheOfferIsTiedToTheOfferingApp(): void {
		$this->offered = [['shillinq', $this->financeHelper()]];
		$this->offers()->collect();
		$uuid = (string)array_key_first($this->objects->store);
		$this->objects->store[$uuid]['state'] = 'active';

		$result = $this->templateService()->instantiate(templateId: $uuid, organisation: '');

		self::assertNotNull($result);
		$agent = end($this->objects->savedBySchema['agent']);
		self::assertSame('shillinq', $agent['applicationSlug']);
	}//end testAnAgentCreatedFromTheOfferIsTiedToTheOfferingApp()

	public function testTheFragmentRefusesASourceThatIsNotOneOfItsValues(): void {
		$this->offered = [['shillinq', $this->financeHelper()]];
		$this->offers()->collect();
		$template = $this->saved()[0];

		self::assertTrue($this->validTemplate(payload: $template));
		$template['source'] = 'app:shillinq';
		self::assertFalse(
			$this->validTemplate(payload: $template),
			'negative control: the enum refuses the design\'s first "app:<appId>" form, which is why offeredBy carries the app'
		);
	}//end testTheFragmentRefusesASourceThatIsNotOneOfItsValues()

	public function testTheFragmentDeclaresOfferedByAndOfferHashAsPlainStrings(): void {
		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/hermiq_register.json'), true);
		$properties = $register['components']['schemas']['AgentTemplate']['properties'];

		self::assertSame('string', $properties['offeredBy']['type']);
		self::assertSame('string', $properties['offerHash']['type']);
		self::assertContains('app', $properties['source']['enum']);
	}//end testTheFragmentDeclaresOfferedByAndOfferHashAsPlainStrings()

	/**
	 * The service under test, wired to a real dispatcher with the fixture listeners.
	 *
	 * @return AppTemplateOffers
	 */
	private function offers(): AppTemplateOffers {
		$symfony = new SymfonyDispatcher();
		$dispatcher = new class($symfony) implements IEventDispatcher {
			public function __construct(private SymfonyDispatcher $inner) {
			}

			public function addListener(string $eventName, callable $listener, int $priority = 0): void {
				$this->inner->addListener($eventName, $listener, $priority);
			}

			public function removeListener(string $eventName, callable $listener): void {
				$this->inner->removeListener($eventName, $listener);
			}

			public function addServiceListener(string $eventName, string $className, int $priority = 0): void {
				throw new RuntimeException('not used');
			}

			public function hasListeners(string $eventName): bool {
				return $this->inner->hasListeners($eventName);
			}

			public function dispatch(string $eventName, Event $event): void {
				$this->inner->dispatch($event, $eventName);
			}

			public function dispatchTyped(Event $event): void {
				$this->dispatch(get_class($event), $event);
			}
		};

		$offered = $this->offered;
		$listener = new class($offered) implements IEventListener {
			public function __construct(private array $offered) {
			}

			public function handle(Event $event): void {
				if ($event instanceof CollectAgentTemplatesEvent === false) {
					return;
				}

				foreach ($this->offered as [$appId, $package]) {
					$event->offer(appId: $appId, package: $package);
				}
			}
		};
		$dispatcher->addListener(CollectAgentTemplatesEvent::class, [$listener, 'handle'], 10);

		if ($this->throwingListener === true) {
			$dispatcher->addListener(
				CollectAgentTemplatesEvent::class,
				static function (): void {
					throw new RuntimeException('a broken app');
				},
				0
			);
		}

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturnCallback(
			static fn (string $appId): bool => in_array($appId, ['shillinq', 'dossiq'], true)
		);

		$scanner = $this->createMock(ContentScanService::class);
		$scanner->method('scan')->willReturn(
			['safe' => true, 'severity' => ContentScanService::SEVERITY_CLEAN, 'findings' => [], 'scannedBytes' => 10, 'truncated' => false]
		);

		return new AppTemplateOffers(
			dispatcher: $dispatcher,
			objectService: $this->objects,
			serializer: new AgentTemplateSerializer(),
			contentScanService: $scanner,
			appManager: $appManager,
			logger: new NullLogger(),
		);
	}//end offers()

	/**
	 * The real template service over the in-memory store.
	 *
	 * @return AgentTemplateService
	 */
	private function templateService(): AgentTemplateService {
		$scanner = $this->createMock(ContentScanService::class);
		$scanner->method('scan')->willReturn(
			['safe' => true, 'severity' => ContentScanService::SEVERITY_CLEAN, 'findings' => [], 'scannedBytes' => 10, 'truncated' => false]
		);
		$policy = $this->createMock(TenantModelPolicyService::class);
		$policy->method('effectivePolicyFor')->willReturn(['source' => 'none', 'allowed' => [], 'defaultModel' => null]);
		$policy->method('isAllowed')->willReturn(true);

		return new AgentTemplateService(
			objectService: $this->objects,
			serializer: new AgentTemplateSerializer(),
			contentScanService: $scanner,
			modelPolicyService: $policy,
			skillService: $this->createMock(SkillService::class),
		);
	}//end templateService()

	/**
	 * The templates in the store.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function saved(): array {
		return array_values(
			array_filter(
				$this->objects->store,
				static fn (array $object): bool => ($object['_schema'] ?? '') === 'agenttemplate'
			)
		);
	}//end saved()

	/**
	 * A finance helper package in the serializer's format.
	 *
	 * @param string $name The template name.
	 * @param string $prompt The system prompt.
	 *
	 * @return string
	 */
	private function financeHelper(string $name = 'Finance helper', string $prompt = 'You help with invoices.'): string {
		return (string)json_encode(
			[
				'name' => $name,
				'description' => 'Answers questions about the finance app.',
				'category' => 'finance',
				'systemPrompt' => $prompt,
				'tools' => [],
				'version' => '1.0.0',
			]
		);
	}//end financeHelper()

	/**
	 * Validate a payload against the real AgentTemplate fragment.
	 *
	 * @param array<string, mixed> $payload The payload.
	 *
	 * @return bool
	 */
	private function validTemplate(array $payload): bool {
		unset($payload['_schema']);
		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/hermiq_register.json'));
		$schema = $register->components->schemas->AgentTemplate;
		unset($schema->authorization, $schema->configuration);
		// `$ref: agentskill` is an OpenRegister relation, not a JSON pointer Opis can follow.
		unset($schema->properties->skillRefs->items->properties->skillId->{'$ref'});
		return (new Validator())->validate(json_decode((string)json_encode($payload)), $schema)->isValid();
	}//end validTemplate()

	/**
	 * A stateful in-memory ObjectService: saveObject() writes, findAll() reads back.
	 *
	 * @return ObjectService
	 */
	private function objectService(): ObjectService {
		return new class extends ObjectService {
			private ?string $schema = null;

			/**
			 * Saved objects by uuid, each with its schema under `_schema`.
			 *
			 * @var array<string, array<string, mixed>>
			 */
			public array $store = [];

			/**
			 * Every saved payload by schema, in order.
			 *
			 * @var array<string, array<int, array<string, mixed>>>
			 */
			public array $savedBySchema = [];

			public function __construct() {
			}

			public function setRegister(mixed $register): static {
				return $this;
			}

			public function setSchema(mixed $schema): static {
				$this->schema = (string)$schema;
				return $this;
			}

			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				$out = [];
				foreach ($this->store as $uuid => $object) {
					if (($object['_schema'] ?? '') === $this->schema) {
						$out[] = $this->entity(uuid: (string)$uuid, object: $object);
					}
				}

				return $out;
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
				$object = ($this->store[(string)$id] ?? null);
				if ($object === null) {
					return null;
				}

				return $this->entity(uuid: (string)$id, object: $object);
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
				$uuid = ($uuid ?? ('uuid-' . (count($this->store) + 1)));
				$this->savedBySchema[(string)$schema][] = $payload;
				$this->store[$uuid] = array_merge($payload, ['_schema' => (string)$schema]);
				return $this->entity(uuid: $uuid, object: $payload);
			}

			/**
			 * An entity for a stored payload.
			 *
			 * @param string $uuid The uuid.
			 * @param array<string, mixed> $object The payload.
			 *
			 * @return ObjectEntity
			 */
			private function entity(string $uuid, array $object): ObjectEntity {
				unset($object['_schema']);
				$entity = new ObjectEntity();
				$entity->setUuid($uuid);
				$entity->setObject($object);
				return $entity;
			}
		};
	}//end objectService()
}//end class
