<?php

/**
 * Hermiq SkillMaturityController unit tests (skill-maturity).
 *
 * Covers the qualify owner-guard (IDOR): unauthenticated → 401; a missing skill and a
 * non-owner's skill are indistinguishable — both 404, never 403. And the attest-L4
 * posture: an invisible skill 404s BEFORE the action check (no existence leak through a
 * 403); a caller without `skill.attest-maturity` gets 403 with the skill unchanged (the
 * service is never invoked); an authorized caller's uid + note reach the attest stamp.
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
 * @spec openspec/specs/skill-maturity/spec.md#requirement-the-qualify-endpoint-is-owner-guarded-and-returns-a-scorecard
 * @spec openspec/specs/skill-maturity/spec.md#requirement-l4-is-human-attested-only-behind-action-authorization
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\SkillMaturityController;
use OCA\Hermiq\Service\ActionAuthService;
use OCA\Hermiq\Service\SeedCustodyService;
use OCA\Hermiq\Service\SkillMaturityService;
use OCA\Hermiq\Service\SkillService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * SkillMaturityController guard tests (skill-maturity).
 *
 * @spec openspec/specs/skill-maturity/spec.md#requirement-the-qualify-endpoint-is-owner-guarded-and-returns-a-scorecard
 */
class SkillMaturityControllerTest extends TestCase
{

    /**
     * A user session that resolves to $uid, or null (unauthenticated) when $uid is null.
     *
     * @param string|null $uid The UID, or null for no user.
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
     * A Skill ObjectEntity owned by the given uid.
     *
     * @param string $owner The owner uid.
     *
     * @return ObjectEntity
     */
    private function skill(string $owner): ObjectEntity
    {
        $entity = new ObjectEntity();
        $entity->setUuid('skill-uuid');
        $entity->setOwner($owner);
        $entity->setObject(['name' => 'a-skill']);
        return $entity;

    }//end skill()

    /**
     * Build the controller under test.
     *
     * @param IUserSession              $session         The user session.
     * @param SkillService|null         $skillService    The skill read path.
     * @param SkillMaturityService|null $maturityService The maturity service.
     * @param ActionAuthService|null    $actionAuth      The action authorization service.
     *
     * @return SkillMaturityController
     */
    private function controller(
        IUserSession $session,
        ?SkillService $skillService=null,
        ?SkillMaturityService $maturityService=null,
        ?ActionAuthService $actionAuth=null,
        bool $callerIsAdmin=false
    ): SkillMaturityController {
        if ($skillService === null) {
            $skillService = $this->createMock(SkillService::class);
        }

        if ($maturityService === null) {
            $maturityService = $this->createMock(SkillMaturityService::class);
        }

        if ($actionAuth === null) {
            $actionAuth = $this->createMock(ActionAuthService::class);
        }

        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('isAdmin')->willReturn($callerIsAdmin);

        return new SkillMaturityController(
            $this->createMock(IRequest::class),
            $skillService,
            $maturityService,
            $actionAuth,
            new SeedCustodyService(groupManager: $groupManager),
            $session,
            $this->createMock(LoggerInterface::class)
        );

    }//end controller()

    /**
     * Unauthenticated callers get 401 on both endpoints.
     *
     * @return void
     *
     * @spec openspec/specs/skill-maturity/spec.md#requirement-the-qualify-endpoint-is-owner-guarded-and-returns-a-scorecard
     */
    public function testUnauthenticatedIs401(): void
    {
        $controller = $this->controller(session: $this->session(null));

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->qualify('skill-uuid')->getStatus());
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->attestL4('skill-uuid')->getStatus());

    }//end testUnauthenticatedIs401()

    /**
     * Qualifying a missing skill is 404.
     *
     * @return void
     *
     * @spec openspec/specs/skill-maturity/spec.md#requirement-the-qualify-endpoint-is-owner-guarded-and-returns-a-scorecard
     */
    public function testQualifyMissingSkillIs404(): void
    {
        $skillService = $this->createMock(SkillService::class);
        $skillService->method('getSkill')->willReturn(null);

        $controller = $this->controller(session: $this->session('alice'), skillService: $skillService);

        $this->assertSame(Http::STATUS_NOT_FOUND, $controller->qualify('missing')->getStatus());

    }//end testQualifyMissingSkillIs404()

    /**
     * A non-owner qualifying another user's skill gets 404 — never 403 — and the
     * maturity service is never invoked (the skill stays unchanged).
     *
     * @return void
     *
     * @spec openspec/specs/skill-maturity/spec.md#requirement-the-qualify-endpoint-is-owner-guarded-and-returns-a-scorecard
     */
    public function testQualifyByNonOwnerIs404NotForbidden(): void
    {
        $skillService = $this->createMock(SkillService::class);
        $skillService->method('getSkill')->willReturn($this->skill(owner: 'alice'));

        $maturityService = $this->createMock(SkillMaturityService::class);
        $maturityService->expects($this->never())->method('qualify');

        $controller = $this->controller(
            session: $this->session('bob'),
            skillService: $skillService,
            maturityService: $maturityService
        );

        $response = $controller->qualify('skill-uuid');
        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
        $this->assertNotSame(Http::STATUS_FORBIDDEN, $response->getStatus());

    }//end testQualifyByNonOwnerIs404NotForbidden()

    /**
     * The owner's qualify call delegates to the maturity service and returns its
     * scorecard payload.
     *
     * @return void
     *
     * @spec openspec/specs/skill-maturity/spec.md#requirement-the-qualify-endpoint-is-owner-guarded-and-returns-a-scorecard
     */
    public function testQualifyByOwnerReturnsScorecard(): void
    {
        $skill        = $this->skill(owner: 'alice');
        $skillService = $this->createMock(SkillService::class);
        $skillService->method('getSkill')->willReturn($skill);

        $payload = [
            'skillId'       => 'skill-uuid',
            'maturityLevel' => 2,
            'targetLevel'   => 3,
            'scorecard'     => [],
        ];

        $maturityService = $this->createMock(SkillMaturityService::class);
        $maturityService->expects($this->once())
            ->method('qualify')
            ->with($skill)
            ->willReturn($payload);

        $controller = $this->controller(
            session: $this->session('alice'),
            skillService: $skillService,
            maturityService: $maturityService
        );

        $response = $controller->qualify('skill-uuid');
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame($payload, $response->getData());

    }//end testQualifyByOwnerReturnsScorecard()

    /**
     * Seed custodianship: an instance admin qualifies a system-seeded skill
     * (owner `__system__` — no human owner exists for seeds), while a
     * non-admin still gets 404 and a HUMAN-owned skill stays closed to admins.
     *
     * @return void
     *
     * @spec openspec/specs/skill-maturity/spec.md#requirement-the-qualify-endpoint-is-owner-guarded-and-returns-a-scorecard
     */
    public function testQualifySystemSeededSkillIsAdminCustodied(): void
    {
        $seeded       = $this->skill(owner: SeedCustodyService::SYSTEM_OWNER);
        $skillService = $this->createMock(SkillService::class);
        $skillService->method('getSkill')->willReturn($seeded);

        $maturityService = $this->createMock(SkillMaturityService::class);
        $maturityService->method('qualify')->willReturn(['scorecard' => []]);

        // Admin caller: custodian-owner of the seed → 200.
        $admin = $this->controller(
            session: $this->session('admin'),
            skillService: $skillService,
            maturityService: $maturityService,
            callerIsAdmin: true
        );
        $this->assertSame(Http::STATUS_OK, $admin->qualify('skill-uuid')->getStatus());

        // Non-admin caller: still 404 on the seed.
        $nonAdmin = $this->controller(
            session: $this->session('bob'),
            skillService: $skillService,
            maturityService: $maturityService
        );
        $this->assertSame(Http::STATUS_NOT_FOUND, $nonAdmin->qualify('skill-uuid')->getStatus());

        // A HUMAN-owned skill is NOT opened to admins by the custodian rule.
        $humanOwnedService = $this->createMock(SkillService::class);
        $humanOwnedService->method('getSkill')->willReturn($this->skill(owner: 'alice'));
        $adminOnHuman = $this->controller(
            session: $this->session('admin'),
            skillService: $humanOwnedService,
            maturityService: $maturityService,
            callerIsAdmin: true
        );
        $this->assertSame(Http::STATUS_NOT_FOUND, $adminOnHuman->qualify('skill-uuid')->getStatus());

    }//end testQualifySystemSeededSkillIsAdminCustodied()

    /**
     * Attesting an invisible skill is 404 BEFORE the action check — the action matrix
     * is never consulted, so a 403 can never confirm existence.
     *
     * @return void
     *
     * @spec openspec/specs/skill-maturity/spec.md#requirement-l4-is-human-attested-only-behind-action-authorization
     */
    public function testAttestInvisibleSkillIs404BeforeActionCheck(): void
    {
        $skillService = $this->createMock(SkillService::class);
        $skillService->method('getSkill')->willReturn(null);

        $actionAuth = $this->createMock(ActionAuthService::class);
        $actionAuth->expects($this->never())->method('requireAction');

        $controller = $this->controller(
            session: $this->session('noor'),
            skillService: $skillService,
            actionAuth: $actionAuth
        );

        $this->assertSame(Http::STATUS_NOT_FOUND, $controller->attestL4('missing')->getStatus());

    }//end testAttestInvisibleSkillIs404BeforeActionCheck()

    /**
     * A caller without the skill.attest-maturity action gets 403 and the skill is
     * unchanged — the attest stamp is never invoked.
     *
     * @return void
     *
     * @spec openspec/specs/skill-maturity/spec.md#requirement-l4-is-human-attested-only-behind-action-authorization
     */
    public function testAttestWithoutActionIs403AndSkillUnchanged(): void
    {
        $skillService = $this->createMock(SkillService::class);
        $skillService->method('getSkill')->willReturn($this->skill(owner: 'alice'));

        $actionAuth = $this->createMock(ActionAuthService::class);
        $actionAuth->method('requireAction')
            ->willThrowException(new OCSForbiddenException("Action 'skill.attest-maturity' requires admin rights"));

        $maturityService = $this->createMock(SkillMaturityService::class);
        $maturityService->expects($this->never())->method('attestL4');

        $controller = $this->controller(
            session: $this->session('bob'),
            skillService: $skillService,
            maturityService: $maturityService,
            actionAuth: $actionAuth
        );

        $this->assertSame(Http::STATUS_FORBIDDEN, $controller->attestL4('skill-uuid')->getStatus());

    }//end testAttestWithoutActionIs403AndSkillUnchanged()

    /**
     * An authorized curator's attest call stamps with the CALLER's uid and returns the
     * refreshed scorecard.
     *
     * @return void
     *
     * @spec openspec/specs/skill-maturity/spec.md#requirement-l4-is-human-attested-only-behind-action-authorization
     */
    public function testAttestByAuthorizedCuratorDelegates(): void
    {
        $skill        = $this->skill(owner: 'alice');
        $skillService = $this->createMock(SkillService::class);
        $skillService->method('getSkill')->willReturn($skill);

        $payload = [
            'skillId'       => 'skill-uuid',
            'maturityLevel' => 4,
            'targetLevel'   => 5,
            'scorecard'     => [],
        ];

        $maturityService = $this->createMock(SkillMaturityService::class);
        $maturityService->expects($this->once())
            ->method('attestL4')
            ->with($skill, 'noor', '')
            ->willReturn($payload);

        $controller = $this->controller(
            session: $this->session('noor'),
            skillService: $skillService,
            maturityService: $maturityService
        );

        $response = $controller->attestL4('skill-uuid');
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame($payload, $response->getData());

    }//end testAttestByAuthorizedCuratorDelegates()
}//end class
