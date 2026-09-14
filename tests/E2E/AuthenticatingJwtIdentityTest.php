<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Fixtures\Authenticating\JwtHttp;

final class AuthenticatingJwtIdentityTest extends AuthenticatingTestCase
{
    public function testObserveNativeIssuanceAndIdentityHttpCost(): void
    {
        $account = $this->account();
        $issuance = [];
        $identity = [];
        for ($sample = 0; $sample < 3; ++$sample) {
            $start = hrtime(true);
            $response = JwtHttp::login($account['email'], $account['password']);
            $issuance[] = (hrtime(true) - $start) / 1e6;
            $token = JwtHttp::token($response);
            $start = hrtime(true);
            $response = JwtHttp::me($token);
            $identity[] = (hrtime(true) - $start) / 1e6;
            JwtHttp::identity($response, $account['id'], $account['email']);
        }
        sort($issuance);
        sort($identity);
        printf("\nHTTP observation (3 samples, no SLA): issuance median %.2f ms; /api/me median %.2f ms.\n", $issuance[1], $identity[1]);
    }

    public function testIdentityUsesItsOwnDatabaseReadInsteadOfSerializingTheAuthenticatedPrincipal(): void
    {
        $account = $this->account();
        $token = JwtHttp::token(JwtHttp::login($account['email'], $account['password']));
        $projectedEmail = $this->prefix.'-identity-query@example.test';
        // Disposable PostgreSQL instrumentation, not an app/provider replacement.
        // The first credential read sees the stored email. Every later identity
        // read sees a different email, making direct principal projection observable.
        // The indexed UUID predicate still selects the same real account row.
        $this->connection->executeStatement('CREATE SEQUENCE public.authenticating_jwt_identity_reads');
        try {
            $this->connection->executeStatement('ALTER TABLE public.authenticating_account RENAME TO authenticating_jwt_identity_source');
            try {
                $this->connection->executeStatement('CREATE VIEW public.authenticating_account AS SELECT id, CASE WHEN id = '.$this->connection->quote($account['id'])."::uuid THEN CASE WHEN nextval('public.authenticating_jwt_identity_reads') = 1 THEN email ELSE ".$this->connection->quote($projectedEmail).' END ELSE email END AS email, password_hash FROM public.authenticating_jwt_identity_source');
                try {
                    JwtHttp::identity(JwtHttp::me($token), $account['id'], $projectedEmail);
                    self::assertSame(2, $this->connection->fetchOne('SELECT last_value FROM public.authenticating_jwt_identity_reads'), 'Bearer authentication and the Application identity query must each read the account once.');
                } finally {
                    $this->connection->executeStatement('DROP VIEW public.authenticating_account');
                }
            } finally {
                $this->connection->executeStatement('ALTER TABLE public.authenticating_jwt_identity_source RENAME TO authenticating_account');
            }
        } finally {
            $this->connection->executeStatement('DROP SEQUENCE public.authenticating_jwt_identity_reads');
        }
        JwtHttp::identity(JwtHttp::me($token), $account['id'], $account['email']);
    }
}
