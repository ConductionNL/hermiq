<?php

/**
 * Hermiq Mail Read Service (nc-mail-read-tools).
 *
 * `hermiq.sendMail` has existed since nc-native-tools. Nothing read. An agent
 * could compose and send mail on a user's behalf but could not see the message it
 * was answering — so this closes the read half.
 *
 * Nextcloud Mail publishes NO OCP contract, so every collaborator here is
 * resolved lazily from the server container behind a `class_exists()` guard and a
 * shape probe. That is a deliberate decision taken knowing the cost: an internal
 * API carries no deprecation contract and can move across releases. Absence AND
 * shape drift therefore both fail SOFT into a structured error, exactly as
 * `listDeckBoards` already does for Deck.
 *
 * The IDOR guard is unusually cheap here and worth naming: Mail's own
 * `AccountService::find()`, `MailManager::getMailbox()` and `MailManager::getMessage()`
 * all take the acting uid and scope to it, so another user's account, mailbox or
 * message resolves to a not-found rather than to their data. This service adds no
 * second scoping mechanism that could drift out of step with Mail's own.
 *
 * **The exposure that is actually new is the inference path.** Hermiq already
 * redacts egress — `DeliveryService` runs `RedactionService` before anything
 * crosses the instance boundary — and that still holds. What reading mail adds is
 * that a message body travels to whatever ENGINE the run uses, so on a hosted
 * provider a third party processes the user's correspondence. No prior control
 * spoke to that, because until now nothing read personal correspondence into
 * context. Hence: an AI-feature gate that a tool grant alone cannot satisfy, the
 * engine recorded on every read, and no mail content in the audit record.
 *
 * ADR-031: a legitimate imperative external-integration service — side-effecting
 * calls into a Nextcloud subsystem and, beneath it, IMAP. Owns no schema, no
 * derived value, no lifecycle.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\NcNative
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/nc-native-tools/spec.md#requirement-per-object-idor-guard-on-every-provider
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\NcNative;

use OCA\Hermiq\Service\AiFeatureService;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Read-only access to the acting user's own mail.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\NcNative
 *
 * @spec openspec/specs/nc-native-tools/spec.md#requirement-per-object-idor-guard-on-every-provider
 */
class MailReadService {

	use ErrorEnvelopeTrait;

	/**
	 * The AI feature that must be enabled before any mail is read.
	 *
	 * A tool grant is NOT sufficient authorisation (spec requirement). Reading a
	 * user's correspondence into a model is a processing activity in its own right
	 * and has to be a recorded, deliberate decision.
	 *
	 * @var string
	 */
	public const FEATURE_SLUG = 'mail-reading';

	/**
	 * The hard server-side maximum envelopes one call may return.
	 *
	 * Not a token-cost bound. An unbounded listing is the difference between an
	 * assistant reading a thread and an assistant enumerating an inbox.
	 *
	 * @var int
	 */
	public const MAX_PAGE_SIZE = 50;

	/**
	 * Mail's internal classes, resolved lazily and only if present.
	 *
	 * @var array<string, string>
	 */
	private const MAIL_CLASSES = [
		'accounts' => '\OCA\Mail\Service\AccountService',
		'manager' => '\OCA\Mail\Service\MailManager',
		'messages' => '\OCA\Mail\Db\MessageMapper',
		'clients' => '\OCA\Mail\IMAP\IMAPClientFactory',
	];

	/**
	 * Constructor.
	 *
	 * @param AiFeatureService $features The AI-feature governance register.
	 * @param ContainerInterface $container Lazy Mail service resolution.
	 * @param LoggerInterface $logger Diagnostics.
	 */
	public function __construct(
		private readonly AiFeatureService $features,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * List the acting user's own mail accounts — identity only.
	 *
	 * Never credentials and never server settings: Mail stores IMAP passwords, and
	 * nothing here goes near them.
	 *
	 * @param string $uid The acting user id.
	 *
	 * @return array<string, mixed> The accounts, or an error envelope.
	 *
	 * @spec openspec/specs/nc-native-tools/spec.md#requirement-per-object-idor-guard-on-every-provider
	 */
	public function listAccounts(string $uid): array {
		$refusal = $this->gate();
		if ($refusal !== null) {
			return $refusal;
		}

		$accounts = $this->mail(key: 'accounts');
		if ($accounts === null) {
			return $this->mailAbsent();
		}

		try {
			$found = $accounts->findByUserId($uid);
		} catch (Throwable $e) {
			$this->logger->warning('Hermiq: listing mail accounts failed', ['exception' => $e]);

			return $this->err(code: 'mail_unavailable', message: 'The mail accounts could not be listed.');
		}

		$results = [];
		foreach ($found as $account) {
			$results[] = [
				'id' => $account->getId(),
				'email' => $account->getEmail(),
				'name' => $account->getName(),
			];
		}

		return ['accounts' => $results];

	}//end listAccounts()

	/**
	 * Page one mailbox's envelopes. No bodies.
	 *
	 * @param string $uid The acting user id.
	 * @param array<string, mixed> $arguments mailboxId, page, pageSize.
	 *
	 * @return array<string, mixed> The envelopes, or an error envelope.
	 *
	 * @spec openspec/specs/nc-native-tools/spec.md#requirement-per-object-idor-guard-on-every-provider
	 */
	public function listMessages(string $uid, array $arguments): array {
		$refusal = $this->gate();
		if ($refusal !== null) {
			return $refusal;
		}

		$mailboxId = (int)($arguments['mailboxId'] ?? 0);
		if ($mailboxId <= 0) {
			return $this->err(code: 'invalid_argument', message: 'A mailboxId is required.');
		}

		$manager = $this->mail(key: 'manager');
		$messages = $this->mail(key: 'messages');
		if ($manager === null || $messages === null) {
			return $this->mailAbsent();
		}

		// The page size is clamped server-side and cannot be raised by argument.
		$pageSize = min(self::MAX_PAGE_SIZE, max(1, (int)($arguments['pageSize'] ?? 20)));
		$page = max(1, (int)($arguments['page'] ?? 1));

		try {
			// IDOR: getMailbox() is scoped to $uid, so another user's mailbox id
			// resolves to a not-found rather than to their mailbox.
			$mailbox = $manager->getMailbox($uid, $mailboxId);
			$ids = $messages->findAllIds($mailbox);
			$slice = array_slice($ids, (($page - 1) * $pageSize), $pageSize);

			$found = [];
			if ($slice !== []) {
				$found = $messages->findByIds($uid, $slice, 'DESC');
			}
		} catch (Throwable $e) {
			$this->logger->warning('Hermiq: listing mail messages failed', ['exception' => $e]);

			return $this->err(code: 'mailbox_not_found', message: 'That mailbox could not be read.');
		}

		return [
			'mailboxId' => $mailboxId,
			'page' => $page,
			'pageSize' => $pageSize,
			'messages' => array_map([$this, 'envelope'], $found),
		];

	}//end listMessages()

	/**
	 * Read one message: headers, text body, attachment METADATA.
	 *
	 * The HTML body is returned only on explicit request, flagged unsanitised, and
	 * never rendered by Hermiq. A mail body is attacker-controlled text entering a
	 * model's context; HTML additionally lets markup hide instructions from a human
	 * while leaving them legible to the model, so a human approving an action would
	 * be reading a different document than the model did. That is not mitigated by
	 * sanitising — it is mitigated by mail content never authorising anything,
	 * which the approval gate and write default-deny already provide.
	 *
	 * @param string $uid The acting user id.
	 * @param array<string, mixed> $arguments id, includeHtml.
	 *
	 * @return array<string, mixed> The message, or an error envelope.
	 *
	 * @spec openspec/specs/nc-native-tools/spec.md#requirement-per-object-idor-guard-on-every-provider
	 */
	public function readMessage(string $uid, array $arguments): array {
		$refusal = $this->gate();
		if ($refusal !== null) {
			return $refusal;
		}

		$id = (int)($arguments['id'] ?? 0);
		if ($id <= 0) {
			return $this->err(code: 'invalid_argument', message: 'A message id is required.');
		}

		$manager = $this->mail(key: 'manager');
		$accounts = $this->mail(key: 'accounts');
		$clients = $this->mail(key: 'clients');
		if ($manager === null || $accounts === null || $clients === null) {
			return $this->mailAbsent();
		}

		try {
			// IDOR: scoped to $uid by Mail's own API.
			$message = $manager->getMessage($uid, $id);
			$mailbox = $manager->getMailbox($uid, $message->getMailboxId());
			$account = $accounts->find($uid, $mailbox->getAccountId());

			$client = $clients->getClient($account);
			$imap = $manager->getImapMessage($client, $account, $mailbox, $message->getUid(), true);
		} catch (Throwable $e) {
			$this->logger->warning('Hermiq: reading a mail message failed', ['exception' => $e]);

			return $this->err(code: 'message_not_found', message: 'That message could not be read.');
		}

		$result = $this->envelope(message: $message);
		$result['body'] = $imap->getPlainBody();
		$result['attachments'] = $this->attachmentMetadata(imap: $imap);

		if (($arguments['includeHtml'] ?? false) === true) {
			// Returned UNSANITISED and marked as such. We do not own an HTML
			// sanitiser and should not acquire one for a secondary use case; what
			// we can guarantee is that no consumer receives HTML that looks vetted.
			$result['htmlBody'] = $imap->getHtmlBody($id);
			$result['htmlBodyIsUnsanitised'] = true;
		}

		return $result;

	}//end readMessage()

	/**
	 * Reduce a message to its envelope — never its body.
	 *
	 * @param object $message The Mail message entity.
	 *
	 * @return array<string, mixed> The envelope.
	 */
	private function envelope(object $message): array {
		return [
			'id' => $message->getId(),
			'subject' => $message->getSubject(),
			'from' => $this->addresses(list: $message->getFrom()),
			'to' => $this->addresses(list: $message->getTo()),
			'sentAt' => $message->getSentAt(),
			'flags' => ['seen' => $message->getFlagSeen(), 'answered' => $message->getFlagAnswered()],
			'hasAttachments' => ($message->getAttachments() !== []),
		];

	}//end envelope()

	/**
	 * Flatten an address list to plain strings.
	 *
	 * @param mixed $list The Mail AddressList.
	 *
	 * @return array<int, string> The addresses.
	 */
	private function addresses(mixed $list): array {
		if (is_object($list) === false || method_exists($list, 'jsonSerialize') === false) {
			return [];
		}

		$out = [];
		foreach (($list->jsonSerialize() ?? []) as $entry) {
			if (is_array($entry) === true) {
				$out[] = (string)($entry['email'] ?? '');
			}
		}

		return array_values(array_filter($out));

	}//end addresses()

	/**
	 * Attachment METADATA only — name, size, MIME. Never bytes.
	 *
	 * Attachments are the densest personal data in a mailbox and the least
	 * necessary for the driving use cases.
	 *
	 * @param object $imap The IMAP message.
	 *
	 * @return array<int, array<string, mixed>> The attachment metadata.
	 */
	private function attachmentMetadata(object $imap): array {
		try {
			$attachments = $imap->getAttachments();
		} catch (Throwable) {
			return [];
		}

		$out = [];
		foreach ($attachments as $attachment) {
			if (is_array($attachment) === false) {
				continue;
			}

			$out[] = [
				'name' => (string)($attachment['fileName'] ?? ''),
				'size' => (int)($attachment['size'] ?? 0),
				'mime' => (string)($attachment['mime'] ?? ''),
			];
		}

		return $out;

	}//end attachmentMetadata()

	/**
	 * The AI-feature gate. Returns an error envelope when reading is not authorised.
	 *
	 * Fails CLOSED: a feature that is absent, unreadable, or not explicitly enabled
	 * all refuse. These tools are honestly `readOnlyHint: true`, so the write
	 * default-deny that protects `nc-native-write-tools` does NOT protect them —
	 * this gate is what carries that weight instead, which is why it must not
	 * default to permissive.
	 *
	 * @return array<string, mixed>|null An error envelope, or null when authorised.
	 *
	 * @spec openspec/specs/nc-native-tools/spec.md#requirement-per-object-idor-guard-on-every-provider
	 */
	private function gate(): ?array {
		try {
			$feature = $this->features->findBySlug(self::FEATURE_SLUG);
		} catch (Throwable $e) {
			$this->logger->warning('Hermiq: mail-reading feature lookup failed', ['exception' => $e]);

			return $this->err(
				code: 'mail_reading_not_enabled',
				message: 'Mail reading is not enabled on this instance.'
			);
		}

		if ($feature === null || $this->isEnabled(feature: $feature) === false) {
			return $this->err(
				code: 'mail_reading_not_enabled',
				message: 'Mail reading must be enabled as an AI feature before an agent can read mail. '
					. 'A tool grant alone does not authorise it.'
			);
		}

		return null;

	}//end gate()

	/**
	 * Whether an AI-feature object is explicitly enabled.
	 *
	 * @param object $feature The feature object.
	 *
	 * @return bool True only when explicitly enabled.
	 */
	private function isEnabled(object $feature): bool {
		if (method_exists($feature, 'getObject') === false) {
			return false;
		}

		try {
			$data = $feature->getObject();
		} catch (Throwable) {
			return false;
		}

		return (is_array($data) === true && ($data['enabled'] ?? false) === true);

	}//end isEnabled()

	/**
	 * Resolve one of Mail's internal services, or null when absent/drifted.
	 *
	 * @param string $key The MAIL_CLASSES key.
	 *
	 * @return object|null The service, or null.
	 */
	private function mail(string $key): ?object {
		$class = (self::MAIL_CLASSES[$key] ?? '');
		if ($class === '' || class_exists($class) === false) {
			return null;
		}

		try {
			$service = $this->container->get($class);
		} catch (Throwable $e) {
			$this->logger->debug('Hermiq: Mail service could not be resolved', ['class' => $class, 'exception' => $e]);

			return null;
		}

		// A container may hand back a non-object for a binding it cannot build;
		// passing that on would break the never-throws guarantee.
		if (is_object($service) === false) {
			return null;
		}

		return $service;

	}//end mail()

	/**
	 * The structured error returned when Mail is unavailable or has drifted.
	 *
	 * @return array<string, mixed> The error envelope.
	 */
	private function mailAbsent(): array {
		return $this->err(
			code: 'mail_not_available',
			message: 'The Mail app is not available on this instance.'
		);

	}//end mailAbsent()

}//end class
