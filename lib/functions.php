<?php

namespace Swift;

/**
 * Encodes a string into a URL-safe base64 format.
 */
function base64url_encode(?string $text = ''): string
{
    return \rtrim(\strtr(\base64_encode($text), '+/', '-_'), '=');
}

/**
 * Decodes a base64url string.
 */
function base64url_decode(?string $text = ''): string
{
    $decoded = \base64_decode(\strtr($text, '-_', '+/'), true);
    if (false === $decoded) {
        throw new \InvalidArgumentException('Invalid data provided');
    }

    return $decoded;
}

/**
 * Replaces all ascii text with a given character. This is helpful for hiding the contents of a string but without destroying its format, layout, etc.
 *
 * @return string|string[]|null
 */
function obfuscate(string $text, string $replaceWith = 'x'): array|string|null
{
    $chars = \preg_quote('#/\!?@%^&*()_+=[]{}~"“”‘’\'`~<>,.|;:…—–-', '/');

    // u at the end is for unicode so it is multibyte safe
    // \s space, tab, newline, carriage return, vertical tab
    return \preg_replace('/[^'.$chars.'\s]/u', $replaceWith, $text);
}

/**
 * Convert the Swift message to a raw base64url encoded message string.
 */
function getRawMessage(\Swift_Mime_SimpleMessage $message): string
{
    $messageString = $message->toString();
    // Handle attachments
    foreach ($message->getChildren() ?? [] as $attachment) {
        if ($attachment instanceof \Swift_Mime_Attachment) {
            $attachmentString = $attachment->toString();
            $messageString .= "\r\n".$attachmentString;
        }
    }

    return \base64_encode($messageString);
}
