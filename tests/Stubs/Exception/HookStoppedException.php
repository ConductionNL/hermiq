<?php

/**
 * Test stub for OpenRegister HookStoppedException.
 *
 * Stands in for OCA\OpenRegister\Exception\HookStoppedException when OpenRegister is not
 * installed (standalone CI). Mirrors the real exception's public surface: a message +
 * validation-errors constructor and getErrors(). OpenRegister's MagicMapper throws this
 * when a lifecycle listener stops the update event (e.g. the DPO-ack guard denies enable);
 * AiFeatureService catches it. The real class ships with OpenRegister at runtime.
 *
 * @category Test
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use Exception;
use Throwable;

/**
 * Minimal HookStoppedException stub for standalone unit runs.
 */
class HookStoppedException extends Exception
{

    /**
     * Validation errors from the hook.
     *
     * @var array<string, mixed>
     */
    private readonly array $errors;

    /**
     * Constructor.
     *
     * @param string               $message  Error message.
     * @param array<string, mixed> $errors   Hook validation errors.
     * @param int                  $code     Error code.
     * @param Throwable|null       $previous Previous exception.
     */
    public function __construct(
        string $message='Operation blocked by schema hook',
        array $errors=[],
        int $code=0,
        ?Throwable $previous=null
    ) {
        $this->errors = $errors;
        parent::__construct(message: $message, code: $code, previous: $previous);
    }//end __construct()

    /**
     * Get the hook validation errors.
     *
     * @return array<string, mixed>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }//end getErrors()
}//end class
