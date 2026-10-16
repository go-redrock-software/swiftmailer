<?php

use League\OAuth2\Client\Grant\AbstractGrant;
use League\OAuth2\Client\Grant\GrantFactory;

/** A private factory copy; the caller retains its original registry and grants. */
class Swift_Transport_Api_GraphOAuthGrantFactory extends GrantFactory
{
    public function __construct(#[SensitiveParameter] private GrantFactory $delegate, #[SensitiveParameter] private Swift_Transport_Api_GraphOAuthGrantPolicy $policy)
    {
    }

    public function getGrant($name)
    {
        $this->policy->assertValid();
        try {
            $grant = $this->grant($this->delegate->getGrant($name));

            return $this->policy->needsPreparation((string) $grant) ? new Swift_Transport_Api_GraphOAuthGrant($grant, $this->policy) : $grant;
        } catch (Throwable $error) {
            if ($this->policy->contextChanged()) {
                $this->policy->reject('The returned Graph context cannot prepare its OAuth grant.', $error);
            }
            throw $error;
        }
    }

    /** Custom factories have no native return type; validate their runtime result. */
    private function grant(mixed $grant): AbstractGrant
    {
        if (!$grant instanceof AbstractGrant) {
            $this->policy->reject('The Graph OAuth grant factory returned an unsupported grant.');
        }

        return $grant;
    }

    public function setGrant($name, AbstractGrant $grant)
    {
        $this->delegate->setGrant($name, $grant);

        return $this;
    }
}
