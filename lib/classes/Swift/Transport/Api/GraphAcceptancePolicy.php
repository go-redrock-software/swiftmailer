<?php

use GuzzleHttp\Psr7\Utils;
use Microsoft\Graph\Generated\Models\Attachment;
use Microsoft\Graph\Generated\Models\Event;
use Microsoft\Graph\Generated\Models\EventCollectionResponse;
use Microsoft\Graph\Generated\Models\Message;
use Microsoft\Graph\Generated\Models\UploadSession;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** Raw acceptance evidence and permanent ambiguity for one Graph send. */
class Swift_Transport_Api_GraphAcceptancePolicy
{
    private ?Swift_Transport_NonRetryableException $failure = null;

    private string $operation = '';

    private ?int $status = null;

    private bool $accepted = false;

    private bool $notified = false;

    private ?stdClass $data = null;

    private ?string $uploadUrl = null;

    private ?DateTimeImmutable $uploadExpiry = null;

    private int $uploaded = 0;

    private ?int $uploadSize = null;

    private bool $uploadComplete = false;

    public function run(string $operation, #[SensitiveParameter] Closure $send, ?int $uploadSize = null): mixed
    {
        $this->assertUsable();
        $this->operation      = $operation;
        $this->status         = null;
        $this->accepted       = false;
        $this->data           = null;
        $this->uploaded       = 0;
        $this->uploadSize     = $uploadSize;
        $this->uploadComplete = false;
        try {
            $result = $send();
            $this->assertUsable();
            if (!$this->accepted || !$this->validResult($result)) {
                $this->refuse();
            }

            return $result;
        } catch (Throwable $error) {
            $this->assertAfterFailure($error);
            throw $error;
        }
    }

    public function assertMailAccepted(): void
    {
        $this->assertUsable();
        if (!$this->accepted || !\in_array($this->operation, ['sendMail', 'draft send'], true)) {
            $this->refuse();
        }
    }

    public function assertUsable(): void
    {
        if (null !== $this->failure) {
            throw $this->failure;
        }
    }

    public function validateRequest(#[SensitiveParameter] RequestInterface $request): void
    {
        $this->assertUsable();
        if ('upload chunk' === $this->operation) {
            $this->chunkRange($request);
        } elseif ($this->accepted) {
            // Existing middleware must not replay an acknowledged operation.
            $this->refuse();
        }
    }

    public function validateResponse(#[SensitiveParameter] RequestInterface $request, #[SensitiveParameter] ResponseInterface $response, #[SensitiveParameter] ?Throwable $error = null): ResponseInterface
    {
        $this->assertUsable();
        $this->status = $response->getStatusCode();
        if ($this->status < 200 || $this->status >= 300) {
            return $response;
        }
        if (null !== $error) {
            $this->refuse();
        }
        try {
            $body = (string) $response->getBody();
            if ('upload chunk' === $this->operation) {
                $this->validateChunk($request, $response, $body);
            } elseif (\in_array($this->operation, ['sendMail', 'draft send', 'event cancel'], true)) {
                if (202 !== $this->status || '' !== $body) {
                    $this->refuse();
                }
                $this->notified = true;
            } else {
                $this->data     = self::object($body);
                $expectedStatus = \in_array($this->operation, ['event lookup', 'event update'], true) ? 200 : 201;
                if ($this->status !== $expectedStatus) {
                    $this->refuse();
                }
                if ('event lookup' === $this->operation) {
                    $this->validateCollection($request, $this->data);
                } elseif ('upload session' === $this->operation) {
                    $this->validateSession($this->data);
                } elseif (!self::validId($this->data->id ?? null)) {
                    $this->refuse();
                }
                if ('event update' === $this->operation && $this->data->id !== \rawurldecode(\basename($request->getUri()->getPath()))) {
                    $this->refuse();
                }
                if (\in_array($this->operation, ['event create', 'event update'], true)) {
                    $this->notified = true;
                }
            }
        } catch (Throwable) {
            $this->refuse();
        }
        $this->accepted = true;

        // Keep the SDK parser usable after inspecting a nonseekable stream. The
        // documented final Outlook upload has no model, even with a JSON header.
        if ('upload chunk' === $this->operation && $this->uploadComplete && '' === $body) {
            $response = $response->withoutHeader('Content-Type');
        }

        return $response->withBody(Utils::streamFor($body));
    }

    public function assertAfterFailure(#[SensitiveParameter] Throwable $error): void
    {
        $this->assertUsable();
        if (null !== $this->status && $this->status >= 200 && $this->status < 300) {
            $this->refuse();
        }
        if ($this->notified) {
            // Replaying the entire Swift send would repeat an earlier invitation.
            $this->status = Swift_Transport_Api_GraphRequestPolicy::detachedFailure($error)->getCode();
            $this->refuse();
        }
    }

    private function validResult(#[SensitiveParameter] mixed $result): bool
    {
        return match ($this->operation) {
            'sendMail', 'draft send', 'event cancel' => null                                                           === $result,
            'draft'                                  => $result instanceof Message                 && $result->getId() === $this->data?->id,
            'small attachment'                       => $result instanceof Attachment              && $result->getId() === $this->data?->id,
            'event create', 'event update'           => $result instanceof Event                   && $result->getId() === $this->data?->id,
            'event lookup'                           => $result instanceof EventCollectionResponse && $this->validCollectionResult($result),
            'upload session'                         => $result instanceof UploadSession           && $result->getUploadUrl() === $this->uploadUrl && $result->getExpirationDateTime()?->format('U.u') === $this->uploadExpiry?->format('U.u') && $result->getNextExpectedRanges() === $this->data?->nextExpectedRanges,
            'upload chunk'                           => $this->uploadComplete                      && $this->uploaded         === $this->uploadSize,
            default                                  => false,
        };
    }

    private function validCollectionResult(#[SensitiveParameter] EventCollectionResponse $result): bool
    {
        $events = $result->getValue();
        if (null === $events || \count($events) !== \count($this->data->value) || $result->getOdataNextLink() !== ($this->data->{'@odata.nextLink'} ?? null)) {
            return false;
        }
        foreach ($events as $index => $event) {
            if ($event->getId() !== $this->data->value[$index]->id) {
                return false;
            }
        }

        return true;
    }

    private function validateCollection(#[SensitiveParameter] RequestInterface $request, #[SensitiveParameter] stdClass $data): void
    {
        if (!isset($data->value) || !\is_array($data->value)) {
            $this->refuse();
        }
        foreach ($data->value as $event) {
            if (!$event instanceof stdClass || !self::validId($event->id ?? null)) {
                $this->refuse();
            }
        }
        if (\property_exists($data, '@odata.nextLink')) {
            $next = $data->{'@odata.nextLink'};
            if (!self::validUrl($next)) {
                $this->refuse();
            }
            $uri     = new GuzzleHttp\Psr7\Uri($next);
            $current = $request->getUri();
            if ('' === $uri->getQuery() || (string) $uri === (string) $current || [$uri->getScheme(), $uri->getHost(), $uri->getPort(), $uri->getPath()] !== [$current->getScheme(), $current->getHost(), $current->getPort(), $current->getPath()]) {
                $this->refuse();
            }
        }
    }

    private function validateSession(#[SensitiveParameter] stdClass $data): void
    {
        $expiry = $data->expirationDateTime ?? null;
        if (!self::validUrl($data->uploadUrl ?? null) || !\is_string($expiry) || 1 !== \preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,7})?(?:Z|[+-]\d{2}:\d{2})$/D', $expiry) || !\in_array($data->nextExpectedRanges ?? null, [['0-'], ['0']], true)) {
            $this->refuse();
        }
        $date   = new DateTimeImmutable($expiry);
        $errors = DateTimeImmutable::getLastErrors();
        if ((false !== $errors && ($errors['warning_count'] || $errors['error_count'])) || $date->getTimestamp() <= \time()) {
            $this->refuse();
        }
        $this->uploadUrl    = $data->uploadUrl;
        $this->uploadExpiry = $date;
    }

    /** @return array{int, int, int} */
    private function chunkRange(#[SensitiveParameter] RequestInterface $request): array
    {
        if ($this->uploadComplete || null === $this->uploadUrl || (string) $request->getUri() !== (string) new GuzzleHttp\Psr7\Uri($this->uploadUrl) || 'PUT' !== $request->getMethod() || 1 !== \preg_match('/^bytes (\d+)-(\d+)\/(\d+)$/D', $request->getHeaderLine('Content-Range'), $parts)) {
            $this->refuse();
        }
        [$start, $end, $total] = [(int) $parts[1], (int) $parts[2], (int) $parts[3]];
        if ($start !== $this->uploaded || $end < $start || $end >= $total || $total !== $this->uploadSize || $request->getHeaderLine('Content-Length') !== (string) ($end - $start + 1)) {
            $this->refuse();
        }

        return [$start, $end, $total];
    }

    private function validateChunk(#[SensitiveParameter] RequestInterface $request, #[SensitiveParameter] ResponseInterface $response, #[SensitiveParameter] string $body): void
    {
        [, $end, $total] = $this->chunkRange($request);
        if ($end === $total - 1) {
            if (201 !== $this->status || !self::validUrl($response->getHeaderLine('Location'))) {
                $this->refuse();
            }
            if ('' !== $body && !self::validId(self::object($body)->id ?? null)) {
                $this->refuse();
            }
            $this->uploadComplete = true;
        } else {
            $data   = self::object($body);
            $ranges = $data->nextExpectedRanges ?? null;
            if (200 !== $this->status || !\is_array($ranges) || 1 !== \count($ranges) || !\is_string($ranges[0]) || 1 !== \preg_match('/^(\d+)(?:-(\d*))?$/D', $ranges[0], $parts) || (string) ($end + 1) !== $parts[1] || (isset($parts[2]) && '' !== $parts[2] && (string) ($total - 1) !== $parts[2])) {
                $this->refuse();
            }
        }
        $this->uploaded = $end + 1;
    }

    private static function object(#[SensitiveParameter] string $body): stdClass
    {
        $data = \json_decode($body, flags: JSON_THROW_ON_ERROR);
        if (!$data instanceof stdClass || \property_exists($data, 'error')) {
            throw new UnexpectedValueException('Graph returned no operation acceptance object.');
        }

        return $data;
    }

    private static function validId(#[SensitiveParameter] mixed $id): bool
    {
        return \is_string($id) && '' !== $id && 0 === \preg_match('/[\p{Cc}\p{Z}\s]/u', $id);
    }

    private static function validUrl(#[SensitiveParameter] mixed $url): bool
    {
        if (!self::validId($url) || false === \filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        $parts = \parse_url($url);

        return \is_array($parts) && 'https' === \strtolower($parts['scheme'] ?? '') && !empty($parts['host']) && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['fragment']);
    }

    private function refuse(): never
    {
        $this->failure ??= new Swift_Transport_NonRetryableException('Graph operation acceptance could not be confirmed; automatic retry is disabled.', $this->status ?? 0);
        throw $this->failure;
    }
}
