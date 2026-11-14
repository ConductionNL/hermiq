<?php

/**
 * Contract of IntakeService
 *
 * The public surface of IntakeService that other components call, so they depend
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

use DateTimeImmutable;
use OCA\Hermiq\Service\Intake\IntakeRefusedException;

/**
 * Contract of IntakeService.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface IntakeServiceInterface {

	/**
	 * End the conversation: file it when the classification is confident enough,
	 * and hand it to a person otherwise.
	 *
	 * @param string $conversationId The conversation uuid.
	 * @param array<string, mixed> $classification The proposal: `type`, `confidence` and the catalogue it came from.
	 * @param array<string, mixed> $catalogue The request types the owning app declares.
	 * @param string $intakeTool The owning app's declared create-only intake tool.
	 * @param DateTimeImmutable|null $now The moment, for a deterministic test.
	 *
	 * @return array<string, mixed>|null The conversation in its terminal state, or null when absent.
	 *
	 * @spec openspec/specs/conversational-intake/spec.md#scenario-an-uncertain-intake-does-not-guess
	 */
	public function conclude(
		string $conversationId,
		array $classification,
		array $catalogue,
		string $intakeTool,
		?DateTimeImmutable $now = null,
	): ?array;

	/**
	 * One conversation, as a reader sees it.
	 *
	 * @param string $conversationId The conversation uuid.
	 *
	 * @return array<string, mixed>|null The conversation, or null.
	 *
	 * @spec openspec/specs/conversational-intake/spec.md#requirement-no-conversation-must-dead-end
	 */
	public function conversation(string $conversationId): ?array;

	/**
	 * Add one message to a person's open conversation about a subject, opening one
	 * if there is none.
	 *
	 * The conversation is keyed by the person and the subject, never by the
	 * channel, which is what lets somebody start by e-mail and continue in the
	 * portal without repeating themselves. The channel is recorded on the message
	 * because it is worth knowing; it decides nothing.
	 *
	 * @param string $person The person the conversation belongs to.
	 * @param string $subject What it is about, as the owning app names it.
	 * @param string $channel Which channel this message arrived on.
	 * @param string $text What the person wrote.
	 * @param DateTimeImmutable|null $now The moment, for a deterministic test.
	 *
	 * @return array<string, mixed> The conversation as it now stands.
	 *
	 * @spec openspec/specs/conversational-intake/spec.md#scenario-starting-by-e-mail-and-continuing-in-the-portal
	 */
	public function receive(
		string $person,
		string $subject,
		string $channel,
		string $text,
		?DateTimeImmutable $now = null,
	): array;

	/**
	 * Carry an external party's verdict into the conversation, with whose it was
	 * and when.
	 *
	 * Hermiq forms no opinion about what was reviewed. It calls the tool the owning
	 * app declared, records the answer, and that is the whole of its part.
	 *
	 * @param string $conversationId The conversation uuid.
	 * @param string $toolId The declared review tool.
	 * @param array<string, mixed> $arguments The arguments, passed through untouched.
	 * @param DateTimeImmutable|null $now The moment, for a deterministic test.
	 *
	 * @return array<string, mixed>|null The conversation, or null when there is no such conversation.
	 *
	 * @throws IntakeRefusedException When the grant does not cover the tool.
	 *
	 * @spec openspec/specs/conversational-intake/spec.md#scenario-a-verdict-travels-into-the-conversation-with-its-author
	 */
	public function review(
		string $conversationId,
		string $toolId,
		array $arguments,
		?DateTimeImmutable $now = null,
	): ?array;
}//end interface
