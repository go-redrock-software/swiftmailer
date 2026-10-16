<?php

use Google\Service\Gmail\Message;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** Acceptance evidence and permanent ambiguity for one Gmail send operation. */
class Swift_Transport_Api_GmailRequestPolicy
{
    private ?int $sendStatus = null;

    private bool $accepted = false;

    private ?Swift_Transport_NonRetryableException $failure = null;

    public function assertUsable(): void
    {
        if (null !== $this->failure) {
            throw $this->failure;
        }
    }

    public function validateRequest(#[SensitiveParameter] RequestInterface $request): void
    {
        $this->assertUsable();
        if ($this->accepted && 'POST' === $request->getMethod() && \str_ends_with($request->getUri()->getPath(), '/users/me/messages/send')) {
            $this->refuseAcceptance();
        }
    }

    public function validateResponse(#[SensitiveParameter] RequestInterface $request, #[SensitiveParameter] ResponseInterface $response, #[SensitiveParameter] ?Throwable $error = null): ResponseInterface
    {
        $this->assertUsable();
        if ('POST' !== $request->getMethod() || !\str_ends_with($request->getUri()->getPath(), '/users/me/messages/send')) {
            return $response;
        }
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            return $response;
        }
        $this->sendStatus = $status;
        if (null !== $error) {
            $this->refuseAcceptance();
        }
        try {
            $body = (string) $response->getBody();
            $data = \json_decode($body, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $this->refuseAcceptance();
        }
        if (!$data instanceof stdClass || !isset($data->id) || !self::validId($data->id)) {
            $this->refuseAcceptance();
        }
        $this->accepted = true;

        // A nonseekable response has been consumed; Google still needs its JSON.
        return $response->withBody(Utils::streamFor($body));
    }

    public function assertAccepted(#[SensitiveParameter] mixed $result): void
    {
        $this->assertUsable();
        if (!$this->accepted || !$result instanceof Message || !self::validId($result->getId())) {
            $this->refuseAcceptance();
        }
    }

    public function assertAfterFailure(): void
    {
        $this->assertUsable();
        if (null !== $this->sendStatus) {
            $this->refuseAcceptance();
        }
    }

    private static function validId(#[SensitiveParameter] mixed $id): bool
    {
        return \is_string($id) && '' !== $id && 0 === \preg_match('/[\p{Cc}\p{Z}\s]/u', $id);
    }

    private function refuseAcceptance(): never
    {
        $this->failure ??= new Swift_Transport_NonRetryableException('Gmail acceptance could not be confirmed.', $this->sendStatus ?? 0);
        throw $this->failure;
    }
}
