<?php

/**
 * Hermiq FormFillController (forms-smart-paste-fill).
 *
 * The REST surface a form renderer calls for "Paste to fill". Authentication,
 * the input allowlist and the limits live here; the gate, the prompt, the
 * provider call, the checks and the call log live in `FormFillEngine`, mirroring
 * `LessonAuthoringController`. Only `text`, `fields` and `language` are read, so
 * nothing else a client sends can reach a prompt.
 *
 * @category Controller
 * @package  OCA\Hermiq\Controller
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
 * @spec openspec/changes/forms-smart-paste-fill/specs/form-fill/spec.md#requirement-a-signed-in-user-asks-for-proposals-req-ffill-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use InvalidArgumentException;
use OCA\Hermiq\Kernel\AppIdentity;
use OCA\Hermiq\Kernel\Contracts\FormFillEngineInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Form fill endpoints: proposals and availability.
 *
 * @spec openspec/changes/forms-smart-paste-fill/specs/form-fill/spec.md#requirement-a-signed-in-user-asks-for-proposals-req-ffill-002
 */
class FormFillController extends Controller {

	/**
	 * The longest pasted text accepted, in characters.
	 *
	 * @var int
	 */
	public const MAX_TEXT = 10000;

	/**
	 * The most fields accepted in one call.
	 *
	 * @var int
	 */
	public const MAX_FIELDS = 50;

	/**
	 * The longest field label accepted, in characters.
	 *
	 * @var int
	 */
	public const MAX_LABEL = 200;

	/**
	 * The most options one field may carry.
	 *
	 * @var int
	 */
	public const MAX_OPTIONS = 100;

	/**
	 * The language used when the caller names none.
	 *
	 * @var string
	 */
	public const DEFAULT_LANGUAGE = 'nl';

	/**
	 * A field key (D2).
	 *
	 * @var string
	 */
	private const KEY_PATTERN = '/^[A-Za-z0-9_.-]{1,64}$/';

	/**
	 * A field type: a short lowercase word.
	 *
	 * @var string
	 */
	private const TYPE_PATTERN = '/^[a-z][a-z-]{0,31}$/';

	/**
	 * A BCP-47 language tag: a 2 or 3 letter language, up to two subtags.
	 *
	 * @var string
	 */
	private const LANGUAGE_PATTERN = '/^[a-zA-Z]{2,3}(-[a-zA-Z0-9]{2,8}){0,2}$/';

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request object.
	 * @param IUserSession $userSession Resolves the requesting user (401 if absent).
	 * @param FormFillEngineInterface $engine The gated form fill pipeline.
	 * @param LoggerInterface $logger PSR-3 logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly FormFillEngineInterface $engine,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Propose values for `fields` from `text`.
	 *
	 * @return JSONResponse The engine's envelope, or a 400/401/500 error.
	 *
	 * @no-admin-idor-exempt Looks up no caller-supplied id: pasted text in, proposals out, no object to authorise.
	 *
	 * @spec openspec/changes/forms-smart-paste-fill/specs/form-fill/spec.md#requirement-a-signed-in-user-asks-for-proposals-req-ffill-002
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	public function fill(): JSONResponse {
		$userId = $this->currentUserId();
		if ($userId === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$text = $this->text();
			$fields = $this->fields();
			$language = $this->language();
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		try {
			return new JSONResponse($this->engine->fill(text: $text, fields: $fields, language: $language, userId: $userId));
		} catch (Throwable $e) {
			$this->logger->error(
				message: 'Hermiq form-fill request failed: ' . $e->getMessage(),
				context: ['exceptionClass' => get_class($e), 'file' => $e->getFile(), 'line' => $e->getLine()]
			);
			return new JSONResponse(['error' => 'Could not propose form values'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end fill()

	/**
	 * Whether "Paste to fill" may show: the gate only, no model call.
	 *
	 * @return JSONResponse `{available: bool}`, or 401.
	 *
	 * @no-admin-idor-exempt Looks up no caller-supplied id: answers one feature flag.
	 *
	 * @spec openspec/changes/forms-smart-paste-fill/specs/form-fill/spec.md#requirement-form-fill-is-off-until-an-admin-acknowledges-it-req-ffill-001
	 */
	#[NoAdminRequired]
	public function available(): JSONResponse {
		if ($this->currentUserId() === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			return new JSONResponse(['available' => $this->engine->isAvailable()]);
		} catch (Throwable $e) {
			$this->logger->warning(message: 'Hermiq form-fill availability failed: ' . $e->getMessage());
			return new JSONResponse(['available' => false]);
		}
	}//end available()

	/**
	 * The signed-in user's id, or null.
	 *
	 * @return string|null
	 */
	private function currentUserId(): ?string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return null;
		}

		return $user->getUID();
	}//end currentUserId()

	/**
	 * `text`: 1 to MAX_TEXT characters.
	 *
	 * @return string
	 *
	 * @throws InvalidArgumentException When it is missing, not a string or too long.
	 */
	private function text(): string {
		$value = $this->request->getParam('text');
		if (is_string($value) === false || trim($value) === '') {
			throw new InvalidArgumentException('text is required');
		}

		if (mb_strlen($value) > self::MAX_TEXT) {
			throw new InvalidArgumentException('text may be at most ' . self::MAX_TEXT . ' characters');
		}

		return $value;
	}//end text()

	/**
	 * `fields`: 1 to MAX_FIELDS entries of `{key, label, type, options?}`.
	 *
	 * @return array<int, array{key: string, label: string, type: string, options: array<int, string>}>
	 *
	 * @throws InvalidArgumentException When the list or an entry is unusable.
	 */
	private function fields(): array {
		$value = $this->request->getParam('fields');
		if (is_array($value) === false || $value === []) {
			throw new InvalidArgumentException('fields is required');
		}

		if (count($value) > self::MAX_FIELDS) {
			throw new InvalidArgumentException('fields may hold at most ' . self::MAX_FIELDS . ' entries');
		}

		$fields = [];
		$seen = [];
		foreach (array_values($value) as $position => $entry) {
			$field = $this->field(entry: $entry, position: $position);
			if (isset($seen[$field['key']]) === true) {
				throw new InvalidArgumentException('fields[' . $position . '].key repeats "' . $field['key'] . '"');
			}

			$seen[$field['key']] = true;
			$fields[] = $field;
		}

		return $fields;
	}//end fields()

	/**
	 * One field entry, its label and options on one line each.
	 *
	 * @param mixed $entry The entry as sent.
	 * @param int $position Its position, for the message.
	 *
	 * @return array{key: string, label: string, type: string, options: array<int, string>}
	 *
	 * @throws InvalidArgumentException When the entry is unusable.
	 */
	private function field(mixed $entry, int $position): array {
		$path = 'fields[' . $position . ']';
		if (is_array($entry) === false) {
			throw new InvalidArgumentException($path . ' must be an object with key, label and type');
		}

		$key = ($entry['key'] ?? null);
		if (is_string($key) === false || preg_match(self::KEY_PATTERN, $key) !== 1) {
			throw new InvalidArgumentException($path . '.key must match ' . trim(self::KEY_PATTERN, '/'));
		}

		$type = ($entry['type'] ?? 'text');
		if (is_string($type) === false || preg_match(self::TYPE_PATTERN, $type) !== 1) {
			throw new InvalidArgumentException($path . '.type must be a short lowercase word, for example "text" or "date"');
		}

		return [
			'key' => $key,
			'label' => self::oneLine(value: ($entry['label'] ?? $key), path: $path . '.label'),
			'type' => $type,
			'options' => $this->options(value: ($entry['options'] ?? []), path: $path . '.options'),
		];
	}//end field()

	/**
	 * A field's options as strings; an option object gives its `value`.
	 *
	 * @param mixed $value The options as sent.
	 * @param string $path The field path, for the message.
	 *
	 * @return array<int, string>
	 *
	 * @throws InvalidArgumentException When they are not a short list of texts.
	 */
	private function options(mixed $value, string $path): array {
		if ($value === null) {
			return [];
		}

		if (is_array($value) === false || count($value) > self::MAX_OPTIONS) {
			throw new InvalidArgumentException($path . ' must be a list of at most ' . self::MAX_OPTIONS . ' options');
		}

		$options = [];
		foreach (array_values($value) as $option) {
			if (is_array($option) === true) {
				$option = ($option['value'] ?? null);
			}

			if (is_int($option) === true || is_float($option) === true) {
				$option = (string)$option;
			}

			$options[] = self::oneLine(value: $option, path: $path);
		}

		return $options;
	}//end options()

	/**
	 * A short text on one line, so it cannot open a new line in the prompt.
	 *
	 * @param mixed $value The value.
	 * @param string $path Where it came from, for the message.
	 *
	 * @return string
	 *
	 * @throws InvalidArgumentException When it is not a text of 1 to MAX_LABEL characters.
	 */
	private static function oneLine(mixed $value, string $path): string {
		if (is_string($value) === false) {
			throw new InvalidArgumentException($path . ' must be a text');
		}

		$line = trim((string)preg_replace('/\s+/u', ' ', $value));
		if ($line === '' || mb_strlen($line) > self::MAX_LABEL) {
			throw new InvalidArgumentException($path . ' must be 1 to ' . self::MAX_LABEL . ' characters');
		}

		return $line;
	}//end oneLine()

	/**
	 * `language`, a BCP-47 tag; DEFAULT_LANGUAGE when absent.
	 *
	 * @return string
	 *
	 * @throws InvalidArgumentException When it is not a language tag.
	 */
	private function language(): string {
		$value = $this->request->getParam('language', self::DEFAULT_LANGUAGE);
		if ($value === null || $value === '') {
			return self::DEFAULT_LANGUAGE;
		}

		if (is_string($value) === false || preg_match(self::LANGUAGE_PATTERN, $value) !== 1) {
			throw new InvalidArgumentException('language must be a BCP-47 language tag, for example "nl" or "en"');
		}

		return $value;
	}//end language()
}//end class
