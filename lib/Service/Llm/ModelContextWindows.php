<?php

/**
 * Hermiq ModelContextWindows.
 *
 * The context window an administrator declared per `provider/model`, in tokens,
 * for the context meter on the Chat page (chat-how-an-answer-came-about D4). A
 * model without a declaration has no known window, and the meter then shows the
 * tokens used only. Stored in its own app config key next to the declared
 * capabilities (`hermiq.modelCapabilities`), never in the llm blob.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Llm
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/chat-how-an-answer-came-about/specs/answer-transparency/spec.md#requirement-a-person-sees-how-full-the-models-context-is-req-atrn-004
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Llm;

use InvalidArgumentException;
use OCA\Hermiq\Kernel\AppIdentity;
use OCP\IAppConfig;

/**
 * Reads and writes the declared context windows.
 *
 * @spec openspec/changes/chat-how-an-answer-came-about/specs/answer-transparency/spec.md#requirement-a-person-sees-how-full-the-models-context-is-req-atrn-004
 */
class ModelContextWindows {

	/**
	 * The app config key the windows are stored under.
	 *
	 * @var string
	 */
	private const CONFIG_KEY = 'modelContextWindows';

	/**
	 * The largest window accepted, in tokens: well above any model offered today,
	 * low enough that a typo of extra zeros is refused.
	 *
	 * @var int
	 */
	public const MAX_WINDOW = 10000000;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app config the windows live in.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Every declared window, by `provider/model`.
	 *
	 * @return array<string, int>
	 *
	 * @spec openspec/changes/chat-how-an-answer-came-about/specs/answer-transparency/spec.md#requirement-a-person-sees-how-full-the-models-context-is-req-atrn-004
	 */
	public function all(): array {
		$raw = $this->appConfig->getValueString(AppIdentity::APP_ID, self::CONFIG_KEY, '');
		$decoded = json_decode($raw, true);
		if (is_array($decoded) === false) {
			return [];
		}

		$out = [];
		foreach ($decoded as $key => $window) {
			if (is_int($window) === true && $window > 0) {
				$out[(string)$key] = $window;
			}
		}

		return $out;
	}//end all()

	/**
	 * The declared window of one model, or null when none is declared.
	 *
	 * @param string $provider The provider slug.
	 * @param string $model    The model identifier.
	 *
	 * @return int|null The window in tokens.
	 *
	 * @spec openspec/changes/chat-how-an-answer-came-about/specs/answer-transparency/spec.md#requirement-a-person-sees-how-full-the-models-context-is-req-atrn-004
	 */
	public function forModel(string $provider, string $model): ?int {
		return ($this->all()[$provider . '/' . trim($model)] ?? null);
	}//end forModel()

	/**
	 * Validate declarations: a `provider/model` key with an allowed provider, and a
	 * whole number of tokens from 1 to MAX_WINDOW, or null to remove the declaration.
	 *
	 * @param array<mixed, mixed> $declarations The windows by `provider/model`.
	 *
	 * @return array<string, int|null> The declarations, keys trimmed.
	 *
	 * @throws InvalidArgumentException On a malformed key or window.
	 *
	 * @spec openspec/changes/chat-how-an-answer-came-about/specs/answer-transparency/spec.md#requirement-a-person-sees-how-full-the-models-context-is-req-atrn-004
	 */
	public function normalizeDeclarations(array $declarations): array {
		$out = [];
		foreach ($declarations as $key => $window) {
			$out[$this->validKey(key: (string)$key)] = $this->validWindow(key: (string)$key, window: $window);
		}

		return $out;
	}//end normalizeDeclarations()

	/**
	 * Store declarations over the existing ones; a null window removes that model's.
	 *
	 * @param array<mixed, mixed> $declarations The windows by `provider/model`.
	 *
	 * @return array<string, int> Every declared window after the write.
	 *
	 * @throws InvalidArgumentException On a malformed key or window.
	 *
	 * @spec openspec/changes/chat-how-an-answer-came-about/specs/answer-transparency/spec.md#requirement-a-person-sees-how-full-the-models-context-is-req-atrn-004
	 */
	public function declareMany(array $declarations): array {
		$stored = $this->all();
		foreach ($this->normalizeDeclarations(declarations: $declarations) as $key => $window) {
			if ($window === null) {
				unset($stored[$key]);
				continue;
			}

			$stored[$key] = $window;
		}

		$this->appConfig->setValueString(AppIdentity::APP_ID, self::CONFIG_KEY, (string)json_encode($stored));

		return $stored;
	}//end declareMany()

	/**
	 * A `provider/model` key, trimmed, with an allowed provider and a model.
	 *
	 * @param string $key The key as sent.
	 *
	 * @return string The key.
	 *
	 * @throws InvalidArgumentException When it is malformed.
	 */
	private function validKey(string $key): string {
		$slash = strpos($key, '/');
		if ($slash === false) {
			throw new InvalidArgumentException("Model key '{$key}' must be 'provider/model'.");
		}

		$provider = substr($key, 0, $slash);
		$model = trim(substr($key, ($slash + 1)));
		if (in_array($provider, LlmSettingsHandler::ALLOWED_CHAT_PROVIDERS, true) === false) {
			throw new InvalidArgumentException(
				"Unsupported provider '{$provider}', must be one of: " . implode(', ', LlmSettingsHandler::ALLOWED_CHAT_PROVIDERS)
			);
		}

		if ($model === '') {
			throw new InvalidArgumentException("Model key '{$key}' names no model.");
		}

		return $provider . '/' . $model;
	}//end validKey()

	/**
	 * A window of 1 to MAX_WINDOW whole tokens, or null to remove it.
	 *
	 * @param string $key    The key, for the message.
	 * @param mixed  $window The window as sent.
	 *
	 * @return int|null The window.
	 *
	 * @throws InvalidArgumentException When it is not a whole number in range.
	 */
	private function validWindow(string $key, mixed $window): ?int {
		if ($window === null || $window === '') {
			return null;
		}

		if (is_int($window) === false && (is_string($window) === false || ctype_digit($window) === false)) {
			throw new InvalidArgumentException("The context window for '{$key}' must be a whole number of tokens.");
		}

		$tokens = (int)$window;
		if ($tokens < 1 || $tokens > self::MAX_WINDOW) {
			throw new InvalidArgumentException(
				"The context window for '{$key}' must be between 1 and " . self::MAX_WINDOW . ' tokens.'
			);
		}

		return $tokens;
	}//end validWindow()
}//end class
