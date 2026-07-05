<?php

/**
 * Test stub for OC\Hooks\Emitter.
 *
 * Nextcloud's OCP\Files\IRootFolder extends the private OC\Hooks\Emitter interface, which
 * is not shipped in the nextcloud/ocp stubs. This empty stub lets standalone unit tests
 * mock IRootFolder (and other file interfaces) without the full Nextcloud server. The real
 * interface ships with the Nextcloud server.
 *
 * @category Test
 * @package  OC\Hooks
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OC\Hooks;

/**
 * Minimal Emitter stub for standalone unit runs.
 */
interface Emitter
{

    /**
     * Register a listener.
     *
     * @param string   $scope    The emitting scope.
     * @param string   $method   The emitting method.
     * @param callable $callback The listener callback.
     *
     * @return void
     */
    public function listen(string $scope, string $method, callable $callback): void;

    /**
     * Remove a listener.
     *
     * @param string|null   $scope    The scope, or null for all.
     * @param string|null   $method   The method, or null for all.
     * @param callable|null $callback The callback, or null for all.
     *
     * @return void
     */
    public function removeListener(?string $scope=null, ?string $method=null, ?callable $callback=null): void;
}//end interface
