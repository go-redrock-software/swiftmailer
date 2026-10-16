<?php

use Google\Auth\Credentials\UserRefreshCredentials;
use Google\Auth\HttpHandler\HttpHandlerFactory;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\RequestInterface;

/** Keep explicit OAuth form credentials when Google auth filters string zero. */
class Swift_Transport_Api_GmailRefreshCredentials extends UserRefreshCredentials
{
    #[Override]
    public function fetchAuthToken(#[SensitiveParameter] ?callable $httpHandler = null, array $headers = []): array
    {
        $httpHandler ??= HttpHandlerFactory::build();

        return parent::fetchAuthToken(function (RequestInterface $request, array $options = []) use ($httpHandler) {
            \parse_str((string) $request->getBody(), $parameters);
            $parameters['client_id']     = $this->auth->getClientId();
            $parameters['client_secret'] = $this->auth->getClientSecret();
            $request                     = $request->withBody(Utils::streamFor(\http_build_query($parameters, '', '&', PHP_QUERY_RFC3986)));

            return $httpHandler($request, $options);
        }, $headers);
    }
}
