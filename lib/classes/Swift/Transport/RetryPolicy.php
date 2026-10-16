<?php

/** Hard delivery failures take precedence over retry and failover strategies. */
final class Swift_Transport_RetryPolicy
{
    public static function isTerminalFailure(#[SensitiveParameter] Throwable $error): bool
    {
        for ($cause = $error; null !== $cause; $cause = $cause->getPrevious()) {
            if ($cause instanceof Swift_Transport_NonRetryableException || $cause instanceof Error) {
                return true;
            }
        }

        return false;
    }
}
