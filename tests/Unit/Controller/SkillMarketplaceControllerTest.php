<?php

/**
 * Unit tests for SkillMarketplaceController (fix-skill-marketplace-action-auth).
 *
 * Focuses on the ADR-023 action-auth gate added to approve()/publish(): each requires
 * `skill.approve-quarantined` / `skill.publish-hub` before doing any work, and approve()
 * additionally requires the stricter `skill.override-scan-verdict` action on the
 * `force=true` path. A refused caller gets 403, an unauthenticated caller 401, never
 * reaching the service.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Controller
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
 * @spec openspec/changes/fix-skill-marketplace-action-auth/tasks.md#3-tests
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\SkillMarketplaceController;
use OCA\Hermiq\Service\ActionAuthService;
use OCA\Hermiq\Service\GitHubTemplatePushService;
use OCA\Hermiq\Service\SkillMarketplaceService;
use OCA\Hermiq\Service\SkillService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the skills-marketplace controller's action-auth gate.
 *
 * @spec openspec/changes/fix-skill-marketplace-action-auth/tasks.md#3-tests
 */
class SkillMarketplaceControllerTest extends TestCase
{
    /**
     * A Skill ObjectEntity in the given state.
     *
     * @param string $state The skill lifecycle state (e.g. "active", "quarantined").
     *
     * @return ObjectEntity
     */
    private function skill(string $state='active'): ObjectEntity
    {
        $entity = new ObjectEntity();
        $entity->setUuid('skill-1');
        $entity->setObject(['name' => 'Example skill', 'state' => $state]);
        return $entity;

    }//end skill()

    /**
     * A request mock returning the given params.
     *
     * @param array<string, mixed> $params The request params keyed by name.
     *
     * @return IRequest
     */
    private function request(array $params=[]): IRequest
    {
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(
            function (string $key, $default=null) use ($params) {
                return $params[$key] ?? $default;
            }
        );
        return $request;

    }//end request()

    /**
     * A session with the given (or no) user.
     *
     * @param string|null $uid The UID, or null for unauthenticated.
     *
     * @return IUserSession
     */
    private function session(?string $uid): IUserSession
    {
        $session = $this->createMock(IUserSession::class);
        if ($uid === null) {
            $session->method('getUser')->willReturn(null);
            return $session;
        }

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $session->method('getUser')->willReturn($user);
        return $session;

    }//end session()

    /**
     * Build the controller with the given collaborators.
     *
     * @param SkillMarketplaceService   $service      The marketplace service.
     * @param ActionAuthService         $actionAuth   The action-auth service.
     * @param IUserSession              $session      The user session.
     * @param IRequest|null             $request      An optional request mock (defaults to no params).
     * @param SkillService|null         $skillService An optional SkillService mock (hermiq-github-store).
     * @param GitHubTemplatePushService|null $pushService An optional push-service mock (hermiq-github-store).
     *
     * @return SkillMarketplaceController
     */
    private function controller(
        SkillMarketplaceService $service,
        ActionAuthService $actionAuth,
        IUserSession $session,
        ?IRequest $request=null,
        ?SkillService $skillService=null,
        ?GitHubTemplatePushService $pushService=null
    ): SkillMarketplaceController {
        return new SkillMarketplaceController(
            ($request ?? $this->request()),
            $service,
            $actionAuth,
            $session,
            $this->createMock(LoggerInterface::class),
            ($skillService ?? $this->createMock(SkillService::class)),
            ($pushService ?? $this->createMock(GitHubTemplatePushService::class))
        );

    }//end controller()

    /**
     * installFromSource() returns 200 with the quarantined skill for an authenticated caller.
     *
     * @return void
     *
     * @spec openspec/changes/playwright-regression-coverage/tasks.md#task-2-2
     */
    public function testInstallFromSourceReturnsQuarantinedSkill(): void
    {
        $service = $this->createMock(SkillMarketplaceService::class);
        $service->method('installFromSource')->willReturn($this->skill('quarantined'));

        $request  = $this->request(['package' => 'name: Demo skill', 'source' => 'hub']);
        $response = $this->controller($service, $this->createMock(ActionAuthService::class), $this->session('alice'), $request)->installFromSource();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());

    }//end testInstallFromSourceReturnsQuarantinedSkill()

    /**
     * installFromSource() rejects an empty package with 400, never reaching the service.
     *
     * @return void
     *
     * @spec openspec/changes/playwright-regression-coverage/tasks.md#task-2-2
     */
    public function testInstallFromSourceEmptyPackageIsBadRequest(): void
    {
        $service = $this->createMock(SkillMarketplaceService::class);
        $service->expects($this->never())->method('installFromSource');

        $request  = $this->request(['package' => '   ']);
        $response = $this->controller($service, $this->createMock(ActionAuthService::class), $this->session('alice'), $request)->installFromSource();

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

    }//end testInstallFromSourceEmptyPackageIsBadRequest()

    /**
     * installFromSource() returns 401 for an unauthenticated caller.
     *
     * @return void
     *
     * @spec openspec/changes/playwright-regression-coverage/tasks.md#task-2-2
     */
    public function testInstallFromSourceUnauthenticated(): void
    {
        $service = $this->createMock(SkillMarketplaceService::class);
        $service->expects($this->never())->method('installFromSource');

        $response = $this->controller($service, $this->createMock(ActionAuthService::class), $this->session(null))->installFromSource();

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

    }//end testInstallFromSourceUnauthenticated()

    /**
     * An unauthenticated caller gets 401 on approve(), never reaching action-auth.
     *
     * @return void
     */
    public function testApproveUnauthenticated(): void
    {
        $actionAuth = $this->createMock(ActionAuthService::class);
        $actionAuth->expects($this->never())->method('requireAction');

        $response = $this->controller(
            $this->createMock(SkillMarketplaceService::class),
            $actionAuth,
            $this->session(null)
        )->approve('skill-1');

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

    }//end testApproveUnauthenticated()

    /**
     * Approve gates on requireAction('skill.approve-quarantined') then succeeds.
     *
     * @return void
     */
    public function testApproveCallsRequireActionAndSucceeds(): void
    {
        $service = $this->createMock(SkillMarketplaceService::class);
        $service->method('approveQuarantined')->willReturn($this->skill());

        $actionAuth = $this->createMock(ActionAuthService::class);
        $actionAuth->expects($this->once())
            ->method('requireAction')
            ->with($this->anything(), 'skill.approve-quarantined');

        $response = $this->controller($service, $actionAuth, $this->session('admin'))->approve('skill-1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());

    }//end testApproveCallsRequireActionAndSucceeds()

    /**
     * A caller with no matrix entry is refused (403) on approve(), never reaching the service.
     *
     * @return void
     */
    public function testApproveForbiddenForNonAdmin(): void
    {
        $service = $this->createMock(SkillMarketplaceService::class);
        $service->expects($this->never())->method('approveQuarantined');

        $actionAuth = $this->createMock(ActionAuthService::class);
        $actionAuth->method('requireAction')->willThrowException(new OCSForbiddenException('nope'));

        $response = $this->controller($service, $actionAuth, $this->session('mallory'))->approve('skill-1');

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

    }//end testApproveForbiddenForNonAdmin()

    /**
     * A caller granted `skill.approve-quarantined` but NOT `skill.override-scan-verdict` is
     * refused (403) on approve(force: true) — even though a plain approve would succeed.
     *
     * @return void
     */
    public function testApproveForceForbiddenWithoutOverrideAction(): void
    {
        $service = $this->createMock(SkillMarketplaceService::class);
        $service->expects($this->never())->method('approveQuarantined');

        $actionAuth = $this->createMock(ActionAuthService::class);
        $actionAuth->method('requireAction')->willReturnCallback(
            function ($user, string $action) {
                if ($action === 'skill.override-scan-verdict') {
                    throw new OCSForbiddenException('nope');
                }
            }
        );

        $request  = $this->request(['force' => true]);
        $response = $this->controller($service, $actionAuth, $this->session('curator'), $request)->approve('skill-1');

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

    }//end testApproveForceForbiddenWithoutOverrideAction()

    /**
     * A caller granted both actions succeeds on approve(force: true).
     *
     * @return void
     */
    public function testApproveForceSucceedsWithOverrideAction(): void
    {
        $service = $this->createMock(SkillMarketplaceService::class);
        $service->method('approveQuarantined')->willReturn($this->skill());

        $actionAuth = $this->createMock(ActionAuthService::class);
        $actionAuth->expects($this->exactly(2))->method('requireAction');

        $request  = $this->request(['force' => true]);
        $response = $this->controller($service, $actionAuth, $this->session('admin'), $request)->approve('skill-1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());

    }//end testApproveForceSucceedsWithOverrideAction()

    /**
     * An unauthenticated caller gets 401 on publish(), never reaching action-auth.
     *
     * @return void
     */
    public function testPublishUnauthenticated(): void
    {
        $actionAuth = $this->createMock(ActionAuthService::class);
        $actionAuth->expects($this->never())->method('requireAction');

        $response = $this->controller(
            $this->createMock(SkillMarketplaceService::class),
            $actionAuth,
            $this->session(null)
        )->publish('skill-1');

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

    }//end testPublishUnauthenticated()

    /**
     * Publish gates on requireAction('skill.publish-hub') then succeeds.
     *
     * @return void
     */
    public function testPublishCallsRequireActionAndSucceeds(): void
    {
        $service = $this->createMock(SkillMarketplaceService::class);
        $service->method('publishToHub')->willReturn(['status' => 'published']);

        $actionAuth = $this->createMock(ActionAuthService::class);
        $actionAuth->expects($this->once())
            ->method('requireAction')
            ->with($this->anything(), 'skill.publish-hub');

        $response = $this->controller($service, $actionAuth, $this->session('admin'))->publish('skill-1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());

    }//end testPublishCallsRequireActionAndSucceeds()

    /**
     * A caller with no matrix entry is refused (403) on publish(), never reaching the service.
     *
     * @return void
     */
    public function testPublishForbiddenForNonAdmin(): void
    {
        $service = $this->createMock(SkillMarketplaceService::class);
        $service->expects($this->never())->method('publishToHub');

        $actionAuth = $this->createMock(ActionAuthService::class);
        $actionAuth->method('requireAction')->willThrowException(new OCSForbiddenException('nope'));

        $response = $this->controller($service, $actionAuth, $this->session('mallory'))->publish('skill-1');

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

    }//end testPublishForbiddenForNonAdmin()

    /**
     * An unauthenticated caller gets 401 on githubPublish(), never reaching action-auth
     * (hermiq-github-store).
     *
     * @return void
     */
    public function testGithubPublishUnauthenticated(): void
    {
        $actionAuth = $this->createMock(ActionAuthService::class);
        $actionAuth->expects($this->never())->method('requireAction');

        $response = $this->controller(
            $this->createMock(SkillMarketplaceService::class),
            $actionAuth,
            $this->session(null)
        )->githubPublish('skill-1');

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

    }//end testGithubPublishUnauthenticated()

    /**
     * githubPublish() gates on the SAME `skill.publish-hub` action as publish()
     * (hermiq-github-store — design.md does not introduce a distinct action).
     *
     * @return void
     */
    public function testGithubPublishForbiddenForNonAdmin(): void
    {
        $skillService = $this->createMock(SkillService::class);
        $skillService->expects($this->never())->method('exportSkill');

        $actionAuth = $this->createMock(ActionAuthService::class);
        $actionAuth->expects($this->once())
            ->method('requireAction')
            ->with($this->anything(), 'skill.publish-hub')
            ->willThrowException(new OCSForbiddenException('nope'));

        $request  = $this->request(['owner' => 'acme', 'repo' => 'demo-skill', 'credentialId' => 'cred-uuid']);
        $response = $this->controller(
            $this->createMock(SkillMarketplaceService::class),
            $actionAuth,
            $this->session('mallory'),
            $request,
            $skillService
        )->githubPublish('skill-1');

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

    }//end testGithubPublishForbiddenForNonAdmin()

    /**
     * An invalid owner/repo is rejected with 400 before any export or push call
     * (hermiq-github-store).
     *
     * @return void
     */
    public function testGithubPublishRejectsInvalidRepo(): void
    {
        $skillService = $this->createMock(SkillService::class);
        $skillService->expects($this->never())->method('exportSkill');

        $request  = $this->request(['owner' => '../evil', 'repo' => 'demo-skill', 'credentialId' => 'cred-uuid']);
        $response = $this->controller(
            $this->createMock(SkillMarketplaceService::class),
            $this->createMock(ActionAuthService::class),
            $this->session('admin'),
            $request,
            $skillService
        )->githubPublish('skill-1');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

    }//end testGithubPublishRejectsInvalidRepo()

    /**
     * A missing credentialId is rejected with 422 before any export or push call
     * (hermiq-github-store).
     *
     * @return void
     */
    public function testGithubPublishRequiresCredentialId(): void
    {
        $skillService = $this->createMock(SkillService::class);
        $skillService->expects($this->never())->method('exportSkill');

        $request  = $this->request(['owner' => 'acme', 'repo' => 'demo-skill']);
        $response = $this->controller(
            $this->createMock(SkillMarketplaceService::class),
            $this->createMock(ActionAuthService::class),
            $this->session('admin'),
            $request,
            $skillService
        )->githubPublish('skill-1');

        $this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());

    }//end testGithubPublishRequiresCredentialId()

    /**
     * A skill outside the caller's visibility (exportSkill() returns null) is a 404,
     * never a 403 that would confirm existence (hermiq-github-store).
     *
     * @return void
     */
    public function testGithubPublishSkillOutsideVisibilityIsNotFound(): void
    {
        $skillService = $this->createMock(SkillService::class);
        $skillService->method('exportSkill')->willReturn(null);

        $pushService = $this->createMock(GitHubTemplatePushService::class);
        $pushService->expects($this->never())->method('push');

        $request  = $this->request(['owner' => 'acme', 'repo' => 'demo-skill', 'credentialId' => 'cred-uuid']);
        $response = $this->controller(
            $this->createMock(SkillMarketplaceService::class),
            $this->createMock(ActionAuthService::class),
            $this->session('admin'),
            $request,
            $skillService,
            $pushService
        )->githubPublish('skill-1');

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

    }//end testGithubPublishSkillOutsideVisibilityIsNotFound()

    /**
     * Publish fails closed (503) when the broker is unavailable — no token-bearing
     * fallback is attempted (hermiq-github-store).
     *
     * @return void
     */
    public function testGithubPublishFailsClosedWhenBrokerUnavailable(): void
    {
        $skillService = $this->createMock(SkillService::class);
        $skillService->method('exportSkill')->willReturn('---
name: Demo skill
---
Body.');

        $pushService = $this->createMock(GitHubTemplatePushService::class);
        $pushService->method('isBrokerAvailable')->willReturn(false);
        $pushService->expects($this->never())->method('push');

        $request  = $this->request(['owner' => 'acme', 'repo' => 'demo-skill', 'credentialId' => 'cred-uuid']);
        $response = $this->controller(
            $this->createMock(SkillMarketplaceService::class),
            $this->createMock(ActionAuthService::class),
            $this->session('admin'),
            $request,
            $skillService,
            $pushService
        )->githubPublish('skill-1');

        $this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());

    }//end testGithubPublishFailsClosedWhenBrokerUnavailable()

    /**
     * A successful publish pushes with `kind: KIND_SKILL` and stamps provenance
     * (hermiq-github-store).
     *
     * @return void
     */
    public function testGithubPublishSucceedsAndStampsProvenance(): void
    {
        $skillService = $this->createMock(SkillService::class);
        $skillService->method('exportSkill')->willReturn('---
name: Demo skill
---
Body.');
        $skillService->expects($this->once())
            ->method('stampGithubPublish')
            ->with('skill-1', 'acme', 'demo-skill', $this->isType('string'));

        $pushService = $this->createMock(GitHubTemplatePushService::class);
        $pushService->method('isBrokerAvailable')->willReturn(true);
        $pushService->expects($this->once())
            ->method('push')
            ->with(
                $this->anything(),
                'acme',
                'demo-skill',
                'private',
                'cred-uuid',
                'admin',
                GitHubTemplatePushService::KIND_SKILL
            )
            ->willReturn(['repoUrl' => 'https://github.com/acme/demo-skill', 'commitSha' => 'abc123']);

        $request  = $this->request(['owner' => 'acme', 'repo' => 'demo-skill', 'credentialId' => 'cred-uuid']);
        $response = $this->controller(
            $this->createMock(SkillMarketplaceService::class),
            $this->createMock(ActionAuthService::class),
            $this->session('admin'),
            $request,
            $skillService,
            $pushService
        )->githubPublish('skill-1');

        $this->assertSame(Http::STATUS_CREATED, $response->getStatus());

    }//end testGithubPublishSucceedsAndStampsProvenance()

    /**
     * push() refusing the call (e.g. repo already exists) surfaces as 422, and no
     * provenance stamp is written (hermiq-github-store).
     *
     * @return void
     */
    public function testGithubPublishRefusedByServiceIsUnprocessable(): void
    {
        $skillService = $this->createMock(SkillService::class);
        $skillService->method('exportSkill')->willReturn('---
name: Demo skill
---
Body.');
        $skillService->expects($this->never())->method('stampGithubPublish');

        $pushService = $this->createMock(GitHubTemplatePushService::class);
        $pushService->method('isBrokerAvailable')->willReturn(true);
        $pushService->method('push')->willThrowException(new RuntimeException('Repository acme/demo-skill already exists'));

        $request  = $this->request(['owner' => 'acme', 'repo' => 'demo-skill', 'credentialId' => 'cred-uuid']);
        $response = $this->controller(
            $this->createMock(SkillMarketplaceService::class),
            $this->createMock(ActionAuthService::class),
            $this->session('admin'),
            $request,
            $skillService,
            $pushService
        )->githubPublish('skill-1');

        $this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());

    }//end testGithubPublishRefusedByServiceIsUnprocessable()
}//end class
