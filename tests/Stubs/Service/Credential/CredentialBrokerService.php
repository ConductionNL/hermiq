<?php

/**
 * Test stub for OpenRegister's CredentialBrokerService.
 *
 * The real class ships with the `openregister` app at runtime; it is not on the
 * standalone-CI autoload path. `BrokerHttpClient::isAvailable()` is a `class_exists()`
 * check, so without this stub every LLM driver in the unit suite fails closed with
 * "the credential broker is not available" — which is the CORRECT production behaviour,
 * but makes it impossible to unit-test anything downstream of it.
 *
 * This stub only needs to EXIST for `class_exists()`. It is never actually called in the
 * unit suite: `Server::get()` cannot resolve a real container there, so any test that
 * reached `sendRequest()` would fail anyway. The broker's own guards (owner, allowed-app,
 * allow-rules, host-lock) are tested in openregister, where they live.
 *
 * Mirrors the existing `OCA\OpenRegister\Mcp\IMcpToolProvider` / ObjectService stub
 * pattern (autoload-dev PSR-4: `OCA\OpenRegister\` -> `tests/Stubs/`).
 *
 * @category Test
 * @package  OCA\OpenRegister\Service\Credential
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Credential;

/**
 * Minimal stand-in for the real broker.
 */
class CredentialBrokerService
{
    /**
     * Proxy a request, injecting the vault-held secret server-side.
     *
     * @param string                $credentialId The credential UUID.
     * @param string                $appId        The calling app.
     * @param string                $method       The HTTP method.
     * @param string                $path         The provider-relative path.
     * @param array<string, string> $headers      Request headers (auth headers discarded).
     * @param string|null           $body         The raw request body.
     * @param string|null           $actingUserId The credential owner.
     *
     * @return array{status: int, body: string} The proxied response.
     */
    public function request(
        string $credentialId,
        string $appId,
        string $method,
        string $path,
        array $headers=[],
        ?string $body=null,
        ?string $actingUserId=null
    ): array {
        return [
            'status' => 200,
            'body'   => '{}',
        ];
    }//end request()
}//end class
