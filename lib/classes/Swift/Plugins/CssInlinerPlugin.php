<?php

/*
 * Copyright (c) 2024. Redrock Software Corporation
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Plugin that inlines CSS styles in HTML email bodies.
 *
 * Requires the optional dependency tijsverkoyen/css-to-inline-styles.
 * If not installed, this plugin does nothing.
 */
class Swift_Plugins_CssInlinerPlugin implements Swift_Events_SendListener
{
    #[Override]
    public function beforeSendPerformed(Swift_Events_SendEvent $evt): void
    {
        if (!\class_exists(TijsVerkoyen\CssToInlineStyles\CssToInlineStyles::class)) {
            return;
        }

        $message = $evt->getMessage();

        // Inline the main body if HTML
        if ('text/html' === $message->getBodyContentType()) {
            $message->setBody($this->inlineCss($message->getBody()), 'text/html');
        }

        // Inline any HTML child parts
        foreach ($message->getChildren() ?? [] as $child) {
            if ($child instanceof Swift_MimePart && 'text/html' === $child->getContentType()) {
                $child->setBody($this->inlineCss($child->getBody()), 'text/html');
            }
        }
    }

    #[Override]
    public function sendPerformed(Swift_Events_SendEvent $evt): void
    {
        // No-op — required by interface
    }

    private function inlineCss(string $html): string
    {
        $inliner = new TijsVerkoyen\CssToInlineStyles\CssToInlineStyles();

        return $inliner->convert($html);
    }
}
