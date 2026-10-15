<?php

use Symfony\Contracts\HttpClient\ResponseInterface;

/** Keep a stable refusal for AsyncAws, whose response destructor may resolve again. */
class Swift_Transport_Api_SesHttpResponse implements ResponseInterface
{
    private bool $checked = false;

    private ?int $redirectStatus = null;

    public function __construct(private ResponseInterface $response, private Swift_Transport_Api_SesRequestPolicy $policy)
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

        return $this->response->getContent($throw);
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

        return $this->response->toArray($throw);
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
