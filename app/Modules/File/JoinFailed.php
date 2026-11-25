<?php

namespace App\Modules\File;

use RuntimeException;

/**
 * A step of the domain join (DomainJoin) failed before the file server got its request. The
 * message is German and comes from Core or from PK95 AD's error answer, never from a request
 * body (the provisioning blob is not in it).
 *
 * kind: domain (no domain data), dns (the name points elsewhere or the record failed),
 * exists (PK95 AD: the computer account exists, 409 → offer "Neu verbinden"), odj (any other
 * failure of the blob request → offer the password fallback).
 */
class JoinFailed extends RuntimeException
{
    /**
     * @param  array<string, string>  $fields  field errors of PK95 AD for the wizard form ("hostname")
     */
    public function __construct(string $message, public readonly string $kind, public readonly array $fields = [])
    {
        parent::__construct($message);
    }
}
