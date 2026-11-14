<?php

/**
 * Hermiq SubscriptionLoginService.
 *
 * Signs a user in with their model subscription through the vendor's own CLI
 * (models-provider-connections D5, D6). The CLI runs inside the hermiq-llm-runner
 * ExApp; hermiq is never an OAuth client of the vendor.
 *
 *   1. {@see start()} mints a SIGN-IN run token that may reach only the vendor's
 *      sign-in hosts for ten minutes, and asks the runner to start the CLI's
 *      sign-in. The browser gets the sign-in URL (and a device code).
 *   2. {@see finish()} hands the code the vendor's page showed to the runner,
 *      receives the token once, and writes it straight into OpenRegister's
 *      credential broker as the session user's PERSONAL credential. The browser
 *      gets the credential's id and label only; the token is kept nowhere else
 *      and never logged. The sign-in token is spent at once.
 *
 * A subscription stays personal: the scope is fixed here, not taken from input.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Credential
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Credential;

use OCA\Hermiq\Service\Llm\BrokerHttpClient;
use OCA\Hermiq\Service\Llm\RunTokenRequest;
use OCA\Hermiq\Service\Llm\RunTokenService;
use OCP\App\IAppManager;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\Security\ISecureRandom;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Subscription sign-in through the llm-runner ExApp, token straight to the broker.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) One flow across AppAPI, the run
 *   token service, the cache and the broker; each is used once.
 */
class SubscriptionLoginService {

	/**
	 * How long a sign-in may stay open, in seconds; the runner uses the same limit.
	 *
	 * @var int
	 */
	public const LOGIN_TTL_SECONDS = 600;

	/**
	 * Per provider: the broker credential type a sign-in creates, its label, and
	 * the only hosts its CLI may reach while signing in. Hermiq's list, not the
	 * runner's: the runner says which sign-ins it can run, hermiq says where they go.
	 *
	 * @var array<string, array{credentialProvider: string, label: string, hosts: list<string>}>
	 */
	private const PROVIDERS = [
		'anthropic' => [
			'credentialProvider' => 'anthropic-cli',
			'label' => 'Claude subscription',
			'hosts' => ['claude.ai', 'console.anthropic.com', 'api.anthropic.com'],
		],
		'openai' => [
			'credentialProvider' => 'openai-cli',
			'label' => 'ChatGPT subscription',
			'hosts' => ['auth.openai.com', 'chatgpt.com'],
		],
	];

	/**
	 * The AppAPI app id.
	 *
	 * @var string
	 */
	private const APP_API_ID = 'app_api';

	/**
	 * AppAPI's public seam, named by class-string so hermiq boots without AppAPI.
	 *
	 * @var string
	 */
	private const APP_API_PUBLIC_FUNCTIONS = 'OCA\\AppAPI\\PublicFunctions';

	/**
	 * The runner ExApp id.
	 *
	 * @var string
	 */
	private const RUNNER_EXAPP_ID = 'hermiq-llm-runner';

	/**
	 * OpenRegister's mint value objects, named by class-string like the broker.
	 *
	 * @var string
	 */
	private const MINT_CLASS = 'OCA\\OpenRegister\\Service\\Credential\\CredentialMint';

	/**
	 * @var string
	 */
	private const MINT_OPTIONS_CLASS = 'OCA\\OpenRegister\\Service\\Credential\\CredentialMintOptions';

	/**
	 * The AppAPI timeout of a call that waits on the CLI: the sign-in URL, or the
	 * exchange after the code. Above the runner's own 30 s URL wait.
	 *
	 * @var int
	 */
	private const RUNNER_TIMEOUT_SECONDS = 120;

	/**
	 * The cache the open sign-ins live in, keyed by user and login id.
	 *
	 * @var ICache|null
	 */
	private ?ICache $cache = null;

	/**
	 * Constructor.
	 *
	 * @param IAppManager|null   $appManager      Detects AppAPI.
	 * @param ContainerInterface $container       Resolves AppAPI and the broker.
	 * @param RunTokenService    $runTokenService Mints and spends the sign-in token.
	 * @param ICacheFactory      $cacheFactory    Holds the open sign-ins.
	 * @param ISecureRandom      $secureRandom    The sign-in's run id.
	 * @param LoggerInterface    $logger          Token-free log lines.
	 */
	public function __construct(
		private readonly ?IAppManager $appManager,
		private readonly ContainerInterface $container,
		private readonly RunTokenService $runTokenService,
		private readonly ICacheFactory $cacheFactory,
		private readonly ISecureRandom $secureRandom,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Which subscription sign-ins this instance offers.
	 *
	 * @param string $uid The session user.
	 *
	 * @return array{available: bool, providers: list<array{provider: string, mode: string}>} `available`
	 *         is false when the runner ExApp is not there; `providers` lists the sign-ins the runner can run.
	 *
	 * @spec openspec/changes/models-provider-connections
	 */
	public function offered(string $uid): array {
		if ($this->runnerAvailable() === false) {
			return ['available' => false, 'providers' => []];
		}

		try {
			$answer = $this->runnerJson(uid: $uid, route: '/login', method: 'GET', params: []);
		} catch (SubscriptionLoginException $e) {
			// A runner without the sign-in route offers no sign-in.
			return ['available' => true, 'providers' => []];
		}

		$providers = [];
		foreach ((array)($answer['providers'] ?? []) as $entry) {
			$provider = (string)($entry['provider'] ?? '');
			if (isset(self::PROVIDERS[$provider]) === true) {
				$providers[] = ['provider' => $provider, 'mode' => (string)($entry['mode'] ?? '')];
			}
		}

		return ['available' => true, 'providers' => $providers];
	}//end offered()

	/**
	 * Start a sign-in for the session user.
	 *
	 * @param string $uid      The session user.
	 * @param string $provider `anthropic` or `openai`.
	 *
	 * @return array{loginId: string, url: string, mode: string, userCode: string} What the browser shows.
	 *
	 * @throws SubscriptionLoginException When the provider is unknown (400) or the runner is not there (503).
	 *
	 * @spec openspec/changes/models-provider-connections
	 */
	public function start(string $uid, string $provider): array {
		if (isset(self::PROVIDERS[$provider]) === false) {
			throw new SubscriptionLoginException('This subscription cannot be signed in to.', 400);
		}

		if ($this->runnerAvailable() === false) {
			throw new SubscriptionLoginException('Subscription sign-in needs the Hermiq LLM runner app.', 503);
		}

		$request = new RunTokenRequest(
			runId: 'sign-in:' . $this->secureRandom->generate(16, ISecureRandom::CHAR_ALPHANUMERIC),
			agentId: ''
		);
		$request->userId = $uid;
		$request->ttlSeconds = self::LOGIN_TTL_SECONDS;
		$request->egressHosts = self::PROVIDERS[$provider]['hosts'];
		$runToken = $this->runTokenService->mintFor(request: $request);

		$answer = $this->runnerJson(
			uid: $uid,
			route: '/login',
			method: 'POST',
			params: ['provider' => $provider, 'runToken' => $runToken]
		);
		$loginId = (string)($answer['loginId'] ?? '');
		$url = (string)($answer['url'] ?? '');
		if (preg_match('/^[0-9a-f-]{36}$/', $loginId) !== 1 || str_starts_with($url, 'https://') === false) {
			$this->runTokenService->consume(token: $runToken);
			throw new SubscriptionLoginException('The sign-in could not be started. Try again.', 502);
		}

		$this->cache()->set(
			$this->cacheKey(uid: $uid, loginId: $loginId),
			['provider' => $provider, 'runToken' => $runToken],
			self::LOGIN_TTL_SECONDS
		);

		return [
			'loginId' => $loginId,
			'url' => $url,
			'mode' => (string)($answer['mode'] ?? ''),
			'userCode' => (string)($answer['userCode'] ?? ''),
		];
	}//end start()

	/**
	 * Finish a sign-in: the token goes to the broker as the user's personal credential.
	 *
	 * @param string $uid     The session user (only the user who started it can finish it).
	 * @param string $loginId The login id {@see start()} returned.
	 * @param string $code    The one-time code the vendor's page showed ('' for a device sign-in).
	 *
	 * @return array{id: string, label: string} The new credential; never the token.
	 *
	 * @throws SubscriptionLoginException When the sign-in is unknown (404), did not complete (502)
	 *                                    or the broker could not store it (503).
	 *
	 * @spec openspec/changes/models-provider-connections
	 */
	public function finish(string $uid, string $loginId, string $code): array {
		$key = $this->cacheKey(uid: $uid, loginId: $loginId);
		$open = $this->cache()->get($key);
		if (is_array($open) === false || isset(self::PROVIDERS[(string)($open['provider'] ?? '')]) === false) {
			throw new SubscriptionLoginException('This sign-in has expired. Start it again.', 404);
		}

		$this->cache()->remove($key);
		$provider = self::PROVIDERS[(string)$open['provider']];

		try {
			$answer = $this->runnerJson(
				uid: $uid,
				route: '/login/' . $loginId . '/code',
				method: 'POST',
				params: ['code' => $code]
			);
		} finally {
			// The sign-in hosts leave the egress policy the moment the sign-in ends.
			$this->runTokenService->consume(token: (string)($open['runToken'] ?? ''));
		}

		$token = (string)($answer['token'] ?? '');
		if ($token === '') {
			throw new SubscriptionLoginException('The sign-in did not complete. Start it again.', 502);
		}

		$id = $this->storeInBroker(uid: $uid, provider: $provider, token: $token);

		return ['id' => $id, 'label' => $provider['label']];
	}//end finish()

	/**
	 * Mint the token as the user's personal credential, usable by hermiq only.
	 *
	 * @param string                                   $uid      The owner.
	 * @param array{credentialProvider: string, label: string} $provider The provider entry.
	 * @param string                                   $token    The token.
	 *
	 * @return string The credential id.
	 *
	 * @throws SubscriptionLoginException When the broker is not there or refused (503).
	 */
	private function storeInBroker(string $uid, array $provider, #[\SensitiveParameter] string $token): string {
		if (class_exists(BrokerHttpClient::BROKER_CLASS) === false
			|| class_exists(self::MINT_CLASS) === false
			|| class_exists(self::MINT_OPTIONS_CLASS) === false
		) {
			throw new SubscriptionLoginException(
				'The sign-in worked, but OpenRegister\'s credential broker is not available to store it.',
				503
			);
		}

		$mintClass = self::MINT_CLASS;
		$optionsClass = self::MINT_OPTIONS_CLASS;
		try {
			$broker = $this->container->get(BrokerHttpClient::BROKER_CLASS);
			$saved = $broker->mintCredential(
				new $mintClass($provider['label'], $provider['credentialProvider'], $uid),
				new $optionsClass([BrokerHttpClient::APP_ID], $token, 'personal')
			);
			return (string)$saved->getUuid();
		} catch (Throwable $e) {
			// The class only: a broker message is not ours to vouch for.
			$this->logger->warning(
				'[SubscriptionLogin] The credential broker refused a signed-in subscription',
				['provider' => $provider['credentialProvider'], 'exception' => get_class($e)]
			);
			throw new SubscriptionLoginException(
				'The sign-in worked, but the credential could not be stored. Try again.',
				503
			);
		}
	}//end storeInBroker()

	/**
	 * Whether AppAPI and an enabled runner ExApp are there.
	 *
	 * @return bool True when the runner can be asked.
	 */
	private function runnerAvailable(): bool {
		if ($this->appManager === null
			|| $this->appManager->isInstalled(self::APP_API_ID) === false
			|| class_exists(self::APP_API_PUBLIC_FUNCTIONS) === false
		) {
			return false;
		}

		try {
			$exApp = $this->container->get(self::APP_API_PUBLIC_FUNCTIONS)->getExApp(self::RUNNER_EXAPP_ID);
		} catch (Throwable $e) {
			return false;
		}

		return is_array($exApp) === true && (bool)($exApp['enabled'] ?? false) === true;
	}//end runnerAvailable()

	/**
	 * Ask the runner and decode its JSON answer.
	 *
	 * @param string               $uid    The session user.
	 * @param string               $route  The runner route.
	 * @param string               $method The HTTP method.
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return array<string, mixed> The decoded answer.
	 *
	 * @throws SubscriptionLoginException When the runner was not reached or refused.
	 */
	private function runnerJson(string $uid, string $route, string $method, array $params): array {
		$result = $this->container->get(self::APP_API_PUBLIC_FUNCTIONS)->exAppRequest(
			self::RUNNER_EXAPP_ID,
			$route,
			$uid,
			$method,
			$params,
			['timeout' => self::RUNNER_TIMEOUT_SECONDS]
		);

		if (is_array($result) === true) {
			$this->logger->warning('[SubscriptionLogin] The runner could not be reached', ['route' => $method . ' ' . explode('/', ltrim($route, '/'))[0]]);
			throw new SubscriptionLoginException('The Hermiq LLM runner app could not be reached.', 503);
		}

		$status = $result->getStatusCode();
		$answer = json_decode((string)$result->getBody(), true);
		if (is_array($answer) === false) {
			$answer = [];
		}

		if ($status === 404 && str_contains($route, '/code') === true) {
			throw new SubscriptionLoginException('This sign-in has expired. Start it again.', 404);
		}

		if ($status === 400 && ($answer['code'] ?? '') === 'code_required') {
			throw new SubscriptionLoginException('Paste the code the sign-in page shows.', 400);
		}

		if ($status < 200 || $status > 299) {
			$this->logger->warning(
				'[SubscriptionLogin] The runner refused a sign-in step',
				['status' => $status, 'code' => (string)($answer['code'] ?? '')]
			);
			throw new SubscriptionLoginException('The sign-in did not complete. Start it again.', 502);
		}

		return $answer;
	}//end runnerJson()

	/**
	 * The cache key of one user's open sign-in.
	 *
	 * @param string $uid     The user.
	 * @param string $loginId The login id.
	 *
	 * @return string The key.
	 */
	private function cacheKey(string $uid, string $loginId): string {
		return 'login-' . hash('sha256', $uid . "\0" . $loginId);
	}//end cacheKey()

	/**
	 * The distributed cache of open sign-ins.
	 *
	 * @return ICache The cache.
	 */
	private function cache(): ICache {
		$this->cache ??= $this->cacheFactory->createDistributed('hermiq-subscription-login');
		return $this->cache;
	}//end cache()
}//end class
