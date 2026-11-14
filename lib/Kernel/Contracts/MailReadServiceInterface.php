<?php

/**
 * Contract of MailReadService
 *
 * The public surface of MailReadService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Hermiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Kernel\Contracts;

/**
 * Contract of MailReadService.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface MailReadServiceInterface {

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
	public function listAccounts(string $uid): array;

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
	public function listMessages(string $uid, array $arguments): array;

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
	public function readMessage(string $uid, array $arguments): array;
}//end interface
