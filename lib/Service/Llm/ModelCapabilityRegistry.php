<?php

/**
 * Hermiq ModelCapabilityRegistry.
 *
 * Which inputs a model reads natively (`image`, `pdf`, or neither), as declared by
 * the administrator who configured it. Never inferred from a model name: names are
 * reused and aliases are local, and a wrong guess either sends a model an image it
 * drops without a word or holds back one it could read.
 *
 * Stored as one `IAppConfig` JSON map (`hermiq.modelCapabilities`) keyed by
 * `provider/model`, the same administered shape as ProviderResidencyRegistry. The
 * key splits on its FIRST slash only, because Fireworks model ids carry slashes
 * themselves (`accounts/fireworks/models/...`). An undeclared model has no
 * capability; an explicit empty list means the administrator declared "neither".
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Llm
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
 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-a-model-that-reads-images-or-pdfs-natively-gets-them-natively-req-catt-004
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Llm;

use InvalidArgumentException;
use OCA\Hermiq\AppInfo\Application;
use OCP\IAppConfig;

/**
 * Reads and writes the administered input capabilities of each provider and model.
 *
 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-a-model-that-reads-images-or-pdfs-natively-gets-them-natively-req-catt-004
 */
class ModelCapabilityRegistry {

	/**
	 * The model reads images natively.
	 *
	 * @var string
	 */
	public const CAPABILITY_IMAGE = 'image';

	/**
	 * The model reads PDF documents natively.
	 *
	 * @var string
	 */
	public const CAPABILITY_PDF = 'pdf';

	/**
	 * Every capability an administrator may declare, in the order they are returned.
	 *
	 * @var list<string>
	 */
	public const DECLARABLE_CAPABILITIES = [
		self::CAPABILITY_IMAGE,
		self::CAPABILITY_PDF,
	];

	/**
	 * The app config key holding the JSON map.
	 *
	 * @var string
	 */
	private const CONFIG_KEY = 'modelCapabilities';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig App config holding `hermiq.modelCapabilities`.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Every declared model with its capabilities, keyed by `provider/model`.
	 *
	 * Stored values outside the declarable set are dropped on read.
	 *
	 * @return array<string, list<string>> The declared map.
	 *
	 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-a-model-that-reads-images-or-pdfs-natively-gets-them-natively-req-catt-004
	 */
	public function all(): array {
		$out = [];
		foreach ($this->stored() as $key => $capabilities) {
			$out[(string)$key] = $this->canonical(capabilities: $capabilities);
		}

		return $out;
	}//end all()

	/**
	 * The capabilities declared for one model; empty when undeclared.
	 *
	 * @param string $provider The provider id (`openai`, `ollama`, ...).
	 * @param string $model    The model id as configured.
	 *
	 * @return list<string> The declared capabilities.
	 *
	 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-a-model-that-reads-images-or-pdfs-natively-gets-them-natively-req-catt-004
	 */
	public function forModel(string $provider, string $model): array {
		$stored = $this->stored();
		$key = $provider . '/' . $model;

		return $this->canonical(capabilities: ($stored[$key] ?? []));
	}//end forModel()

	/**
	 * Whether a model was declared to read one kind of input natively.
	 *
	 * @param string $provider   The provider id.
	 * @param string $model      The model id.
	 * @param string $capability `image` or `pdf`.
	 *
	 * @return bool True only when an administrator declared it.
	 *
	 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-a-model-that-reads-images-or-pdfs-natively-gets-them-natively-req-catt-004
	 */
	public function supports(string $provider, string $model, string $capability): bool {
		return in_array($capability, $this->forModel(provider: $provider, model: $model), true);
	}//end supports()

	/**
	 * Declare the capabilities of one model, replacing what it had.
	 *
	 * @param string       $provider     The provider id.
	 * @param string       $model        The model id.
	 * @param list<string> $capabilities The capabilities; empty declares "neither".
	 *
	 * @return list<string> The stored capabilities.
	 *
	 * @throws InvalidArgumentException When the provider, model or a capability is not valid.
	 *
	 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-a-model-that-reads-images-or-pdfs-natively-gets-them-natively-req-catt-004
	 */
	public function declare(string $provider, string $model, array $capabilities): array {
		$declared = $this->declareMany(declarations: [$provider . '/' . $model => $capabilities]);

		return $declared[$provider . '/' . trim($model)];
	}//end declare()

	/**
	 * Declare several models at once: every entry is validated before anything is written.
	 *
	 * Models not named keep what they had.
	 *
	 * @param array<mixed> $declarations Map of `provider/model` to a capability list.
	 *
	 * @return array<string, list<string>> The full declared map after the write.
	 *
	 * @throws InvalidArgumentException When any entry is not valid; nothing is written then.
	 *
	 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-a-model-that-reads-images-or-pdfs-natively-gets-them-natively-req-catt-004
	 */
	public function declareMany(array $declarations): array {
		$normalized = $this->normalizeDeclarations(declarations: $declarations);

		$stored = $this->all();
		foreach ($normalized as $key => $capabilities) {
			$stored[$key] = $capabilities;
		}

		$this->appConfig->setValueString(
			Application::APP_ID,
			self::CONFIG_KEY,
			(string)json_encode($stored)
		);

		return $stored;
	}//end declareMany()

	/**
	 * Validate a declaration map and return it in canonical form, without writing.
	 *
	 * @param array<mixed> $declarations Map of `provider/model` to a capability list.
	 *
	 * @return array<string, list<string>> The validated map.
	 *
	 * @throws InvalidArgumentException When a key or a capability is not valid.
	 *
	 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-a-model-that-reads-images-or-pdfs-natively-gets-them-natively-req-catt-004
	 */
	public function normalizeDeclarations(array $declarations): array {
		$out = [];
		foreach ($declarations as $key => $capabilities) {
			$out[$this->validKey(key: (string)$key)] = $this->validCapabilities(
				key: (string)$key,
				capabilities: $capabilities
			);
		}

		return $out;
	}//end normalizeDeclarations()

	/**
	 * Validate a `provider/model` key and return it with the model trimmed.
	 *
	 * @param string $key The key.
	 *
	 * @return string The valid key.
	 *
	 * @throws InvalidArgumentException When the key names no supported provider or no model.
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
	 * Validate a capability list and return it deduplicated in canonical order.
	 *
	 * @param string $key          The key, for the message.
	 * @param mixed  $capabilities The submitted list.
	 *
	 * @return list<string> The valid capabilities.
	 *
	 * @throws InvalidArgumentException When it is not a list of declarable capabilities.
	 */
	private function validCapabilities(string $key, mixed $capabilities): array {
		if (is_array($capabilities) === false) {
			throw new InvalidArgumentException("Capabilities for '{$key}' must be a list.");
		}

		foreach ($capabilities as $capability) {
			if (in_array($capability, self::DECLARABLE_CAPABILITIES, true) === false) {
				$shown = (string)json_encode($capability);
				throw new InvalidArgumentException(
					"Unsupported capability {$shown} for '{$key}', must be one of: " . implode(', ', self::DECLARABLE_CAPABILITIES)
				);
			}
		}

		return $this->canonical(capabilities: $capabilities);
	}//end validCapabilities()

	/**
	 * Keep only declarable capabilities, once each, in the declared order of the set.
	 *
	 * @param mixed $capabilities A stored or submitted value.
	 *
	 * @return list<string> The canonical list.
	 */
	private function canonical(mixed $capabilities): array {
		if (is_array($capabilities) === false) {
			return [];
		}

		return array_values(
			array_filter(
				self::DECLARABLE_CAPABILITIES,
				static fn (string $capability): bool => in_array($capability, $capabilities, true)
			)
		);
	}//end canonical()

	/**
	 * The decoded stored map; empty when unset or not a JSON object.
	 *
	 * @return array<mixed> The stored map.
	 */
	private function stored(): array {
		$raw = $this->appConfig->getValueString(Application::APP_ID, self::CONFIG_KEY, '');
		if ($raw === '') {
			return [];
		}

		$decoded = json_decode($raw, true);
		if (is_array($decoded) === false) {
			return [];
		}

		return $decoded;
	}//end stored()
}//end class
