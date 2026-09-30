<?php

/**
 * GraphVisibility: a node is visible only while the acting user can read the record
 * behind it, checked through that record's own authorization, failing closed
 * (knowledge-graph).
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Graph
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/knowledge-graph/spec.md#requirement-an-edge-is-visible-only-when-both-endpoints-are
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Graph;

use OCA\Hermiq\Service\Graph\ActingUserScope;
use OCA\Hermiq\Service\Graph\GraphVisibility;
use OCA\Hermiq\Service\NcNative\MailReadService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for GraphVisibility and ActingUserScope.
 *
 * @spec openspec/specs/knowledge-graph/spec.md#requirement-an-edge-is-visible-only-when-both-endpoints-are
 */
class GraphVisibilityTest extends TestCase {

	/**
	 * The session user, as the ActingUserScope sees and sets it.
	 *
	 * @var IUser|null
	 */
	private ?IUser $sessionUser = null;

	/**
	 * The uid the session held during each object lookup.
	 *
	 * @var array<int, string|null>
	 */
	private array $lookupsAs = [];

	/**
	 * A user double.
	 *
	 * @param string $uid The uid.
	 *
	 * @return IUser
	 */
	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);

		return $user;
	}//end user()

	/**
	 * Build GraphVisibility with a real ActingUserScope over session doubles.
	 *
	 * @param callable $find The ObjectService::find behaviour (uuid, schema, rbac).
	 * @param array<int, int> $files File ids alice's folder holds.
	 * @param MailReadService|null $mail The mail service double.
	 *
	 * @return GraphVisibility
	 */
	private function visibility(callable $find, array $files = [], ?MailReadService $mail = null): GraphVisibility {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(fn (): ?IUser => $this->sessionUser);
		$session->method('setUser')->willReturnCallback(
			function (?IUser $user): void {
				$this->sessionUser = $user;
			}
		);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(fn (string $uid): ?IUser => in_array($uid, ['alice', 'bob'], true) ? $this->user($uid) : null);

		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $withFiles = false, mixed $register = null, mixed $schema = null, bool $_rbac = true) use ($find): ?ObjectEntity {
				$this->lookupsAs[] = $this->sessionUser?->getUID();

				return $find((string)$id, (string)$schema, $_rbac, $this->sessionUser?->getUID());
			}
		);

		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturnCallback(
			function (string $uid) use ($files): Folder {
				$folder = $this->createMock(Folder::class);
				$folder->method('getFirstNodeById')->willReturnCallback(
					fn (int $id): ?Node => ($uid === 'alice' && in_array($id, $files, true)) ? $this->createMock(Node::class) : null
				);

				return $folder;
			}
		);

		return new GraphVisibility(
			$objects,
			$root,
			($mail ?? $this->createMock(MailReadService::class)),
			new ActingUserScope($session, $users),
			$this->createMock(LoggerInterface::class)
		);

	}//end visibility()

	/**
	 * An object is visible when OpenRegister finds it WITH RBAC as the acting user; the
	 * session is switched to that user for the lookup and restored afterwards.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/knowledge-graph/spec.md#requirement-an-edge-is-visible-only-when-both-endpoints-are
	 */
	public function testAnObjectIsCheckedAsTheActingUserWithRbac(): void {
		$this->sessionUser = $this->user('admin');
		$visibility = $this->visibility(
			function (string $uuid, string $schema, bool $rbac, ?string $as): ?ObjectEntity {
				$this->assertTrue($rbac);
				$this->assertSame('contact', $schema);

				return ($as === 'alice' && $uuid === 'c-1') ? new ObjectEntity() : null;
			}
		);
		$ref = ['register' => 'crm', 'schema' => 'contact', 'uuid' => 'c-1'];

		$this->assertTrue($visibility->isVisible('object', $ref, 'alice'));
		$this->assertFalse($visibility->isVisible('object', $ref, 'bob'));
		$this->assertSame(['alice', 'bob'], $this->lookupsAs);
		$this->assertSame('admin', $this->sessionUser?->getUID(), 'the prior identity is restored');

	}//end testAnObjectIsCheckedAsTheActingUserWithRbac()

	/**
	 * A deleted record, an OpenRegister error, an unknown user, an incomplete pointer
	 * and an unknown source type are all "not visible", never an exception.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/knowledge-graph/spec.md#requirement-graph-nodes-reference-records-and-never-copy-them
	 */
	public function testUnresolvableRecordsFailClosed(): void {
		$this->sessionUser = $this->user('admin');
		$visibility = $this->visibility(
			static function (string $uuid): ?ObjectEntity {
				if ($uuid === 'boom') {
					throw new RuntimeException('register down');
				}

				return null;
			}
		);

		$this->assertFalse($visibility->isVisible('object', ['register' => 'crm', 'schema' => 'contact', 'uuid' => 'gone'], 'alice'));
		$this->assertFalse($visibility->isVisible('object', ['register' => 'crm', 'schema' => 'contact', 'uuid' => 'boom'], 'alice'));
		$this->assertFalse($visibility->isVisible('object', ['register' => 'crm', 'schema' => 'contact', 'uuid' => 'x'], 'mallory'));
		$this->assertFalse($visibility->isVisible('object', ['uuid' => 'x'], 'alice'));
		$this->assertFalse($visibility->isVisible('web', ['uuid' => 'x'], 'alice'));
		$this->assertFalse($visibility->isVisible('object', ['register' => 'crm', 'schema' => 'contact', 'uuid' => 'x'], ''));
		$this->assertSame('admin', $this->sessionUser?->getUID(), 'restored after a throw too');

	}//end testUnresolvableRecordsFailClosed()

	/**
	 * A file is visible when the acting user's own folder holds its id.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/knowledge-graph/spec.md#requirement-an-edge-is-visible-only-when-both-endpoints-are
	 */
	public function testAFileIsCheckedInTheUsersOwnFolder(): void {
		$visibility = $this->visibility(static fn (): ?ObjectEntity => null, [42]);

		$this->assertTrue($visibility->isVisible('file', ['fileId' => 42, 'path' => '/a'], 'alice'));
		$this->assertFalse($visibility->isVisible('file', ['fileId' => 42], 'bob'));
		$this->assertFalse($visibility->isVisible('file', ['fileId' => 43], 'alice'));
		$this->assertFalse($visibility->isVisible('file', ['path' => '/a'], 'alice'));

	}//end testAFileIsCheckedInTheUsersOwnFolder()

	/**
	 * Mail goes through the mail service's account-scoped lookup.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/knowledge-graph/spec.md#requirement-an-edge-is-visible-only-when-both-endpoints-are
	 */
	public function testMailUsesTheAccountScopedLookup(): void {
		$mail = $this->createMock(MailReadService::class);
		$mail->method('canRead')->willReturnCallback(static fn (string $uid, int $id): bool => $uid === 'alice' && $id === 3);
		$visibility = $this->visibility(static fn (): ?ObjectEntity => null, [], $mail);

		$this->assertTrue($visibility->isVisible('mail', ['accountId' => 1, 'mailboxId' => 2, 'messageId' => 3], 'alice'));
		$this->assertFalse($visibility->isVisible('mail', ['accountId' => 1, 'mailboxId' => 2, 'messageId' => 3], 'bob'));

	}//end testMailUsesTheAccountScopedLookup()

	/**
	 * A conversation is visible to its owner and listed participants only.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/knowledge-graph/spec.md#requirement-an-edge-is-visible-only-when-both-endpoints-are
	 */
	public function testAConversationFollowsTheRoster(): void {
		$visibility = $this->visibility(
			static function (string $uuid, string $schema): ?ObjectEntity {
				if ($uuid !== 'conv-1' || $schema !== 'agentsession') {
					return null;
				}

				$session = new ObjectEntity();
				$session->setObject(['userId' => 'alice', 'participants' => ['carol']]);

				return $session;
			}
		);
		$ref = ['conversationUuid' => 'conv-1'];

		$this->assertTrue($visibility->isVisible('conversation', $ref, 'alice'));
		$this->assertTrue($visibility->isVisible('conversation', $ref, 'carol'));
		$this->assertFalse($visibility->isVisible('conversation', $ref, 'bob'));
		$this->assertFalse($visibility->isVisible('conversation', ['conversationUuid' => 'conv-2'], 'alice'));

	}//end testAConversationFollowsTheRoster()
}//end class
