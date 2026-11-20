<?php

/**
 * Hermiq PublicChatService.
 *
 * One public chat turn: a visitor who has not signed in asks a channel a
 * question, and the channel's tool-free agent answers from public content
 * only. Shared by the website endpoint and the in-process entry point for
 * sibling apps, so both pass the same gates in the same order.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\PublicChat
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-a-visitor-chats-without-signing-in-and-gets-answers-only-from-public-content-req-pubchat-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\PublicChat;

use LLPhant\Chat\Enums\ChatRole;
use LLPhant\Chat\Message as LLPhantMessage;
use OCA\Hermiq\Service\Engine\ResponseGenerationHandler;
use OCA\Hermiq\Service\GuardrailPolicyService;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Runs one public chat turn.
 *
 * THE ORDER. The question is checked, then PublicChatGate lets the turn
 * through (channel, feature, kill switch, agent, daily limit), then the
 * guardrail input filter, the anonymous retrieval, the model, and the
 * guardrail output filter.
 *
 * NO IDENTITY. Nothing here reads the session user, and nothing written
 * carries one: the conversation is kept under its random token, with no IP
 * address.
 *
 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-a-visitor-chats-without-signing-in-and-gets-answers-only-from-public-content-req-pubchat-002
 */
class PublicChatService {

	/**
	 * The longest question a visitor may ask.
	 */
	public const MAX_QUESTION = 2000;

	/**
	 * What a question the guardrails refuse is answered with.
	 */
	public const BLOCKED_REPLY = 'I cannot help with that here.';

	/**
	 * What a question with no public source is answered with, without asking the model.
	 */
	public const NO_MATCH_REPLY = 'I could not find that in the public information I can use. Try asking it in other words.';

	/**
	 * The disclosure a channel without one shows.
	 */
	public const DEFAULT_DISCLOSURE = 'You are chatting with an AI assistant. It can make mistakes. Do not share personal details.';

	/**
	 * The earlier turns the model reads.
	 */
	private const HISTORY_TURNS = 10;

	/**
	 * Constructor.
	 *
	 * @param PublicChannelStore          $store      Channels, agents and conversations.
	 * @param PublicChatGate              $gate       The gates before the model and the daily limit.
	 * @param PublicRetrievalService      $retrieval  The anonymous, scoped reader.
	 * @param ResponseGenerationHandler   $responses  Runs the model.
	 * @param ITimeFactory                $time       The turn's time.
	 * @param GuardrailPolicyService|null $guardrails The organisation's input and output filters.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-a-visitor-chats-without-signing-in-and-gets-answers-only-from-public-content-req-pubchat-002
	 */
	public function __construct(
		private readonly PublicChannelStore $store,
		private readonly PublicChatGate $gate,
		private readonly PublicRetrievalService $retrieval,
		private readonly ResponseGenerationHandler $responses,
		private readonly ITimeFactory $time,
		private readonly ?GuardrailPolicyService $guardrails=null,
	) {
	}//end __construct()

	/**
	 * Answer one question on a channel.
	 *
	 * @param array<string, mixed>                               $channel           The channel, as the store reads it.
	 * @param string                                             $question          The visitor's question.
	 * @param string                                             $locale            The visitor's locale, for the model.
	 * @param array<int, array{register?: string, schema?: string}>|null $sourceScope       What the caller narrows the scope to, or null.
	 * @param string|null                                        $conversationToken The visit's token, or null for a new one.
	 *
	 * @return PublicAnswer
	 *
	 * @throws PublicChatRefusedException 503 when the channel is stopped, 429 over its daily limit, 400 for an empty or too long question.
	 *
	 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-a-visitor-chats-without-signing-in-and-gets-answers-only-from-public-content-req-pubchat-002
	 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-the-public-endpoint-is-limited-and-origin-bound-req-pubchat-003
	 */
	public function answer(
		array $channel,
		string $question,
		string $locale='en',
		?array $sourceScope=null,
		?string $conversationToken=null
	): PublicAnswer {
		$question = self::validQuestion(question: $question);
		$agent    = $this->gate->runnableAgent(channel: $channel);
		$this->gate->countQuestion(channel: $channel);

		$disclosure   = $this->disclosureFor(channel: $channel);
		$organisation = (string)($channel['tenantId'] ?? '');
		$policy       = $this->policyFor(organisation: $organisation);
		$conversation = $this->store->conversation(channelId: (string)($channel['id'] ?? ''), token: $conversationToken);

		$input = $this->guardrails?->filterInput(policy: $policy, text: $question) ?? ['text' => $question, 'blocked' => false];
		if (($input['blocked'] ?? false) === true) {
			// The refused question is not kept.
			return new PublicAnswer(answer: self::BLOCKED_REPLY, sources: [], conversationToken: $conversation['token'], disclosure: $disclosure);
		}

		$question = (string)$input['text'];
		$sources  = $this->retrieval->retrieve(
			scope: $this->retrieval->scopeFor(channel: $channel, requested: $sourceScope),
			question: $question,
			organisation: $organisation
		);

		$reply = $this->responses->generateResponse(
			userMessage: $question,
			context: self::contextOf(sources: $sources, locale: $locale),
			messageHistory: self::historyOf(turns: $conversation['turns']),
			agent: $agent,
			selectedTools: [],
			channel: null,
			cnAiContext: [],
			contextPreamble: '',
			trace: null,
			dryRun: false
		);

		$output = $this->guardrails?->filterOutput(policy: $policy, text: $reply) ?? ['text' => $reply];
		$reply  = (string)($output['text'] ?? '');
		$links  = array_map(static fn (array $source): array => ['title' => $source['title'], 'url' => $source['url']], $sources);

		$at = $this->time->getDateTime()->format(DATE_ATOM);
		$conversation['turns'][] = ['role' => 'user', 'content' => $question, 'at' => $at];
		$conversation['turns'][] = ['role' => 'assistant', 'content' => $reply, 'at' => $at, 'sources' => $links];
		$this->store->saveConversation(channel: $channel, conversation: $conversation);

		return new PublicAnswer(answer: $reply, sources: $links, conversationToken: $conversation['token'], disclosure: $disclosure);
	}//end answer()

	/**
	 * The disclosure a channel shows before the first message.
	 *
	 * @param array<string, mixed> $channel The channel.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-a-visitor-chats-without-signing-in-and-gets-answers-only-from-public-content-req-pubchat-002
	 */
	public function disclosureFor(array $channel): string {
		$text = trim((string)($channel['disclosureText'] ?? ''));
		if ($text === '') {
			return self::DEFAULT_DISCLOSURE;
		}

		return $text;
	}//end disclosureFor()

	/**
	 * The organisation's guardrail policy, or a policy that filters nothing when none applies.
	 *
	 * @param string $organisation The channel's organisation.
	 *
	 * @return array<string, mixed>
	 */
	private function policyFor(string $organisation): array {
		if ($this->guardrails === null) {
			return [];
		}

		return $this->guardrails->effectivePolicyFor(organisation: $organisation);
	}//end policyFor()

	/**
	 * A question with surrounding space removed, refused when empty or too long.
	 *
	 * @param string $question The question.
	 *
	 * @return string
	 *
	 * @throws PublicChatRefusedException 400 for an empty or too long question.
	 */
	private static function validQuestion(string $question): string {
		$question = trim($question);
		if ($question === '' || mb_strlen($question) > self::MAX_QUESTION) {
			throw new PublicChatRefusedException(
				message: sprintf('Ask a question of at most %d characters.', self::MAX_QUESTION),
				status: 400
			);
		}

		return $question;
	}//end validQuestion()

	/**
	 * The model's context: the public sources, numbered, and nothing else.
	 *
	 * Scoped, so a question with no source is answered with NO_MATCH_REPLY
	 * without asking the model at all.
	 *
	 * @param list<array{id: string, title: string, url: string, excerpt: string}> $sources The sources.
	 * @param string                                                                $locale  The visitor's locale.
	 *
	 * @return array{text: string, sources: list<array<string, mixed>>, scoped: true, noMatch: string}
	 */
	private static function contextOf(array $sources, string $locale): array {
		$text = 'Answer only from the public sources below, in the language of locale "' . $locale . '". '
			. 'Name the source numbers you used. If the sources do not answer the question, say so.' . "\n\n";
		$list = [];
		foreach (array_values($sources) as $index => $source) {
			$number = ($index + 1);
			$text  .= '[' . $number . '] Source: ' . $source['title'] . ' (' . $source['url'] . ")\n" . $source['excerpt'] . "\n\n";
			$list[] = ['id' => $source['id'], 'type' => 'object', 'name' => $source['title'], 'url' => $source['url'], 'passage' => $number];
		}

		return ['text' => $text, 'sources' => $list, 'scoped' => true, 'noMatch' => self::NO_MATCH_REPLY];
	}//end contextOf()

	/**
	 * The earlier turns as model messages, at most HISTORY_TURNS of them.
	 *
	 * @param list<array<string, mixed>> $turns The conversation's turns.
	 *
	 * @return list<LLPhantMessage>
	 */
	private static function historyOf(array $turns): array {
		$messages = [];
		foreach (array_slice($turns, -self::HISTORY_TURNS) as $turn) {
			$content = (string)($turn['content'] ?? '');
			if ($content === '') {
				continue;
			}

			$message          = new LLPhantMessage();
			$message->role    = ChatRole::User;
			$message->content = $content;
			if (($turn['role'] ?? '') === 'assistant') {
				$message->role = ChatRole::Assistant;
			}

			$messages[] = $message;
		}

		return $messages;
	}//end historyOf()
}//end class
