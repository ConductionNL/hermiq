<?php

/**
 * Hermiq GitHubBrokerClient.
 *
 * Routes GitHubTemplatePushService's GitHub API calls through OpenRegister's
 * credential broker: this app sends only method, path and body, the broker
 * injects the token from its vault, and nothing here ever sees, holds or logs
 * it. Built by that service from its own logger and container.
 *
 * @category Service
 * @package  OCA\Hermiq\Service
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
 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-never-hold-or-log-the-github-token
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use RuntimeException;
use Throwable;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * GitHub API calls through the OpenRegister credential broker.
 *
 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-never-hold-or-log-the-github-token
 */
final class GitHubBrokerClient {

	/**
	 * The OpenRegister credential broker, feature-detected by class-string.
	 *
	 * @var string
	 */
	public const BROKER_CLASS = 'OCA\\OpenRegister\\Service\\Credential\\CredentialBrokerService';

	/**
	 * The app id every brokered call is made as; the broker only serves
	 * credentials whose `allowedApps` contain it.
	 *
	 * @var string
	 */
	private const APP_ID = 'hermiq';

	/**
	 * Build the client.
	 *
	 * @param LoggerInterface $logger Where a failed call is logged (never its body).
	 * @param ContainerInterface|null $container The container the broker is resolved from.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly ?ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * The container the optional OpenRegister credential broker is resolved from.
	 *
	 * The broker is another app's class, feature-detected by class-string, so it can
	 * never be a constructor type here. It used to come from the global server, which
	 * outside a booted Nextcloud autowires from scratch and can recurse through a
	 * constructor cycle until memory runs out. The container is nullable because the
	 * unit tests build this service with a logger alone and never reach a brokered
	 * call; a null is the same dead end as the broker being absent, and the caller
	 * already treats that as a failed call.
	 *
	 * @return ContainerInterface The injected container.
	 *
	 * @throws RuntimeException When the service was built without one.
	 */
	private function container(): ContainerInterface {
		if ($this->container === null) {
			throw new RuntimeException(
				'Hermiq GitHub template publish: this service was constructed without a container, '
				. 'so the OpenRegister credential broker cannot be resolved.'
			);
		}

		return $this->container;
	}//end container()

	/**
	 * GET a JSON body through the broker and decode the response.
	 *
	 * @param GitHubPublishRun $run The publish whose credential the call uses.
	 * @param string $path GitHub-relative path.
	 *
	 * @return array<string,mixed> Decoded response payload.
	 *
	 * @throws RuntimeException On API failure.
	 *
	 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-let-a-template-owner-publish-it-to-a-new-tagged-github-repository
	 */
	public function getJson(GitHubPublishRun $run, string $path): array {
		$decoded = $this->call(run: $run, call: new GitHubCall(method: 'GET', path: $path));

		if ($decoded === null) {
			throw new RuntimeException('GitHub API call failed: GET ' . $path);
		}

		return $decoded;
	}//end getJson()

	/**
	 * POST a JSON body through the broker and decode the response.
	 *
	 * @param GitHubPublishRun $run The publish whose credential the call uses.
	 * @param GitHubCall $call The POST call, with its body.
	 *
	 * @return array<string,mixed> Decoded response payload.
	 *
	 * @throws RuntimeException On API failure.
	 *
	 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-let-a-template-owner-publish-it-to-a-new-tagged-github-repository
	 */
	public function postJson(GitHubPublishRun $run, GitHubCall $call): array {
		$decoded = $this->call(run: $run, call: $call);

		if ($decoded === null) {
			throw new RuntimeException('GitHub API call failed: POST ' . $call->path);
		}

		return $decoded;
	}//end postJson()

	/**
	 * Route one GitHub call through OpenRegister's credential broker.
	 *
	 * We send only {method, path, body}: the base URL is the broker's host-lock and
	 * the Authorization header is injected there from the vault. This process never
	 * sees the token, so there is nothing here to leak into a log or an exception.
	 *
	 * A non-2xx (including a broker 403 for an unlisted method/path) returns `null`
	 * rather than throwing, because `assertRepoAbsent()` treats absence as the happy
	 * path. Every other caller turns `null` into a RuntimeException. A quiet call
	 * (`GitHubCall::quietly()`) fails without the warning: setTopics() treats
	 * failure as cosmetic while every other caller wants the scrubbed warning.
	 *
	 * @param GitHubPublishRun $run The publish whose credential the call uses.
	 * @param GitHubCall $call The call.
	 *
	 * @return array<string,mixed>|null Decoded payload, or null on any non-2xx.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) The optional OpenRegister broker is
	 *   named by class-string and resolved through the injected container, so this class
	 *   stays constructible when the broker is absent.
	 *
	 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-never-hold-or-log-the-github-token
	 */
	public function call(GitHubPublishRun $run, GitHubCall $call): ?array {
		try {
			$response = $this->request(run: $run, call: $call);
		} catch (Throwable $e) {
			// Never log the body — it may carry the package contents, which can
			// carry anything the author typed. Method and path only.
			$this->warnUnlessQuiet(
				call: $call,
				message: 'broker call failed for ' . $call->method . ' ' . $call->path . ': ' . $this->scrub(message: $e->getMessage())
			);

			return null;
		}//end try

		return $this->decodedSuccess(call: $call, response: $response);
	}//end call()

	/**
	 * Hand one call to the broker, with the run's credential and acting user.
	 *
	 * @param GitHubPublishRun $run The publish whose credential the call uses.
	 * @param GitHubCall $call The call.
	 *
	 * @return mixed The broker's `{status, body}` answer.
	 *
	 * @throws Throwable Whatever resolving or calling the broker throws.
	 */
	private function request(GitHubPublishRun $run, GitHubCall $call): mixed {
		return $this->container()->get(self::BROKER_CLASS)->request(
			$run->credential->credentialId,
			self::APP_ID,
			$call->method,
			$call->path,
			['Accept' => 'application/vnd.github+json', 'Content-Type' => 'application/json'],
			$call->encodedBody(),
			$run->credential->actingUserId
		);
	}//end request()

	/**
	 * The decoded body of a 2xx broker answer, or null (with a warning unless quiet) otherwise.
	 *
	 * @param GitHubCall $call The call that was answered.
	 * @param mixed $response The broker's `{status, body}` answer.
	 *
	 * @return array<string,mixed>|null The decoded body, or null.
	 */
	private function decodedSuccess(GitHubCall $call, mixed $response): ?array {
		$status = (int)($response['status'] ?? 0);
		if ($status < 200 || $status >= 300) {
			$this->warnUnlessQuiet(call: $call, message: $call->method . ' ' . $call->path . ' returned HTTP ' . $status);

			return null;
		}

		return $this->decode(body: (string)($response['body'] ?? ''));
	}//end decodedSuccess()

	/**
	 * Log a failed broker call, unless the call is quiet.
	 *
	 * @param GitHubCall $call The failed call.
	 * @param string $message What failed (method, path and reason; never the body).
	 *
	 * @return void
	 */
	private function warnUnlessQuiet(GitHubCall $call, string $message): void {
		if ($call->isQuiet() === false) {
			$this->logger->warning('Hermiq GitHub template publish: ' . $message);
		}
	}//end warnUnlessQuiet()

	/**
	 * Decode a JSON response body into an array.
	 *
	 * @param string $body Raw response body.
	 *
	 * @return array<string,mixed> Decoded payload (empty on non-array).
	 */
	private function decode(string $body): array {
		$decoded = json_decode($body, true);
		if (is_array($decoded) === true) {
			return $decoded;
		}

		return [];
	}//end decode()

	/**
	 * Remove any leaked PAT-shaped tokens from an error message before it is
	 * surfaced into logs (defence in depth).
	 *
	 * @param string $message Raw error message.
	 *
	 * @return string Scrubbed message.
	 *
	 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-never-hold-or-log-the-github-token
	 */
	private function scrub(string $message): string {
		return (string)preg_replace('/gh[pousr]_[A-Za-z0-9]{20,}/', '[redacted-token]', $message);
	}//end scrub()
}//end class
