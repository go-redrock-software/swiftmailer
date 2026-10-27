<?php

use PHPUnit\Framework\TestCase;

class Swift_LazyNamespaceAliasesTest extends TestCase
{
    private ?string $directory = null;

    protected function tearDown(): void
    {
        if (null !== $this->directory) {
            @\unlink($this->directory.'/isolated.php');
            @\rmdir($this->directory);
        }
        parent::tearDown();
    }

    /** @dataProvider bootstrapEntryPoints */
    public function testBootstrapLoadsNoSwiftClassUntilANamespacedNameIsUsed(string $entryPoint): void
    {
        // swift_required.php is a Composer "files" entry, so it runs on every request of the host
        // application. Requests that never send mail must not pay for loading the library.
        $result = $this->runIsolated(<<<'PHP'
            <?php
            require $argv[1];
            $swift  = static fn (): array => array_values(array_filter(array_merge(get_declared_classes(), get_declared_interfaces(), get_declared_traits()), static fn (string $name): bool => 0 === stripos($name, 'swift')));
            $result = ['declaredAfterBootstrap' => $swift(), 'initializedAfterBootstrap' => Swift::$initialized];
            // Declarations are the first references here, so they must autoload the aliases themselves.
            $result['extends']           = get_parent_class(new class extends Swift\ByteStream\ArrayByteStream {});
            $result['implements']        = (new class implements Swift\Events\EventListener {}) instanceof Swift_Events_EventListener;
            $result['alias']             = (new ReflectionClass('Swift\Message'))->getName();
            $result['initializedOnUse']  = Swift::$initialized;
            $result['preferenceCharset'] = Swift_DependencyContainer::getInstance()->lookup('properties.charset');
            echo json_encode($result);
            PHP, $entryPoint);
        $this->assertSame([
            'declaredAfterBootstrap'    => ['Swift'],
            'initializedAfterBootstrap' => false,
            'extends'                   => 'Swift_ByteStream_ArrayByteStream',
            'implements'                => true,
            'alias'                     => 'Swift_Message',
            'initializedOnUse'          => true,
            'preferenceCharset'         => 'utf-8',
        ], $result);
    }

    public static function bootstrapEntryPoints(): iterable
    {
        yield 'Composer autoloader' => [\dirname(__DIR__, 3).'/vendor/autoload.php'];
    }

    public function testAliasIsDeclaredWithItsOriginalSoNamespacedTypeChecksAcceptUnderscoreObjects(): void
    {
        // instanceof and parameter types never autoload. Declaring the alias when the original loads
        // keeps them equivalent to the former eager aliases for every object the library can create.
        // Only the existence check of an alias whose original was never used differs (false, was true).
        $result = $this->runIsolated(<<<'PHP'
            <?php
            require $argv[1];
            $message = new Swift_Message('Subject');
            $accepts = static fn (Swift\Mime\SimpleMessage $message): string => $message::class;
            echo json_encode([
                'aliasDeclared'   => class_exists('Swift\Message', false),
                'instanceof'      => [$message instanceof Swift\Message, $message instanceof Swift\Mime\SimpleMessage, $message instanceof Swift\Mime\CharsetObserver],
                'parameterType'   => $accepts($message),
                'unusedAlias'     => class_exists('Swift\Attachment', false),
                'caseInsensitive' => (new ReflectionClass('SWIFT\EVENTS\EVENTLISTENER'))->getName(),
                'unknownAlias'    => class_exists('Swift\NoSuchClass'),
            ]);
            PHP, \dirname(__DIR__, 3).'/vendor/autoload.php');
        $this->assertSame([
            'aliasDeclared'   => true,
            'instanceof'      => [true, true, true],
            'parameterType'   => 'Swift_Message',
            'unusedAlias'     => false,
            'caseInsensitive' => 'Swift_Events_EventListener',
            'unknownAlias'    => false,
        ], $result);
    }

    public function testEveryMappedAliasResolvesToItsOriginal(): void
    {
        // The v6.5.8 eager list declared these 217 aliases up front; each must still name the same class.
        $result = $this->runIsolated(<<<'PHP'
            <?php
            require $argv[1];
            $broken = [];
            foreach (require $argv[2] as $alias => $original) {
                $exists = static fn (string $name): bool => class_exists($name) || interface_exists($name) || trait_exists($name);
                if (!$exists($alias) || (new ReflectionClass($alias))->getName() !== $original) {
                    $broken[] = $alias;
                }
            }
            echo json_encode(['count' => count(require $argv[2]), 'broken' => $broken]);
            PHP, \dirname(__DIR__, 3).'/vendor/autoload.php', \dirname(__DIR__, 3).'/lib/class_aliases.php');
        $this->assertSame(['count' => 217, 'broken' => []], $result);
    }

    private function runIsolated(string $script, string ...$arguments): array
    {
        $this->directory = \sys_get_temp_dir().'/swiftmailer-lazy-aliases-'.\bin2hex(\random_bytes(8));
        \mkdir($this->directory, 0700, true);
        \file_put_contents($this->directory.'/isolated.php', $script);
        $command = \escapeshellarg(\PHP_BINARY).' '.\escapeshellarg($this->directory.'/isolated.php');
        foreach ($arguments as $argument) {
            $command .= ' '.\escapeshellarg($argument);
        }
        \exec($command.' 2>&1', $output, $exit);
        $this->assertSame(0, $exit, \implode("\n", $output));

        return \json_decode(\implode("\n", $output), true, flags: \JSON_THROW_ON_ERROR);
    }
}
