<?php

/**
 * Unit tests for PreferencesController.
 *
 * Covers the generic per-user preference read/write contract (used by shared
 * @conduction/nextcloud-vue widgets such as CnSupportDialog, and by Hermiq's
 * own Talk-delivery-target picker — see src/api/talk.js): default/stored
 * reads, write + clear, key sanitisation, and the unauthenticated-caller
 * guard on both endpoints.
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
 * @spec exclude Generic preferences infra ported from nextcloud-app-template;
 *   no per-app behavioural spec.
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\PreferencesController;
use OCP\AppFramework\Http;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PreferencesController.
 */
class PreferencesControllerTest extends TestCase
{

    /**
     * Mock IRequest.
     *
     * @var IRequest&MockObject
     */
    private IRequest&MockObject $request;

    /**
     * Mock IConfig.
     *
     * @var IConfig&MockObject
     */
    private IConfig&MockObject $config;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->config  = $this->createMock(IConfig::class);

    }//end setUp()

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
     * Build the controller with the given user session.
     *
     * @param string|null $uid The acting user's UID, or null for unauthenticated.
     *
     * @return PreferencesController
     */
    private function controller(?string $uid): PreferencesController
    {
        return new PreferencesController(
            $this->request,
            $this->config,
            $this->session($uid)
        );

    }//end controller()

    /**
     * getPreference() returns null when nothing is stored yet.
     *
     * @return void
     */
    public function testGetPreferenceReturnsNullWhenUnset(): void
    {
        $this->config->method('getUserValue')
            ->with('bob', 'hermiq', 'pref_delivertarget', '')
            ->willReturn('');

        $controller = $this->controller('bob');
        $response   = $controller->getPreference('delivertarget');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertNull($response->getData()['value']);

    }//end testGetPreferenceReturnsNullWhenUnset()

    /**
     * getPreference() returns the stored value, namespaced under the acting user + app.
     *
     * @return void
     */
    public function testGetPreferenceReturnsStoredValue(): void
    {
        $this->config->expects($this->once())
            ->method('getUserValue')
            ->with('bob', 'hermiq', 'pref_delivertarget', '')
            ->willReturn('room-token-42');

        $controller = $this->controller('bob');
        $response   = $controller->getPreference('delivertarget');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('room-token-42', $response->getData()['value']);

    }//end testGetPreferenceReturnsStoredValue()

    /**
     * getPreference() rejects an unauthenticated caller — 401, never reads config.
     *
     * @return void
     */
    public function testGetPreferenceRejectsUnauthenticated(): void
    {
        $this->config->expects($this->never())->method('getUserValue');

        $controller = $this->controller(null);
        $response   = $controller->getPreference('delivertarget');

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

    }//end testGetPreferenceRejectsUnauthenticated()

    /**
     * getPreference() rejects a key that sanitises to empty — 400, never reads config.
     *
     * @return void
     */
    public function testGetPreferenceRejectsInvalidKey(): void
    {
        $this->config->expects($this->never())->method('getUserValue');

        $controller = $this->controller('bob');
        $response   = $controller->getPreference('!!!');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

    }//end testGetPreferenceRejectsInvalidKey()

    /**
     * setPreference() persists a non-empty value and echoes it back.
     *
     * @return void
     */
    public function testSetPreferencePersistsValue(): void
    {
        $this->config->expects($this->once())
            ->method('setUserValue')
            ->with('bob', 'hermiq', 'pref_delivertarget', 'room-token-42');
        $this->config->expects($this->never())->method('deleteUserValue');

        $controller = $this->controller('bob');
        $response   = $controller->setPreference('delivertarget', 'room-token-42');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('room-token-42', $response->getData()['value']);

    }//end testSetPreferencePersistsValue()

    /**
     * setPreference() with an empty value clears the stored preference.
     *
     * @return void
     */
    public function testSetPreferenceWithEmptyValueClears(): void
    {
        $this->config->expects($this->once())
            ->method('deleteUserValue')
            ->with('bob', 'hermiq', 'pref_delivertarget');
        $this->config->expects($this->never())->method('setUserValue');

        $controller = $this->controller('bob');
        $response   = $controller->setPreference('delivertarget', '');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertNull($response->getData()['value']);

    }//end testSetPreferenceWithEmptyValueClears()

    /**
     * setPreference() rejects an unauthenticated caller — 401, never writes config.
     *
     * @return void
     */
    public function testSetPreferenceRejectsUnauthenticated(): void
    {
        $this->config->expects($this->never())->method('setUserValue');
        $this->config->expects($this->never())->method('deleteUserValue');

        $controller = $this->controller(null);
        $response   = $controller->setPreference('delivertarget', 'room-token-42');

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

    }//end testSetPreferenceRejectsUnauthenticated()

    /**
     * setPreference() rejects a key that sanitises to empty — 400, never writes config.
     *
     * @return void
     */
    public function testSetPreferenceRejectsInvalidKey(): void
    {
        $this->config->expects($this->never())->method('setUserValue');
        $this->config->expects($this->never())->method('deleteUserValue');

        $controller = $this->controller('bob');
        $response   = $controller->setPreference('!!!', 'value');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

    }//end testSetPreferenceRejectsInvalidKey()
}//end class
