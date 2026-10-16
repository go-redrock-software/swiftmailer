<?php

use Http\Promise\FulfilledPromise;
use Http\Promise\Promise;
use Microsoft\Kiota\Abstractions\Authentication\AllowedHostsValidator;
use Microsoft\Kiota\Abstractions\Authentication\AuthenticationProvider;
use Microsoft\Kiota\Abstractions\RequestInformation;

/** Explicit externally managed Graph token; no token endpoint or HTTP defaults. */
class Swift_Transport_Api_GraphBearerAuthenticationProvider implements AuthenticationProvider
{
    private AllowedHostsValidator $hosts;

    public function __construct(#[SensitiveParameter] private string $token)
    {
        $this->hosts = new AllowedHostsValidator(['graph.microsoft.com']);
    }

    #[Override]
    public function authenticateRequest(#[SensitiveParameter] RequestInformation $request, array $additionalAuthenticationContext = []): Promise
    {
        $url   = $request->getUri();
        $parts = \parse_url($url);
        $request->getHeaders()->remove('Authorization');
        if (\is_array($parts) && isset($parts['host']) && 'https' === \strtolower($parts['scheme'] ?? '') && 443 === ($parts['port'] ?? 443) && !isset($parts['user']) && !isset($parts['pass']) && $this->hosts->isUrlHostValid($url)) {
            if (\array_key_exists('claims', $additionalAuthenticationContext)) {
                throw new Swift_TransportException('Graph bearer authentication failed; a fresh token is required.', 401);
            }
            // Kiota's generic bearer provider tests token truthiness and omits '0'.
            $request->addHeader('Authorization', 'Bearer '.$this->token);
        }

        return new FulfilledPromise($request);
    }
}
