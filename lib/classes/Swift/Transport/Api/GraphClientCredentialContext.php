<?php

use Microsoft\Kiota\Authentication\Oauth\ClientCredentialContext;

/** Preserve explicit nonempty zero fields without changing native SDK contexts. */
class Swift_Transport_Api_GraphClientCredentialContext extends ClientCredentialContext
{
    public function __construct(
        #[SensitiveParameter] private string $tenantId,
        #[SensitiveParameter] private string $clientId,
        #[SensitiveParameter] private string $clientSecret,
    ) {
        // Initialize all native grant/cache/CAE state; replace only rejected zeros.
        parent::__construct('0' === $tenantId ? 'swift-literal-zero' : $tenantId, '0' === $clientId ? 'swift-literal-zero' : $clientId, '0' === $clientSecret ? 'swift-literal-zero' : $clientSecret);
    }

    #[Override]
    public function getTenantId(): string
    {
        return $this->tenantId;
    }

    #[Override]
    public function getClientId(): string
    {
        return $this->clientId;
    }

    #[Override]
    public function getParams(): array
    {
        return \array_replace(parent::getParams(), ['client_id' => $this->clientId, 'client_secret' => $this->clientSecret]);
    }

    #[Override]
    public function getRefreshTokenParams(#[SensitiveParameter] string $refreshToken): array
    {
        return \array_replace(parent::getRefreshTokenParams($refreshToken), ['client_id' => $this->clientId, 'client_secret' => $this->clientSecret]);
    }
}
