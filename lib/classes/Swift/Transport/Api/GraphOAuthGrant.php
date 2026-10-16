<?php

use League\OAuth2\Client\Grant\AbstractGrant;

/** Retain the registered grant's validation while repairing its scoped inputs. */
class Swift_Transport_Api_GraphOAuthGrant extends AbstractGrant
{
    public function __construct(#[SensitiveParameter] private AbstractGrant $delegate, #[SensitiveParameter] private Swift_Transport_Api_GraphOAuthGrantPolicy $policy)
    {
    }

    protected function getName()
    {
        return (string) $this->delegate;
    }

    protected function getRequiredRequestParameters()
    {
        return $this->delegate->getRequiredRequestParameters();
    }

    public function prepareRequestParameters(#[SensitiveParameter] array $defaults, #[SensitiveParameter] array $options)
    {
        return $this->policy->prepare($this->delegate, $defaults, $options);
    }
}
