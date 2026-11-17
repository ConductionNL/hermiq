<?php

/**
 * Hermiq ForgeCredentialResolver.
 *
 * Resolves the credential a governed push uses, on the governed side, at the
 * tool boundary. It is a PERSONAL credential of the run owner (or the agent's
 * pinned forge credential, which must be the owner's own), for the provider the
 * admin set in `workspace_forge_provider` (default `github`), and it must be an
 * inject-only credential, because git speaks the pack protocol itself. When the
 * run's model credential is a personal credential too, both must belong to the
 * same person: a run that assembles two people's credentials is refused, not
 * warned about.
 *
 * The secret leaves this class only towards `GitRunner`'s environment for the
 * one push. It never reaches the runner container, a command line, the
 * workspace's configuration, a tool result, a log line or an audit record.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Workspace
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/agent-workspace-git-tools/spec.md#requirement-the-forge-credential-and-the-model-credential-are-separate-and-neither-reaches-the-model
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Workspace;

use OCA\Hermiq\Kernel\AppIdentity;
use OCA\Hermiq\Service\Credential\CredentialScopeResolver;
use OCA\Hermiq\Service\Credential\PinnedCredentialRefusedException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * The forge credential of one push.
 *
 * @spec openspec/specs/agent-workspace-git-tools/spec.md#requirement-commits-are-authored-and-pushes-authorised-as-the-resolved-run-owner
 */
class ForgeCredentialResolver {

	/**
	 * OpenRegister's credential broker.
	 *
	 * @var string
	 */
	public const BROKER_CLASS = 'OCA\\OpenRegister\\Service\\Credential\\CredentialBrokerService';

	/**
	 * The default forge provider id.
	 *
	 * @var string
	 */
	public const DEFAULT_PROVIDER = 'github';

	/**
	 * Build the resolver.
	 *
	 * @param CredentialScopeResolver $scopes    Which credential applies, and whose it is.
	 * @param IAppConfig              $appConfig The forge provider setting.
	 * @param ContainerInterface      $container Reaches the broker when OpenRegister is installed.
	 */
	public function __construct(
		private readonly CredentialScopeResolver $scopes,
		private readonly IAppConfig $appConfig,
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * The credential id and secret for a push by this run.
	 *
	 * @param string            $ownerUid The resolved run owner.
	 * @param ObjectEntity|null $agent    The run's agent (provider, pins, organisation).
	 *
	 * @return array{credentialId: string, secret: string}
	 *
	 * @throws WorkspaceException owner_unresolvable or credential_scope_refused.
	 *
	 * @spec openspec/specs/agent-workspace-git-tools/spec.md#scenario-mismatched-credential-owners-are-refused
	 */
	public function resolve(string $ownerUid, ?ObjectEntity $agent): array {
		if (trim($ownerUid) === '') {
			throw new WorkspaceException(
				errorCode: WorkspaceException::OWNER_UNRESOLVABLE,
				message: 'This run has no owner to push as. It can still read the workspace.'
			);
		}

		$run = $this->pushRun(ownerUid: $ownerUid, agent: $agent);
		$forgeId = $this->forgeCredential(run: $run);
		$this->assertSameOwner(run: $run, forgeId: $forgeId);

		return ['credentialId' => $forgeId, 'secret' => $this->secret(credentialId: $forgeId, ownerUid: $ownerUid)];
	}//end resolve()

	/**
	 * The resolution for a run: its owner, its agent, the agent's data and its pins (a non-list counts as none).
	 *
	 * @param string            $ownerUid The resolved run owner.
	 * @param ObjectEntity|null $agent    The run's agent.
	 *
	 * @return PushCredentialRun The resolution.
	 */
	private function pushRun(string $ownerUid, ?ObjectEntity $agent): PushCredentialRun {
		$run = new PushCredentialRun(ownerUid: $ownerUid, agent: $agent);
		if ($agent !== null) {
			$run->agentData = $agent->getObject();
		}

		$pins = $run->agentData['credentialIds'] ?? null;
		if (is_array($pins) === true) {
			$run->pins = $pins;
		}

		return $run;
	}//end pushRun()

	/**
	 * The owner's personal forge credential, or the refusal.
	 *
	 * @param PushCredentialRun $run The resolution.
	 *
	 * @return string The forge credential id.
	 *
	 * @throws WorkspaceException credential_scope_refused.
	 */
	private function forgeCredential(PushCredentialRun $run): string {
		$forgeId = $this->pick(run: $run, target: new CredentialTarget(provider: $this->forgeProvider(), organisation: null));
		if ($forgeId === null || $this->scopes->scopeOfCredential(credentialId: $forgeId) !== 'personal'
			|| $this->scopes->ownerOfCredential(credentialId: $forgeId) !== $run->ownerUid
		) {
			throw $this->refused(message: 'The run owner has no personal forge credential this app may use, so nothing was pushed.');
		}

		return $forgeId;
	}//end forgeCredential()

	/**
	 * Refuse when the model credential is another person's personal credential.
	 *
	 * @param PushCredentialRun $run     The resolution.
	 * @param string            $forgeId The forge credential id.
	 *
	 * @return void
	 *
	 * @throws WorkspaceException credential_scope_refused.
	 */
	private function assertSameOwner(PushCredentialRun $run, string $forgeId): void {
		$provider = trim((string)($run->agentData['provider'] ?? ''));
		if ($provider === '') {
			return;
		}

		$organisation = null;
		if ($run->agent !== null) {
			$organisation = $run->agent->getOrganisation();
		}

		$modelId = $this->pick(run: $run, target: new CredentialTarget(provider: $provider, organisation: $organisation));
		if ($modelId === null || $this->scopes->scopeOfCredential(credentialId: $modelId) !== 'personal') {
			return;
		}

		if ($this->scopes->ownerOfCredential(credentialId: $modelId) !== $this->scopes->ownerOfCredential(credentialId: $forgeId)) {
			throw $this->refused(message: 'The model and forge credentials of this run belong to different people, so nothing was pushed.');
		}
	}//end assertSameOwner()

	/**
	 * Resolve a credential id, a refused pin counting as none.
	 *
	 * @param PushCredentialRun $run    The resolution (owner and pins).
	 * @param CredentialTarget  $target The provider and organisation to look in.
	 *
	 * @return string|null
	 *
	 * @throws WorkspaceException credential_scope_refused when a pin cannot be used.
	 */
	private function pick(PushCredentialRun $run, CredentialTarget $target): ?string {
		try {
			return $this->scopes->resolve(provider: $target->provider, actingUserId: $run->ownerUid, organisation: $target->organisation, pinned: $run->pins);
		} catch (PinnedCredentialRefusedException) {
			throw $this->refused(message: 'The credential pinned on this agent cannot be used by this run, so nothing was pushed.');
		}
	}//end pick()

	/**
	 * The raw secret of an inject-only credential, through the broker's guards.
	 *
	 * @param string $credentialId The credential id.
	 * @param string $ownerUid     The run owner.
	 *
	 * @return string
	 *
	 * @throws WorkspaceException credential_scope_refused.
	 */
	private function secret(string $credentialId, string $ownerUid): string {
		$secret = null;
		try {
			if (class_exists(self::BROKER_CLASS) === true) {
				$secret = $this->container->get(self::BROKER_CLASS)->resolveInjectable(
					credentialId: $credentialId,
					appId: AppIdentity::APP_ID,
					actingUserId: $ownerUid
				);
			}
		} catch (Throwable) {
			$secret = null;
		}

		if (is_string($secret) === false || $secret === '') {
			throw $this->refused(message: 'The forge credential cannot be used for a push (it must be an inject-only credential), so nothing was pushed.');
		}

		return $secret;
	}//end secret()

	/**
	 * The admin's forge provider id.
	 *
	 * @return string
	 */
	private function forgeProvider(): string {
		$provider = trim($this->appConfig->getValueString(AppIdentity::APP_ID, 'workspace_forge_provider', self::DEFAULT_PROVIDER));
		if ($provider === '') {
			return self::DEFAULT_PROVIDER;
		}

		return $provider;
	}//end forgeProvider()

	/**
	 * A credential-scope refusal.
	 *
	 * @param string $message The sentence for the model.
	 *
	 * @return WorkspaceException
	 */
	private function refused(string $message): WorkspaceException {
		return new WorkspaceException(errorCode: WorkspaceException::CREDENTIAL_SCOPE_REFUSED, message: $message);
	}//end refused()
}//end class
