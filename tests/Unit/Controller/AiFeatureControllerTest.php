<?php

/**
 * Unit tests for AiFeatureController (ai-feature-governance-register).
 *
 * Focuses on the ADR-023 action-auth gate on the three governance mutations: each of
 * acknowledge/enable/disable calls ActionAuthService::requireAction() before doing work;
 * a refused (non-admin / non-DPO) caller gets 403, an unauthenticated caller 401. Also
 * covers the transition-outcome → HTTP mapping (enabled=200, blocked=409, notfound=404).
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ai-feature-governance-register/tasks.md#task-6-2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\AiFeatureController;
use OCA\Hermiq\Service\ActionAuthService;
use OCA\Hermiq\Service\AiFeatureService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the AI-feature governance controller.
 *
 * @spec openspec/changes/ai-feature-governance-register/tasks.md#task-6-2
 */
class AiFeatureControllerTest extends TestCase
{
    /**
     * An AiFeature ObjectEntity with the given slug.
     *
     * @param string $slug The feature slug.
     *
     * @return ObjectEntity
     */
    private function feature(string $slug='autonomous-agent-run'): ObjectEntity
    {
        $entity = new ObjectEntity();
        $entity->setUuid('feat-1');
        $entity->setObject(['slug' => $slug, 'name' => 'Autonomous agent run', 'lifecycle' => 'disabled']);
        return $entity;

    }//end feature()

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
     * @param AiFeatureService  $service    The AiFeature service.
     * @param ActionAuthService $actionAuth The action-auth service.
     * @param IUserSession      $session    The user session.
     *
     * @return AiFeatureController
     */
    private function controller(
        AiFeatureService $service,
        ActionAuthService $actionAuth,
        IUserSession $session
    ): AiFeatureController {
        return new AiFeatureController(
            $this->createMock(IRequest::class),
            $service,
            $actionAuth,
            $session,
            $this->createMock(LoggerInterface::class)
        );

    }//end controller()

    /**
     * The index lists the tenant's features for an authenticated caller.
     *
     * @return void
     *
     * @spec openspec/changes/ai-feature-governance-register/tasks.md#task-6-2
     */
    public function testIndexReturnsFeatures(): void
    {
        $service = $this->createMock(AiFeatureService::class);
        $service->method('listFeatures')->willReturn([$this->feature()]);

        $response = $this->controller($service, $this->createMock(ActionAuthService::class), $this->session('admin'))->index();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(1, $response->getData()['total']);

    }//end testIndexReturnsFeatures()

    /**
     * An unauthenticated caller gets 401 on index.
     *
     * @return void
     *
     * @spec openspec/changes/ai-feature-governance-register/tasks.md#task-6-2
     */
    public function testIndexUnauthenticated(): void
    {
        $response = $this->controller(
            $this->createMock(AiFeatureService::class),
            $this->createMock(ActionAuthService::class),
            $this->session(null)
        )->index();

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

    }//end testIndexUnauthenticated()

    /**
     * An unauthenticated caller gets 401 on a mutating endpoint (before action-auth).
     *
     * @return void
     *
     * @spec openspec/changes/ai-feature-governance-register/tasks.md#task-6-2
     */
    public function testAcknowledgeUnauthenticated(): void
    {
        $actionAuth = $this->createMock(ActionAuthService::class);
        $actionAuth->expects($this->never())->method('requireAction');

        $response = $this->controller(
            $this->createMock(AiFeatureService::class),
            $actionAuth,
            $this->session(null)
        )->acknowledge('autonomous-agent-run');

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

    }//end testAcknowledgeUnauthenticated()

    /**
     * Acknowledge gates on requireAction('aifeature.acknowledge') then stamps the feature.
     *
     * @return void
     *
     * @spec openspec/changes/ai-feature-governance-register/tasks.md#task-6-2
     */
    public function testAcknowledgeCallsRequireActionAndSucceeds(): void
    {
        $service = $this->createMock(AiFeatureService::class);
        $service->method('acknowledge')->willReturn($this->feature());

        $actionAuth = $this->createMock(ActionAuthService::class);
        $actionAuth->expects($this->once())
            ->method('requireAction')
            ->with($this->anything(), 'aifeature.acknowledge');

        $response = $this->controller($service, $actionAuth, $this->session('admin'))->acknowledge('autonomous-agent-run');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());

    }//end testAcknowledgeCallsRequireActionAndSucceeds()

    /**
     * A non-admin / non-DPO caller is refused (403) on acknowledge, never reaching the service.
     *
     * @return void
     *
     * @spec openspec/changes/ai-feature-governance-register/tasks.md#task-6-2
     */
    public function testAcknowledgeForbiddenForNonAdmin(): void
    {
        $service = $this->createMock(AiFeatureService::class);
        $service->expects($this->never())->method('acknowledge');

        $actionAuth = $this->createMock(ActionAuthService::class);
        $actionAuth->method('requireAction')->willThrowException(new OCSForbiddenException('nope'));

        $response = $this->controller($service, $actionAuth, $this->session('mallory'))->acknowledge('autonomous-agent-run');

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

    }//end testAcknowledgeForbiddenForNonAdmin()

    /**
     * Acknowledge returns 404 when no feature has that slug.
     *
     * @return void
     *
     * @spec openspec/changes/ai-feature-governance-register/tasks.md#task-6-2
     */
    public function testAcknowledgeNotFound(): void
    {
        $service = $this->createMock(AiFeatureService::class);
        $service->method('acknowledge')->willReturn(null);

        $response = $this->controller($service, $this->createMock(ActionAuthService::class), $this->session('admin'))->acknowledge('missing');

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

    }//end testAcknowledgeNotFound()

    /**
     * Enable gates on requireAction('aifeature.enable') and returns 200 on success.
     *
     * @return void
     *
     * @spec openspec/changes/ai-feature-governance-register/tasks.md#task-6-2
     */
    public function testEnableCallsRequireActionAndSucceeds(): void
    {
        $service = $this->createMock(AiFeatureService::class);
        $service->method('enable')->willReturn(AiFeatureService::RESULT_ENABLED);

        $actionAuth = $this->createMock(ActionAuthService::class);
        $actionAuth->expects($this->once())
            ->method('requireAction')
            ->with($this->anything(), 'aifeature.enable');

        $response = $this->controller($service, $actionAuth, $this->session('admin'))->enable('feat-1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('enabled', $response->getData()['lifecycle']);

    }//end testEnableCallsRequireActionAndSucceeds()

    /**
     * Enable is refused (403) for a non-admin / non-DPO caller, never driving the transition.
     *
     * @return void
     *
     * @spec openspec/changes/ai-feature-governance-register/tasks.md#task-6-2
     */
    public function testEnableForbiddenForNonAdmin(): void
    {
        $service = $this->createMock(AiFeatureService::class);
        $service->expects($this->never())->method('enable');

        $actionAuth = $this->createMock(ActionAuthService::class);
        $actionAuth->method('requireAction')->willThrowException(new OCSForbiddenException('nope'));

        $response = $this->controller($service, $actionAuth, $this->session('mallory'))->enable('feat-1');

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

    }//end testEnableForbiddenForNonAdmin()

    /**
     * A blocked enable (guard denied — no DPO ack) surfaces as 409.
     *
     * @return void
     *
     * @spec openspec/changes/ai-feature-governance-register/tasks.md#task-6-2
     */
    public function testEnableBlockedIsConflict(): void
    {
        $service = $this->createMock(AiFeatureService::class);
        $service->method('enable')->willReturn(AiFeatureService::RESULT_BLOCKED);

        $response = $this->controller($service, $this->createMock(ActionAuthService::class), $this->session('admin'))->enable('feat-1');

        $this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());

    }//end testEnableBlockedIsConflict()

    /**
     * Enabling a cross-tenant / missing feature is 404.
     *
     * @return void
     *
     * @spec openspec/changes/ai-feature-governance-register/tasks.md#task-6-2
     */
    public function testEnableNotFound(): void
    {
        $service = $this->createMock(AiFeatureService::class);
        $service->method('enable')->willReturn(AiFeatureService::RESULT_NOT_FOUND);

        $response = $this->controller($service, $this->createMock(ActionAuthService::class), $this->session('admin'))->enable('feat-1');

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

    }//end testEnableNotFound()

    /**
     * Disable gates on requireAction('aifeature.disable') and returns 200 on success.
     *
     * @return void
     *
     * @spec openspec/changes/ai-feature-governance-register/tasks.md#task-6-2
     */
    public function testDisableCallsRequireActionAndSucceeds(): void
    {
        $service = $this->createMock(AiFeatureService::class);
        $service->method('disable')->willReturn(AiFeatureService::RESULT_DISABLED);

        $actionAuth = $this->createMock(ActionAuthService::class);
        $actionAuth->expects($this->once())
            ->method('requireAction')
            ->with($this->anything(), 'aifeature.disable');

        $response = $this->controller($service, $actionAuth, $this->session('admin'))->disable('feat-1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('disabled', $response->getData()['lifecycle']);

    }//end testDisableCallsRequireActionAndSucceeds()

    /**
     * Disable is refused (403) for a non-admin / non-DPO caller.
     *
     * @return void
     *
     * @spec openspec/changes/ai-feature-governance-register/tasks.md#task-6-2
     */
    public function testDisableForbiddenForNonAdmin(): void
    {
        $service = $this->createMock(AiFeatureService::class);
        $service->expects($this->never())->method('disable');

        $actionAuth = $this->createMock(ActionAuthService::class);
        $actionAuth->method('requireAction')->willThrowException(new OCSForbiddenException('nope'));

        $response = $this->controller($service, $actionAuth, $this->session('mallory'))->disable('feat-1');

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

    }//end testDisableForbiddenForNonAdmin()
}//end class
