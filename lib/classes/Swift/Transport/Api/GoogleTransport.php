<?php

use Google\Client;
use League\OAuth2\Client\Token\AccessTokenInterface;

class Swift_Transport_Api_GoogleTransport extends Swift_Transport_AbstractApiTransport
{

    private Client $googleClient;

    private AccessTokenInterface $accessToken;

    public function __construct(
        Client $googleClient,
        Swift_Events_EventDispatcher $eventDispatcher = null,

    ) {
//        $this->accessToken = $accessToken;
        $this->eventDispatcher = $eventDispatcher;


    }

    public static function fromRawCredentials(
        AccessTokenInterface|string $accessToken,
        #[SensitiveParameter] string $clientId,
        #[SensitiveParameter] string $clientSecret,
        #[SensitiveParameter] string $redirectUri,
        string $accessType = 'offline',
        ?Swift_Events_EventDispatcher $eventDispatcher = null,

    ) {
        $client = new Client();
        $client->setAccessToken($accessToken->getToken());
        $client->setClientId($clientId;
        $client->setClientSecret($clientSecret);
        $client->setRedirectUri($redirectUri);
        $client->setAccessType('offline');

//        return new self($client, );

    }

        /**
     * @inheritDoc
     */
    public function ping(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function send(Swift_Mime_SimpleMessage $message, &$failedRecipients = null): int
    {
        // TODO: Implement send() method.
    }

    public function start(): void
    {
        if (!$this->started) {
            if ($evt = $this->eventDispatcher->createTransportChangeEvent($this)) {
                $this->eventDispatcher->dispatchEvent($evt, 'beforeTransportStarted');
                if ($evt->bubbleCancelled()) {
                    return;
                }
            }

            // nothing to "start" with an API connection, but we should still honor the event dispatcher expectations
            if ($evt) {
                $this->eventDispatcher->dispatchEvent($evt, 'transportStarted');
            }

            $this->started = true;
        }
    }

    /**
     * @inheritDoc
     */
    protected function getApiConnection(): Client
    {
        return $this->googleClient;
    }
}