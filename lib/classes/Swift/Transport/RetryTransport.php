<?php

/*
 * Copyright (c) 2024. Redrock Software Corporation
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Decorator transport that retries sending on transient failures with exponential backoff.
 *
 * Wraps any Swift_Transport and catches Swift_TransportException on send().
 * If the exception is classified as retryable (connection timeout, SMTP 4xx, HTTP 429/5xx),
 * the send is retried up to maxRetries times with exponential backoff + jitter.
 *
 * Usage:
 *     $inner = new Swift_SmtpTransport('smtp.example.com', 587, 'tls');
 *     $retry = new Swift_Transport_RetryTransport($inner, maxRetries: 3, baseDelayMs: 1000);
 *     $mailer = new Swift_Mailer($retry);
 *
 * Backoff formula: delay = baseDelayMs * 2^attempt + random(0, baseDelayMs/2)
 */
class Swift_Transport_RetryTransport implements Swift_Transport
{
    private Swift_Transport $innerTransport;

    private int $maxRetries;

    private int $baseDelayMs;

    private Swift_Transport_RetryClassifier $classifier;

    /**
     * @param Swift_Transport                      $innerTransport The transport to wrap
     * @param int                                  $maxRetries     Maximum number of retry attempts (default: 3)
     * @param int                                  $baseDelayMs    Base delay in milliseconds for backoff (default: 1000)
     * @param Swift_Transport_RetryClassifier|null $classifier     Strategy for classifying retryable exceptions
     */
    public function __construct(
        Swift_Transport $innerTransport,
        int $maxRetries = 3,
        int $baseDelayMs = 1000,
        ?Swift_Transport_RetryClassifier $classifier = null,
    ) {
        $this->innerTransport = $innerTransport;
        $this->maxRetries     = $maxRetries;
        $this->baseDelayMs    = $baseDelayMs;
        $this->classifier     = $classifier ?? new Swift_Transport_DefaultRetryClassifier();
    }

    /**
     * Get the wrapped inner transport.
     */
    public function getInnerTransport(): Swift_Transport
    {
        return $this->innerTransport;
    }

    public function isStarted(): bool
    {
        return $this->innerTransport->isStarted();
    }

    public function start(): void
    {
        $this->innerTransport->start();
    }

    public function stop(): void
    {
        $this->innerTransport->stop();
    }

    public function ping(): bool
    {
        return $this->innerTransport->ping();
    }

    /**
     * Send with retry logic.
     *
     * Attempts to send via the inner transport. On transient failures, retries
     * up to maxRetries times with exponential backoff. Permanent failures are
     * thrown immediately without retry.
     *
     * @param string[] $failedRecipients An array of failures by-reference
     *
     * @return int Number of accepted recipients
     *
     * @throws Swift_TransportException on permanent failure or after all retries exhausted
     */
    public function send(Swift_Mime_SimpleMessage $message, &$failedRecipients = null, ?Swift_Envelope $envelope = null): int
    {
        $attempt = 0;

        while (true) {
            try {
                return $this->innerTransport->send($message, $failedRecipients);
            } catch (Swift_TransportException $e) {
                if (!$this->classifier->isRetryable($e) || $attempt >= $this->maxRetries) {
                    throw $e;
                }

                $this->backoff($attempt);
                ++$attempt;

                // Restart transport in case connection was dropped
                if (!$this->innerTransport->isStarted()) {
                    try {
                        $this->innerTransport->start();
                    } catch (Swift_TransportException $startException) {
                        // Will be retried on next iteration
                    }
                }
            }
        }
    }

    public function registerPlugin(Swift_Events_EventListener $plugin): void
    {
        $this->innerTransport->registerPlugin($plugin);
    }

    /**
     * Sleep for exponential backoff duration.
     *
     * Formula: baseDelayMs * 2^attempt + random jitter (0 to baseDelayMs/2)
     */
    protected function backoff(int $attempt): void
    {
        if ($this->baseDelayMs <= 0) {
            return;
        }

        $delay   = $this->baseDelayMs * (2 ** $attempt);
        $jitter  = \random_int(0, (int) ($this->baseDelayMs / 2));
        $totalMs = \min($delay + $jitter, 60000);

        \usleep($totalMs * 1000);
    }
}
