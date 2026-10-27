<?php

/*
 * This file is part of SwiftMailer.
 * (c) 2004-2009 Chris Corbyn
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * General utility class in Swift Mailer, not to be instantiated.
 *
 * @author Chris Corbyn
 */
abstract class Swift
{
    public const VERSION = '6.5.9';

    public static $initialized = false;

    public static $inits = [];

    /** @var array<string, string>|null lower-case Swift\... alias => original Swift_... name */
    private static ?array $aliases = null;

    /**
     * Registers an initializer callable that will be called the first time
     * a SwiftMailer class is autoloaded.
     *
     * This enables you to tweak the default configuration in a lazy way.
     *
     * @param mixed $callable A valid PHP callable that will be called when autoloading the first Swift class
     */
    public static function init($callable)
    {
        self::$inits[] = $callable;
    }

    /**
     * Internal autoloader for spl_autoload_register().
     *
     * @param string $class
     */
    public static function autoload($class)
    {
        // Don't interfere with other autoloaders
        if (!\str_starts_with($class, 'Swift_')) {
            return;
        }

        $path = __DIR__.'/'.\str_replace('_', '/', $class).'.php';

        if (!\file_exists($path)) {
            return;
        }

        // @codeCoverageIgnoreStart
        require $path;
        self::declareAlias($class);

        if (self::$inits && !self::$initialized) {
            self::$initialized = true;
            foreach (self::$inits as $init) {
                \call_user_func($init);
            }
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * Configure autoloading using Swift Mailer.
     *
     * This is designed to play nicely with other autoloaders.
     *
     * @param mixed $callable A valid PHP callable that will be called when autoloading the first Swift class
     */
    public static function registerAutoload($callable = null)
    {
        if (null !== $callable) {
            self::$inits[] = $callable;
        }
        \spl_autoload_register(['Swift', 'autoload']);
    }

    /**
     * Make namespaced aliases (e.g. Swift\Message for Swift_Message) available without loading any class.
     *
     * An alias is declared the first time its name is autoloaded, or when autoload() loads its original,
     * so `instanceof Swift\Message` and `Swift\Message` parameter types accept Swift_Message objects.
     *
     * @param array<string, string> $aliases lower-case alias => original class, as generated in lib/class_aliases.php
     */
    public static function registerAliases(array $aliases): void
    {
        if (null === self::$aliases) {
            \spl_autoload_register(self::autoloadAlias(...));
        }
        self::$aliases = $aliases + (self::$aliases ?? []);
    }

    private static function autoloadAlias(string $alias): void
    {
        $original = self::$aliases[\strtolower($alias)] ?? null;
        if (null === $original) {
            return;
        }
        if (!self::isDeclared($original)) {
            // Loading the original through autoload() declares the alias as well.
            \spl_autoload_call($original);
        }
        // Covers an original that was loaded without autoload(), e.g. by a direct require.
        self::declareAlias($original);
    }

    private static function declareAlias(string $original): void
    {
        $alias = \str_replace('_', '\\', $original);
        if (isset(self::$aliases[\strtolower($alias)]) && self::isDeclared($original) && !self::isDeclared($alias)) {
            \class_alias($original, $alias, false);
        }
    }

    private static function isDeclared(string $name): bool
    {
        return \class_exists($name, false) || \interface_exists($name, false) || \trait_exists($name, false);
    }

    /**
     * Create a mailer using a DSN-configured transport.
     *
     * The legacy name is retained; use Swift_Transport_DsnTransportFactory for a transport alone.
     *
     * @return Swift_Mailer
     */
    public static function createTransportFromDsn(#[SensitiveParameter] string $dsn)
    {
        $transport = (new Swift_Transport_DsnTransportFactory())->fromDsnString($dsn);

        return new Swift_Mailer($transport);
    }
}
