<?php

/**
 * GuardrailPolicyController::effective() — tenant scope on the organisation
 * parameter.
 *
 * The parameter overrides the caller's own organisation, and the lookup behind
 * it reads with `_rbac: false, _multitenancy: false` (deliberately — the write
 * path needs a policy's organisation before it can decide who may administer
 * it). That makes the controller the only place the caller's scope is checked,
 * and it was not checked at all: any authenticated user could name any
 * organisation and read its effective policy, which states plainly which PII
 * and prompt-injection filters are off and which tools are `auto`.
 *
 * @category  Test
 * @package   OCA\Hermiq\Tests\Unit\Controller
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\GuardrailPolicyController;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\Hermiq\Service\GuardrailPolicyService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Hermiq\Controller\GuardrailPolicyController
 */
class GuardrailPolicyControllerEffectiveScopeTest extends TestCase {
	/**
	 * Build the controller with the supplied admin flag and organisation owner.
	 *
	 * @param string      $requested The organisation the caller asks for.
	 * @param boolean     $isAdmin   Whether the caller is an instance admin.
	 * @param string|null $owner     The requested organisation's owner uid.
	 *
	 * @return array{0:GuardrailPolicyController,1:GuardrailPolicyService}
	 */
	private function make(string $requested, bool $isAdmin, ?string $owner): array {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static function (string $key, $default = null) use ($requested) {
				return ($key === 'organisation') ? $requested : $default;
			}
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('mallory');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn($isAdmin);

		$mapper = $this->createMock(OrganisationMapper::class);
		if ($owner === null) {
			$mapper->method('findByUuid')->willThrowException(new \RuntimeException('nope'));
		} else {
			// NOT a mock. getOwner() is DECLARED on the standalone stub and MAGIC
			// (Entity::__call) on the real openregister Organisation that CI
			// loads, and the two need opposite PHPUnit doubles: createMock()
			// cannot configure a magic method, addMethods() cannot add a
			// declared one. An anonymous subclass that declares getOwner()
			// satisfies the call in both environments and needs neither.
			$entity = new class ($owner) extends Organisation {
				/**
				 * @param string $owner The owning uid.
				 */
				public function __construct(private string $owner) {
				}

				/**
				 * @return string|null
				 */
				public function getOwner(): ?string {
					return $this->owner;
				}
			};
			$mapper->method('findByUuid')->willReturn($entity);
		}

		$policy = $this->createMock(GuardrailPolicyService::class);

		$controller = new GuardrailPolicyController(
			$request,
			$policy,
			$session,
			$groups,
			$mapper,
			$this->createMock(LoggerInterface::class)
		);

		return [$controller, $policy];
	}

	/**
	 * A caller who neither owns the organisation nor is an admin is refused,
	 * and the policy service is never consulted.
	 *
	 * The second half matters: a refusal that still read the policy and then
	 * discarded it would leak the same posture through timing, and would pass
	 * a status-code-only assertion.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-guardrails/specs/agent-guardrails/spec.md#requirement-per-organisation-guardrail-policy-with-a-fully-open-fallback
	 */
	public function testAnotherTenantsPolicyIsRefusedAndNeverRead(): void {
		[$controller, $policy] = $this->make('org-victim', false, 'victim-owner');
		$policy->expects($this->never())->method('effectivePolicyFor');

		$response = $controller->effective();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	/**
	 * The owner of the organisation still reads it.
	 *
	 * @return void
	 */
	public function testTheOrganisationsOwnerStillReadsIt(): void {
		[$controller, $policy] = $this->make('org-mine', false, 'mallory');
		$policy->expects($this->once())
			->method('effectivePolicyFor')
			->with(organisation: 'org-mine')
			->willReturn(['source' => 'organisation']);

		$this->assertSame(Http::STATUS_OK, $controller->effective()->getStatus());
	}

	/**
	 * An instance admin may read any organisation — the same latitude index()
	 * already grants.
	 *
	 * @return void
	 */
	public function testAnInstanceAdminMayReadAnyOrganisation(): void {
		[$controller, $policy] = $this->make('org-victim', true, 'victim-owner');
		$policy->expects($this->once())
			->method('effectivePolicyFor')
			->willReturn(['source' => 'organisation']);

		$this->assertSame(Http::STATUS_OK, $controller->effective()->getStatus());
	}
}//end class
