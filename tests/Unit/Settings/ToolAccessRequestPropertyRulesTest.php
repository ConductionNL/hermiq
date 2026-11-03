<?php

/**
 * The ToolAccessRequest property rules, decided by OpenRegister's real
 * PropertyRbacHandler loaded from OpenRegister's source.
 *
 * The schema admits a request's owner before any object-level rule, so the
 * fields that carry a decision (status, decidedBy, decidedAt) and the fields
 * the decision is about (toolId, agentId, requestedBy) must carry their own
 * update rule: the property layer gives the owner no bypass. Only the schema's
 * property block comes from hermiq's register; the decision is OpenRegister's.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tool-discovery-and-access-requests/specs/tool-discovery-and-access-requests/spec.md#requirement-only-the-agents-owner-must-be-able-to-grant-a-request
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Settings;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Lifecycle\StateFieldRuleResolver;
use OCA\OpenRegister\Service\Lifecycle\StateFieldRules;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Who may change which ToolAccessRequest field through the object API.
 *
 * @coversNothing
 *
 * @spec openspec/changes/tool-discovery-and-access-requests/specs/tool-discovery-and-access-requests/spec.md#requirement-only-the-agents-owner-must-be-able-to-grant-a-request
 */
class ToolAccessRequestPropertyRulesTest extends TestCase {

	/**
	 * The OpenRegister classes loaded from its real source for this test.
	 *
	 * @var string[]
	 */
	private const REAL_CLASSES = [
		'OCA\\OpenRegister\\Service\\PropertyRbacHandler',
		'OCA\\OpenRegister\\Service\\ConditionMatcher',
		'OCA\\OpenRegister\\Service\\Lifecycle\\StateFieldRuleResolver',
		'OCA\\OpenRegister\\Service\\Lifecycle\\StateFieldRules',
		'OCA\\OpenRegister\\Service\\Rbac\\ExplicitReveal',
		'OCA\\OpenRegister\\Service\\Rbac\\RevealCollector',
	];

	/**
	 * A stored pending request raised by alice.
	 *
	 * @var array<string, mixed>
	 */
	private const STORED = [
		'agentId' => 'agent-1',
		'toolId' => 'hermiq.listFiles',
		'reason' => 'To read the brief.',
		'status' => 'pending',
		'requestedBy' => 'alice',
	];

	/**
	 * Load the permission classes from OpenRegister's source. An absent source
	 * is an error, never a skip.
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void {
		$roots = array_filter([getenv('HERMIQ_OPENREGISTER_PATH') ?: null, __DIR__ . '/../../../../openregister']);
		$root  = null;
		foreach ($roots as $candidate) {
			if (is_file(rtrim((string)$candidate, '/') . '/lib/Service/PropertyRbacHandler.php') === true) {
				$root = rtrim((string)$candidate, '/');
				break;
			}
		}

		if ($root === null) {
			self::fail('OpenRegister source not found beside hermiq or at HERMIQ_OPENREGISTER_PATH.');
		}

		spl_autoload_register(
			static function (string $class) use ($root): void {
				if (in_array($class, self::REAL_CLASSES, true) === true) {
					include_once $root . '/lib/' . str_replace('\\', '/', substr($class, strlen('OCA\\OpenRegister\\'))) . '.php';
				}
			},
			true,
			true
		);

	}//end setUpBeforeClass()

	/**
	 * The properties a requester sends that OpenRegister refuses.
	 *
	 * @param array<string, mixed> $incoming The properties sent.
	 * @param bool                 $isAdmin  Whether the caller is in the admin group.
	 * @param bool                 $isCreate Whether the save creates the request.
	 *
	 * @return array<int, string>
	 */
	private function refused(array $incoming, bool $isAdmin = false, bool $isCreate = false): array {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(originalClassName: IGroupManager::class);
		$groups->method('getUserGroupIds')->willReturn($isAdmin === true ? ['admin'] : ['staff']);
		$stateRules = $this->createMock(originalClassName: StateFieldRuleResolver::class);
		$stateRules->method('resolve')->willReturn(new StateFieldRules());

		$handler = new PropertyRbacHandler(
			userSession: $session,
			groupManager: $groups,
			conditionMatcher: $this->createMock(originalClassName: ConditionMatcher::class),
			logger: new NullLogger(),
			stateFieldRules: $stateRules
		);

		$stored = self::STORED;
		if ($isCreate === true) {
			$stored = [];
		}

		return $handler->getUnauthorizedProperties(
			schema: $this->schema(),
			object: $stored,
			incomingData: $incoming,
			isCreate: $isCreate
		);

	}//end refused()

	/**
	 * The ToolAccessRequest schema as hermiq's register declares it, answering the
	 * three property-authorization questions OpenRegister's Schema answers.
	 *
	 * @return Schema
	 */
	private function schema(): Schema {
		$register   = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/hermiq_register.json'), true);
		$properties = $register['components']['schemas']['ToolAccessRequest']['properties'];
		$blocks     = [];
		foreach ($properties as $name => $config) {
			if (empty($config['authorization'] ?? null) === false) {
				$blocks[$name] = $config['authorization'];
			}
		}

		$schema = $this->getMockBuilder(className: Schema::class)
			->addMethods(methods: ['hasPropertyAuthorization', 'getPropertiesWithAuthorization', 'getPropertyAuthorization'])
			->getMock();
		$schema->method('hasPropertyAuthorization')->willReturn($blocks !== []);
		$schema->method('getPropertiesWithAuthorization')->willReturn($blocks);
		$schema->method('getPropertyAuthorization')->willReturnCallback(
			static fn (string $propertyName): ?array => ($blocks[$propertyName] ?? null)
		);

		return $schema;

	}//end schema()

	/**
	 * The requester, who owns the request, cannot decide it or swap what it is about.
	 *
	 * @return void
	 */
	public function testTheRequesterCannotDecideOrRetargetTheirRequest(): void {
		$attempts = [
			'status' => ['status' => 'granted'],
			'decidedBy' => ['decidedBy' => 'bob'],
			'decidedAt' => ['decidedAt' => '2026-11-03T10:00:00+00:00'],
			'toolId' => ['toolId' => 'hermiq.sendMail'],
			'agentId' => ['agentId' => 'agent-2'],
			'requestedBy' => ['requestedBy' => 'bob'],
		];
		foreach ($attempts as $field => $incoming) {
			$this->assertSame(expected: [$field], actual: $this->refused(incoming: $incoming + self::STORED), message: $field);
		}

		$both = $this->refused(incoming: ['status' => 'granted', 'toolId' => 'hermiq.sendMail'] + self::STORED);
		sort($both);
		$this->assertSame(expected: ['status', 'toolId'], actual: $both);

	}//end testTheRequesterCannotDecideOrRetargetTheirRequest()

	/**
	 * Resending the stored values, or changing the reason, is not refused.
	 *
	 * @return void
	 */
	public function testUnchangedFieldsAndTheReasonStayWritable(): void {
		$this->assertSame(expected: [], actual: $this->refused(incoming: self::STORED));
		$this->assertSame(expected: [], actual: $this->refused(incoming: ['reason' => 'A better reason.'] + self::STORED));

	}//end testUnchangedFieldsAndTheReasonStayWritable()

	/**
	 * Through the object API only an admin may set the decision fields, also when
	 * creating a request, so hermiq raises and decides requests on the system path.
	 *
	 * @return void
	 */
	public function testOnlyAnAdminSetsTheGuardedFields(): void {
		$this->assertSame(expected: [], actual: $this->refused(incoming: ['status' => 'granted', 'decidedBy' => 'carol'] + self::STORED, isAdmin: true));
		$created = $this->refused(incoming: self::STORED, isCreate: true);
		sort($created);
		$this->assertSame(expected: ['agentId', 'requestedBy', 'status', 'toolId'], actual: $created);

	}//end testOnlyAnAdminSetsTheGuardedFields()
}//end class
