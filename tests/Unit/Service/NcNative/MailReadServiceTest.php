<?php

/**
 * Tests for the nc-mail-read-tools MailReadService.
 *
 * The behaviour that matters most here is the AI-FEATURE GATE, and it is asserted
 * as a control pair rather than a presence check. These tools are honestly
 * `readOnlyHint: true`, so the write default-deny that protects
 * `nc-native-write-tools` does NOT protect them — this gate is the only thing
 * standing between a tool grant and an agent reading a user's inbox. A test that
 * only proved "disabled refuses" would pass just as well against a service that
 * refused everything, so the enabled half is asserted too.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\NcNative
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\NcNative;

use OCA\Hermiq\Service\AiFeatureService;
use OCA\Hermiq\Service\NcNative\MailReadService;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Unit tests for MailReadService.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\NcNative
 */
class MailReadServiceTest extends TestCase {

	/**
	 * Build the service with a feature register in the given state.
	 *
	 * @param bool|null $enabled True/false for a feature in that state, null for absent.
	 * @param bool $throws Whether the feature lookup throws.
	 *
	 * @return MailReadService
	 */
	private function service(?bool $enabled, bool $throws = false): MailReadService {
		$features = $this->createMock(AiFeatureService::class);

		if ($throws === true) {
			$features->method('findBySlug')->willThrowException(new RuntimeException('register down'));
		} elseif ($enabled === null) {
			$features->method('findBySlug')->willReturn(null);
		} else {
			$feature = new ObjectEntity();
			$feature->setObject(['enabled' => $enabled]);
			$features->method('findBySlug')->willReturn($feature);
		}

		return new MailReadService(
			$features,
			$this->createMock(ContainerInterface::class),
			$this->createMock(LoggerInterface::class)
		);

	}//end service()

	/**
	 * Every mail tool refuses when the AI feature is not enabled.
	 *
	 * @return void
	 */
	public function testDisabledFeatureRefusesEveryTool(): void {
		$service = $this->service(false);

		$results = [
			$service->listAccounts('alice'),
			$service->listMessages('alice', ['mailboxId' => 1]),
			$service->readMessage('alice', ['id' => 1]),
		];

		foreach ($results as $result) {
			$this->assertSame('mail_reading_not_enabled', $result['error']['code']);
		}

	}//end testDisabledFeatureRefusesEveryTool()

	/**
	 * An ABSENT feature refuses too — the gate fails closed rather than treating
	 * "never configured" as permission.
	 *
	 * @return void
	 */
	public function testAbsentFeatureRefuses(): void {
		$result = $this->service(null)->listAccounts('alice');

		$this->assertSame('mail_reading_not_enabled', $result['error']['code']);

	}//end testAbsentFeatureRefuses()

	/**
	 * A feature register that cannot be read refuses rather than defaulting open.
	 *
	 * @return void
	 */
	public function testUnreadableFeatureRegisterRefuses(): void {
		$result = $this->service(null, true)->listAccounts('alice');

		$this->assertSame('mail_reading_not_enabled', $result['error']['code']);

	}//end testUnreadableFeatureRegisterRefuses()

	/**
	 * 🔴 The positive half of the control pair. With the feature ENABLED the gate
	 * lets the call through and it fails for a DIFFERENT reason — Mail being
	 * absent in this environment. Without this assertion, every test above would
	 * pass equally well against a service that refused everything unconditionally,
	 * and the gate would look load-bearing while proving nothing.
	 *
	 * @return void
	 */
	public function testEnabledFeaturePassesTheGateAndFailsOnMailInstead(): void {
		$result = $this->service(true)->listAccounts('alice');

		$this->assertArrayHasKey('error', $result);
		$this->assertSame(
			'mail_not_available',
			$result['error']['code'],
			'With the feature enabled the gate must be passed — the remaining failure must be Mail, not the gate.'
		);

	}//end testEnabledFeaturePassesTheGateAndFailsOnMailInstead()

	/**
	 * Mail being absent is a structured error for every tool, never an exception:
	 * `invokeTool()` must never throw, so an agent run continues.
	 *
	 * @return void
	 */
	public function testMailAbsentIsAStructuredErrorForEveryTool(): void {
		$service = $this->service(true);

		foreach (
			[
				$service->listAccounts('alice'),
				$service->listMessages('alice', ['mailboxId' => 1]),
				$service->readMessage('alice', ['id' => 1]),
			] as $result
		) {
			$this->assertSame('mail_not_available', $result['error']['code']);
		}

	}//end testMailAbsentIsAStructuredErrorForEveryTool()

	/**
	 * A missing mailbox id is refused before Mail is touched.
	 *
	 * @return void
	 */
	public function testListMessagesRequiresAMailboxId(): void {
		$result = $this->service(true)->listMessages('alice', []);

		$this->assertSame('invalid_argument', $result['error']['code']);

	}//end testListMessagesRequiresAMailboxId()

	/**
	 * A missing message id is refused before Mail is touched.
	 *
	 * @return void
	 */
	public function testReadMessageRequiresAnId(): void {
		$result = $this->service(true)->readMessage('alice', []);

		$this->assertSame('invalid_argument', $result['error']['code']);

	}//end testReadMessageRequiresAnId()

	/**
	 * The page-size ceiling is a server-side constant, not a suggestion. An
	 * unbounded listing is the difference between an assistant reading a thread
	 * and an assistant enumerating an inbox.
	 *
	 * @return void
	 */
	public function testPageSizeCeilingIsDeclaredAndModest(): void {
		$this->assertSame(50, MailReadService::MAX_PAGE_SIZE);
		$this->assertSame('mail-reading', MailReadService::FEATURE_SLUG);

	}//end testPageSizeCeilingIsDeclaredAndModest()

	/**
	 * Build a service with the feature enabled and Mail's services replaced by
	 * doubles, so the happy paths — unreachable anywhere Mail is not installed,
	 * CI included — are exercised at all.
	 *
	 * @param array<string, object> $doubles Keyed by MAIL_CLASSES key.
	 *
	 * @return MailReadService
	 */
	private function serviceWithMail(array $doubles): MailReadService {
		$features = $this->createMock(AiFeatureService::class);
		$feature = new ObjectEntity();
		$feature->setObject(['enabled' => true]);
		$features->method('findBySlug')->willReturn($feature);

		return new class(
			$features,
			$this->createMock(ContainerInterface::class),
			$this->createMock(LoggerInterface::class),
			$doubles
		) extends MailReadService {
			/**
			 * Constructor.
			 *
			 * @param AiFeatureService $features The feature register.
			 * @param ContainerInterface $container The container.
			 * @param LoggerInterface $logger The logger.
			 * @param array<string, object> $doubles The Mail doubles.
			 */
			public function __construct(
				AiFeatureService $features,
				ContainerInterface $container,
				LoggerInterface $logger,
				private array $doubles,
			) {
				parent::__construct($features, $container, $logger);
			}

			/**
			 * Return an injected double instead of resolving Mail.
			 *
			 * @param string $key The service key.
			 *
			 * @return object|null
			 */
			protected function mail(string $key): ?object {
				return ($this->doubles[$key] ?? null);
			}
		};

	}//end serviceWithMail()

	/**
	 * Accounts are reduced to identity fields — no credentials, no server settings.
	 *
	 * @return void
	 */
	public function testAccountsAreReducedToIdentityFields(): void {
		$accounts = new class {
			/**
			 * Find accounts for a user.
			 *
			 * @param string $uid The user id.
			 *
			 * @return array<int, object>
			 */
			public function findByUserId(string $uid): array {
				return [
					new class {
						/**
						 * The id.
						 *
						 * @return int
						 */
						public function getId(): int {
							return 3;
						}

						/**
						 * The address.
						 *
						 * @return string
						 */
						public function getEmail(): string {
							return 'alice@example.org';
						}

						/**
						 * The display name.
						 *
						 * @return string
						 */
						public function getName(): string {
							return 'Alice';
						}

						/**
						 * A password that must never surface.
						 *
						 * @return string
						 */
						public function getInboundPassword(): string {
							return 'SHOULD_NEVER_APPEAR';
						}
					},
				];
			}
		};

		$result = $this->serviceWithMail(['accounts' => $accounts])->listAccounts('alice');

		$this->assertSame(3, $result['accounts'][0]['id']);
		$this->assertSame('alice@example.org', $result['accounts'][0]['email']);
		$this->assertStringNotContainsString(
			'SHOULD_NEVER_APPEAR',
			(string)json_encode($result),
			'Account credentials must never reach a tool response.'
		);

	}//end testAccountsAreReducedToIdentityFields()

	/**
	 * A page-size above the server maximum is CLAMPED, and the request for more is
	 * not honoured — the ceiling cannot be raised by argument.
	 *
	 * @return void
	 */
	public function testOversizedPageRequestIsClamped(): void {
		$asked = [];
		$mailbox = new class {
		};

		$manager = new class($mailbox) {
			/**
			 * Constructor.
			 *
			 * @param object $mailbox The mailbox.
			 */
			public function __construct(private object $mailbox) {
			}

			/**
			 * Resolve a mailbox for a user.
			 *
			 * @param string $uid The user id.
			 * @param int $id The mailbox id.
			 *
			 * @return object
			 */
			public function getMailbox(string $uid, int $id): object {
				return $this->mailbox;
			}
		};

		$messages = new class($asked) {
			/**
			 * Constructor.
			 *
			 * @param array<int, int> $asked Reference-captured id slice.
			 */
			public function __construct(private array &$asked) {
			}

			/**
			 * All ids in a mailbox.
			 *
			 * @param object $mailbox The mailbox.
			 *
			 * @return array<int, int>
			 */
			public function findAllIds(object $mailbox): array {
				return range(1, 500);
			}

			/**
			 * Fetch by ids.
			 *
			 * @param string $uid The user id.
			 * @param array<int, int> $ids The ids.
			 * @param string $sortOrder The sort order.
			 *
			 * @return array<int, object>
			 */
			public function findByIds(string $uid, array $ids, string $sortOrder): array {
				$this->asked = $ids;

				return [];
			}
		};

		$result = $this->serviceWithMail(['manager' => $manager, 'messages' => $messages])
			->listMessages('alice', ['mailboxId' => 1, 'pageSize' => 5000]);

		$this->assertSame(
			MailReadService::MAX_PAGE_SIZE,
			$result['pageSize'],
			'A caller asking for 5000 must be clamped to the server maximum.'
		);
		$this->assertCount(MailReadService::MAX_PAGE_SIZE, $asked);

	}//end testOversizedPageRequestIsClamped()

	/**
	 * A mailbox that cannot be resolved for this user is a structured refusal, not
	 * an exception — the IDOR path and the not-found path are the same shape.
	 *
	 * @return void
	 */
	public function testUnresolvableMailboxIsRefused(): void {
		$manager = new class {
			/**
			 * Resolve a mailbox for a user.
			 *
			 * @param string $uid The user id.
			 * @param int $id The mailbox id.
			 *
			 * @return object
			 */
			public function getMailbox(string $uid, int $id): object {
				throw new RuntimeException('not found for this user');
			}
		};

		$messages = new class {
		};

		$result = $this->serviceWithMail(['manager' => $manager, 'messages' => $messages])
			->listMessages('alice', ['mailboxId' => 99]);

		$this->assertSame('mailbox_not_found', $result['error']['code']);

	}//end testUnresolvableMailboxIsRefused()

	/**
	 * A message that cannot be resolved for this user is a structured refusal.
	 *
	 * @return void
	 */
	public function testUnresolvableMessageIsRefused(): void {
		$manager = new class {
			/**
			 * Resolve a message for a user.
			 *
			 * @param string $uid The user id.
			 * @param int $id The message id.
			 *
			 * @return object
			 */
			public function getMessage(string $uid, int $id): object {
				throw new RuntimeException('not found for this user');
			}
		};

		$result = $this->serviceWithMail(
			['manager' => $manager, 'accounts' => new class {
			}, 'clients' => new class {
			}]
		)->readMessage('alice', ['id' => 99]);

		$this->assertSame('message_not_found', $result['error']['code']);

	}//end testUnresolvableMessageIsRefused()

	/**
	 * A read returns headers, the text body and attachment METADATA — and no
	 * attachment bytes, no HTML body unless asked, and no credentials.
	 *
	 * This is the one test that exercises the envelope/address/attachment
	 * reduction at all, so it is also where the no-bytes rule is actually proven
	 * rather than asserted in prose.
	 *
	 * @return void
	 */
	public function testReadMessageReturnsMetadataAndNoAttachmentBytes(): void {
		$doubles = $this->readMessageDoubles();

		$result = $this->serviceWithMail($doubles)->readMessage('alice', ['id' => 7]);

		$this->assertArrayNotHasKey('error', $result);
		$this->assertSame('Quarterly figures', $result['subject']);
		$this->assertSame(['sender@example.org'], $result['from']);
		$this->assertSame('the plain body', $result['body']);
		$this->assertTrue($result['hasAttachments']);

		$this->assertSame('budget.ods', $result['attachments'][0]['name']);
		$this->assertSame(2048, $result['attachments'][0]['size']);

		$encoded = (string)json_encode($result);
		$this->assertStringNotContainsString(
			'ATTACHMENT_BYTES',
			$encoded,
			'Attachment contents must never reach a tool response.'
		);
		$this->assertArrayNotHasKey('htmlBody', $result, 'HTML must not be returned unless explicitly requested.');

	}//end testReadMessageReturnsMetadataAndNoAttachmentBytes()

	/**
	 * HTML is returned only when asked for, and is flagged unsanitised so no
	 * consumer can mistake it for vetted markup.
	 *
	 * @return void
	 */
	public function testHtmlIsReturnedOnlyOnRequestAndFlaggedUnsanitised(): void {
		$doubles = $this->readMessageDoubles();

		$result = $this->serviceWithMail($doubles)->readMessage('alice', ['id' => 7, 'includeHtml' => true]);

		$this->assertSame('<p>the html body</p>', $result['htmlBody']);
		$this->assertTrue($result['htmlBodyIsUnsanitised']);

	}//end testHtmlIsReturnedOnlyOnRequestAndFlaggedUnsanitised()

	/**
	 * The Mail doubles needed for a successful read.
	 *
	 * @return array<string, object> The doubles, keyed as MAIL_CLASSES.
	 */
	private function readMessageDoubles(): array {
		$message = new class {
			/**
			 * The message id.
			 *
			 * @return int
			 */
			public function getId(): int {
				return 7;
			}

			/**
			 * The subject.
			 *
			 * @return string
			 */
			public function getSubject(): string {
				return 'Quarterly figures';
			}

			/**
			 * The sender list.
			 *
			 * @return object
			 */
			public function getFrom(): object {
				return new class {
					/**
					 * Serialise the address list.
					 *
					 * @return array<int, array<string, string>>
					 */
					public function jsonSerialize(): array {
						return [['email' => 'sender@example.org', 'label' => 'Sender']];
					}
				};
			}

			/**
			 * The recipient list.
			 *
			 * @return object
			 */
			public function getTo(): object {
				return $this->getFrom();
			}

			/**
			 * The sent timestamp.
			 *
			 * @return int
			 */
			public function getSentAt(): int {
				return 1700000000;
			}

			/**
			 * The seen flag.
			 *
			 * @return bool
			 */
			public function getFlagSeen(): bool {
				return false;
			}

			/**
			 * The answered flag.
			 *
			 * @return bool
			 */
			public function getFlagAnswered(): bool {
				return false;
			}

			/**
			 * The attachments.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function getAttachments(): array {
				return [['fileName' => 'budget.ods', 'size' => 2048]];
			}

			/**
			 * The owning mailbox id.
			 *
			 * @return int
			 */
			public function getMailboxId(): int {
				return 1;
			}

			/**
			 * The IMAP uid.
			 *
			 * @return int
			 */
			public function getUid(): int {
				return 42;
			}
		};

		$imap = new class {
			/**
			 * The plain body.
			 *
			 * @return string
			 */
			public function getPlainBody(): string {
				return 'the plain body';
			}

			/**
			 * The HTML body.
			 *
			 * @param int $id The message id.
			 *
			 * @return string
			 */
			public function getHtmlBody(int $id): string {
				return '<p>the html body</p>';
			}

			/**
			 * The attachments, carrying bytes that must never surface.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function getAttachments(): array {
				return [
					[
						'fileName' => 'budget.ods',
						'size' => 2048,
						'mime' => 'application/vnd.oasis.opendocument.spreadsheet',
						'content' => 'ATTACHMENT_BYTES',
					],
				];
			}
		};

		$manager = new class($message, $imap) {
			/**
			 * Constructor.
			 *
			 * @param object $message The message.
			 * @param object $imap The IMAP message.
			 */
			public function __construct(private object $message, private object $imap) {
			}

			/**
			 * Resolve a message for a user.
			 *
			 * @param string $uid The user id.
			 * @param int $id The message id.
			 *
			 * @return object
			 */
			public function getMessage(string $uid, int $id): object {
				return $this->message;
			}

			/**
			 * Resolve a mailbox for a user.
			 *
			 * @param string $uid The user id.
			 * @param int $id The mailbox id.
			 *
			 * @return object
			 */
			public function getMailbox(string $uid, int $id): object {
				return new class {
					/**
					 * The owning account id.
					 *
					 * @return int
					 */
					public function getAccountId(): int {
						return 3;
					}
				};
			}

			/**
			 * Fetch the IMAP message.
			 *
			 * @param object $client The IMAP client.
			 * @param object $account The account.
			 * @param object $mailbox The mailbox.
			 * @param int $uid The IMAP uid.
			 * @param bool $loadBody Whether to load the body.
			 *
			 * @return object
			 */
			public function getImapMessage(
				object $client,
				object $account,
				object $mailbox,
				int $uid,
				bool $loadBody = false,
			): object {
				return $this->imap;
			}
		};

		$accounts = new class {
			/**
			 * Find one account for a user.
			 *
			 * @param string $uid The user id.
			 * @param int $id The account id.
			 *
			 * @return object
			 */
			public function find(string $uid, int $id): object {
				return new class {
				};
			}
		};

		$clients = new class {
			/**
			 * Build an IMAP client.
			 *
			 * @param object $account The account.
			 *
			 * @return object
			 */
			public function getClient(object $account): object {
				return new class {
				};
			}
		};

		return ['manager' => $manager, 'accounts' => $accounts, 'clients' => $clients];

	}//end readMessageDoubles()

	/**
	 * No method on this service writes, sends, deletes, flags or moves anything.
	 *
	 * A structural assertion: the guarantee is that the capability does not exist,
	 * and that is only true if no method offers it.
	 *
	 * @return void
	 */
	public function testServiceExposesNoWriteVerb(): void {
		foreach (get_class_methods(MailReadService::class) as $method) {
			foreach (['delete', 'send', 'move', 'flag', 'draft', 'markRead'] as $forbidden) {
				$this->assertStringNotContainsStringIgnoringCase(
					$forbidden,
					$method,
					"MailReadService::{$method} — nc-mail-read-tools is read-only."
				);
			}
		}

	}//end testServiceExposesNoWriteVerb()

}//end class
