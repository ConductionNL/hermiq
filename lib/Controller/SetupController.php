<?php

/**
 * Hermiq first-time setup contract (ADR-042).
 *
 * Backs the shared CnSetupWizard the same way every other Conduction app does:
 *   - status    (GET  /api/setup/status)            — per-step completion for the wizard + summary.
 *   - saveConfig (POST /api/setup/config)           — persist `config-fields` values to app-config.
 *   - runAction  (POST /api/setup/action/{actionId})— run a privileged server-side action; here the
 *                                                      only action is `test-llm`, which probes the
 *                                                      configured Ollama endpoint (the browser cannot
 *                                                      reach the model host directly) and records the
 *                                                      first advertised model as the default.
 *
 * The endpoint probe is allow-listed to loopback / host.docker.internal so it can never become an
 * SSRF primitive. saveConfig/runAction are admin-only (no @NoAdminRequired → admin by default);
 * status is readable by any logged-in user so the shell never 403s on load.
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
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\Service\DemoDataService;
use OCA\Hermiq\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * First-time setup status + actions for the shared CnSetupWizard.
 *
 * @spec exclude Standard ADR-042 setup contract adoption — framework wiring for
 *   the shared CnSetupWizard, no per-app behavioural spec of its own.
 */
class SetupController extends Controller {

	/**
	 * Setup contract version; matches manifest.setup.version.
	 *
	 * @var int
	 */
	/**
	 * App-config key recording that the demo-data step was DEALT WITH.
	 *
	 * Not "objects exist": an operator who declines has finished the step, and
	 * re-offering the import every visit would make "no thanks" impossible to
	 * express — and would leave the wizard open over every page.
	 *
	 * @var string
	 */
	private const DEMO_DECIDED_KEY = 'demo_data_decided';

	private const SETUP_VERSION = 1;

	/**
	 * App-config keys the wizard is allowed to write via saveConfig. Kept to an
	 * allow-list so the setup endpoint can never be used to write arbitrary
	 * app-config values.
	 *
	 * @var array<int, string>
	 */
	private const WRITABLE_KEYS = ['llmendpoint'];

	/**
	 * Default Ollama endpoint when the admin has not configured one yet.
	 *
	 * @var string
	 */
	private const DEFAULT_ENDPOINT = 'http://host.docker.internal:11434';

	/**
	 * Hosts an LLM endpoint may point at. Keeps the probe from becoming an SSRF
	 * primitive — the realistic Ollama locations in a Nextcloud deployment are
	 * the loopback interface or the Docker host gateway.
	 *
	 * @var array<int, string>
	 */
	private const ALLOWED_LLM_HOSTS = ['localhost', '127.0.0.1', '::1', 'host.docker.internal'];

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param IClientService $clientService Nextcloud HTTP client factory.
	 * @param IAppConfig $appConfig App-config reader/writer.
	 * @param LoggerInterface $logger PSR-3 logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly IClientService $clientService,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly DemoDataService $demoDataService,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * Report per-step setup status for the wizard.
	 *
	 * @return JSONResponse `{ version, completed, steps: { <id>: { done } } }`.
	 *
	 * @spec exclude First-time-setup status; no behavioural spec.
	 *
	 * @no-admin-idor-exempt Availability probe. Takes no caller-supplied object
	 * id and reads no user or organisation data — it reports whether the
	 * instance-wide first-run wizard has been completed, so that a non-admin
	 * loading the app is not shown a setup prompt they cannot action. The
	 * response is a version number and one boolean per step; it exposes no
	 * configuration VALUES (the LLM endpoint and credentials are never in it),
	 * only whether the LLM connection has been tested. Deliberately readable by
	 * every authenticated user because every authenticated user renders the
	 * shell that consumes it; the privileged half of the wizard (saveConfig,
	 * runAction) is admin-gated separately.
	 *
	 * @NoAdminRequired
	 */
	public function status(): JSONResponse {
		$llmTested = $this->config(key: 'setup_llm_tested') === '1';
		$demoDecided = $this->appConfig->getValueString(Application::APP_ID, self::DEMO_DECIDED_KEY, '') !== '';
		// `completed` stays the REQUIRED step alone. Demo data is optional, so
		// it must not gate the app — it only needs to be reportable, so the
		// wizard can stop asking once it has an answer.
		$completed = $llmTested;

		if ($completed === true) {
			$this->appConfig->setValueString(Application::APP_ID, 'setup_completed_version', (string)self::SETUP_VERSION);
		}

		return new JSONResponse(
			data: [
				'version' => self::SETUP_VERSION,
				'completed' => $completed,
				'steps' => [
					// DEALT WITH, not "objects exist" — see DEMO_DECIDED_KEY.
					// A step the wizard can never mark done keeps the dialog
					// open over every page, which since nextcloud-vue 2.21 is
					// enough on its own: an OUTSTANDING OPTIONAL step opens the
					// wizard (nextcloud-vue#806).
					'demo-data' => ['done' => $demoDecided],
					'test-llm' => ['done' => $llmTested],
				],
			]
		);

	}//end status()

	/**
	 * Persist app-config values from a `config-fields` step (admin-only).
	 *
	 * The "(admin-only)" above was previously enforced ONLY by Nextcloud's
	 * default for an un-attributed method — a comment and an implicit default,
	 * with nothing tying them together. This writes app config (the WRITABLE_KEYS
	 * allowlist below is the only other limit on it), so the requirement is now
	 * declared and delegated-admin-aware.
	 *
	 * @return JSONResponse `{ success }`.
	 *
	 * @spec exclude First-time-setup config persistence; no behavioural spec.
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function saveConfig(): JSONResponse {
		foreach ($this->request->getParams() as $key => $value) {
			if (in_array($key, self::WRITABLE_KEYS, true) === false) {
				continue;
			}

			$stored = '';
			if (is_scalar($value) === true) {
				$stored = (string)$value;
			}

			$this->appConfig->setValueString(
				Application::APP_ID,
				(string)$key,
				$stored,
			);
		}

		return new JSONResponse(data: ['success' => true]);
	}//end saveConfig()

	/**
	 * Run a privileged server-side setup action (admin-only).
	 *
	 * "Privileged" is literal: `test-llm` makes the server issue an outbound
	 * HTTP request to a configured endpoint, so an unauthenticated caller would
	 * have a server-side request forgery primitive. It was admin-gated only by
	 * Nextcloud's default for an un-attributed method; that is now declared.
	 *
	 * @param string $actionId The action to run (only `test-llm`).
	 *
	 * @return JSONResponse `{ success, message }`.
	 *
	 * @spec exclude First-time-setup action dispatch; no behavioural spec.
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function runAction(string $actionId): JSONResponse {
		if ($actionId === 'test-llm') {
			return $this->testLlm();
		}

		if ($actionId === 'install-demo-data') {
			return $this->installDemoData();
		}

		// DECLINING IS AN ANSWER. Without this the wizard re-offers the import
		// on every visit, so "no thanks" is not expressible and the step never
		// closes — which is also what keeps the dialog over the page.
		if ($actionId === 'skip-demo-data') {
			$this->appConfig->setValueString(Application::APP_ID, self::DEMO_DECIDED_KEY, 'skipped');
			return new JSONResponse(data: ['success' => true, 'message' => 'Demo data skipped.']);
		}

		return new JSONResponse(
			data: ['success' => false, 'message' => 'Unknown setup action: ' . $actionId],
			statusCode: Http::STATUS_NOT_FOUND,
		);

	}//end runAction()

	/**
	 * Import the shipped demo dataset.
	 *
	 * Reports the FAILURE, rather than a quiet success: an operator who asked
	 * for demo data and got none must be told, and `DemoDataService::install()`
	 * throws instead of returning an empty result for exactly that reason.
	 *
	 * @return JSONResponse `{ success, message }`.
	 *
	 * @spec exclude First-time-setup action dispatch; no behavioural spec.
	 */
	private function installDemoData(): JSONResponse {
		try {
			$imported = $this->demoDataService->install();
		} catch (\Throwable $e) {
			$this->logger->error(
				'Hermiq setup install-demo-data failed: ' . $e->getMessage(),
				['app' => Application::APP_ID, 'exception' => $e]
			);
			return new JSONResponse(
				data: ['success' => false, 'message' => 'Could not import the demo data: ' . $e->getMessage()],
				statusCode: Http::STATUS_INTERNAL_SERVER_ERROR,
			);
		}

		$this->appConfig->setValueString(Application::APP_ID, self::DEMO_DECIDED_KEY, 'installed');

		return new JSONResponse(
			data: [
				'success' => true,
				'message' => 'Imported ' . $imported['objects'] . ' demo object(s).',
			]
		);

	}//end installDemoData()

	/**
	 * Probe the configured LLM endpoint (Ollama /api/tags), record the first
	 * advertised model as the default, and mark the LLM step done.
	 *
	 * @return JSONResponse `{ success, message }`.
	 */
	private function testLlm(): JSONResponse {
		$base = trim($this->config(key: 'llmendpoint'));
		if ($base === '') {
			$base = self::DEFAULT_ENDPOINT;
		}

		if ($this->isAllowedEndpoint(endpoint: $base) === false) {
			return new JSONResponse(
				data: [
					'success' => false,
					'message' => 'Endpoint host not allowed. Use localhost or host.docker.internal '
						. '(configure a remote model host in OpenRegister).',
				]
			);
		}

		$url = rtrim($base, '/') . '/api/tags';

		try {
			$client = $this->clientService->newClient();
			// Opt this single request past Nextcloud's local-address guard: the LLM host is a
			// loopback / Docker-gateway service (the realistic Ollama location), and the host is
			// already constrained to the ALLOWED_LLM_HOSTS allow-list above, so this is not an
			// open SSRF. The global guard stays on for every other request.
			$response = $client->get(
				$url,
				[
					'timeout' => 5,
					'connect_timeout' => 3,
					'nextcloud' => ['allow_local_address' => true],
				]
			);
			$decoded = json_decode((string)$response->getBody(), true);

			$models = [];
			foreach (($decoded['models'] ?? []) as $model) {
				$name = (string)($model['name'] ?? '');
				if ($name !== '') {
					$models[] = $name;
				}
			}

			if ($models === []) {
				return new JSONResponse(
					data: ['success' => false, 'message' => 'Reachable, but the host advertises no models. Pull a model on the Ollama host first.']
				);
			}

			$this->appConfig->setValueString(Application::APP_ID, 'defaultmodel', $models[0]);
			$this->appConfig->setValueString(Application::APP_ID, 'setup_llm_tested', '1');

			$sample = implode(', ', array_slice($models, 0, 4));
			$plural = 's';
			if (count($models) === 1) {
				$plural = '';
			}

			return new JSONResponse(
				data: [
					'success' => true,
					'message' => sprintf(
						'Connected — %d model%s (%s). Default set to "%s".',
						count($models),
						$plural,
						$sample,
						$models[0],
					),
				]
			);
		} catch (Throwable $e) {
			$this->logger->debug('[Hermiq] LLM test failed: ' . $e->getMessage());
			return new JSONResponse(
				data: ['success' => false, 'message' => 'Not reachable: ' . $e->getMessage()]
			);
		}//end try

	}//end testLlm()

	/**
	 * Whether an LLM endpoint URL is a well-formed http(s) URL pointing at an allow-listed host.
	 *
	 * @param string $endpoint The endpoint URL.
	 *
	 * @return bool True when the endpoint may be probed.
	 */
	private function isAllowedEndpoint(string $endpoint): bool {
		$parts = parse_url($endpoint);
		if ($parts === false || isset($parts['scheme']) === false || isset($parts['host']) === false) {
			return false;
		}

		if (in_array(strtolower($parts['scheme']), ['http', 'https'], true) === false) {
			return false;
		}

		return in_array(strtolower($parts['host']), self::ALLOWED_LLM_HOSTS, true);
	}//end isAllowedEndpoint()

	/**
	 * Read a Hermiq app-config string value.
	 *
	 * @param string $key The config key.
	 *
	 * @return string The value, or '' when unset.
	 */
	private function config(string $key): string {
		return $this->appConfig->getValueString(Application::APP_ID, $key, '');
	}//end config()
}//end class
