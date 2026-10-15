<?php

use AsyncAws\Core\Configuration;
use AsyncAws\Core\Credentials\CredentialProvider;
use AsyncAws\Core\Credentials\Credentials;

/** Prevent a provider that catches SDK exceptions from continuing its chain after a redirect. */
class Swift_Transport_Api_SesCredentialProvider implements CredentialProvider
{
    public function __construct(private CredentialProvider $provider, private Swift_Transport_Api_SesRequestPolicy $policy)
    {
    }

    public function getCredentials(Configuration $configuration): ?Credentials
    {
        $this->policy->assertNoRedirect();
        try {
            $credentials = $this->provider->getCredentials($configuration);
        } catch (Throwable $error) {
            $this->policy->assertNoRedirect();
            throw $error;
        }
        $this->policy->assertNoRedirect();

        return $credentials;
    }

    public function getProvider(): CredentialProvider
    {
        return $this->provider;
    }
}
