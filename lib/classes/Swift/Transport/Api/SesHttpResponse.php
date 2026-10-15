<?php

use Symfony\Contracts\HttpClient\ResponseInterface;

/** Keep a stable refusal for AsyncAws, whose response destructor may resolve again. */
class Swift_Transport_Api_SesHttpResponse implements ResponseInterface
{
    private bool $checked = false;

    private ?int $redirectStatus = null;

    public function __construct(private ResponseInterface $response, private Swift_Transport_Api_SesRequestPolicy $policy, private bool $send = false)
    {
    }

    public function getStatusCode(): int
    {
        $this->checked = true;
        $status        = $this->policy->getRedirectStatus();
        if (null === $status) {
            try {
                $status = $this->response->getStatusCode();
            } catch (Throwable $error) {
                $status = $this->policy->getRedirectStatus();
                if (null === $status) {
                    throw $error;
                }
            }
        }
        if ($status >= 300 && $status < 400) {
            $this->policy->refuseRedirect($status);
            $this->redirectStatus = $status;
            $this->response->cancel();
        }

        if ($this->send) {
            $this->policy->observeSendStatus($status);
        }

        // Let AsyncAws record its normal 3xx resolve status, including debug logging
        // and destructor bookkeeping. The operation boundary rethrows our latch.
        return $status;
    }

    public function getHeaders(bool $throw = true): array
    {
        $this->getStatusCode();
        if (null !== $this->redirectStatus) {
            if ($throw) {
                $this->policy->assertNoRedirect();
            }

            return [];
        }

        return $this->response->getHeaders($throw);
    }

    public function getContent(bool $throw = true): string
    {
        $this->getStatusCode();
        if (null !== $this->redirectStatus) {
            if ($throw) {
                $this->policy->assertNoRedirect();
            }

            return '';
        }

        $content = $this->response->getContent($throw);
        $status  = $this->getStatusCode();
        if ($this->send && $status >= 200 && $status < 300) {
            $this->acceptanceData($content, $status);
        }

        return $content;
    }

    public function toArray(bool $throw = true): array
    {
        $this->getStatusCode();
        if (null !== $this->redirectStatus) {
            if ($throw) {
                $this->policy->assertNoRedirect();
            }

            return [];
        }

        $status = $this->getStatusCode();
        if ($this->send && $status >= 200 && $status < 300) {
            return $this->acceptanceData($this->response->getContent($throw), $status);
        }

        return $this->response->toArray($throw);
    }

    /** Validate before AsyncAws can coerce arrays/scalars into a successful ID. */
    private function acceptanceData(#[SensitiveParameter] string $content, int $status): array
    {
        try {
            $data = \json_decode($content, false, 512, \JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->policy->refuseAcceptance($status);
        }
        if (!$data instanceof stdClass || !isset($data->MessageId) || !\is_string($data->MessageId) || '' === $data->MessageId || \preg_match('/[\p{Cc}\p{Z}]/u', $data->MessageId)) {
            $this->policy->refuseAcceptance($status);
        }

        return (array) $data;
    }

    public function cancel(): void
    {
        $this->checked = true;
        $this->response->cancel();
    }

    public function getInfo(?string $type = null): mixed
    {
        if (null === $this->redirectStatus) {
            return $this->response->getInfo($type);
        }
        $info              = $this->response->getInfo();
        $info['http_code'] = $this->redirectStatus;
        $info['error']     = null;

        return null === $type ? $info : ($info[$type] ?? null);
    }

    public function getResponse(): ResponseInterface
    {
        return $this->response;
    }

    public function __destruct()
    {
        if (!$this->checked) {
            if (null !== $this->policy->getRedirectStatus()) {
                $this->cancel();

                return;
            }
            $this->getHeaders();
        }
    }
}
