<?php

use PHPUnit\Framework\TestCase;

class Swift_ClassMapGeneratorTest extends TestCase
{
    private ?string $fixtureRoot = null;

    protected function tearDown(): void
    {
        if (null !== $this->fixtureRoot) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->fixtureRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                $file->isDir() ? \rmdir($file->getPathname()) : \unlink($file->getPathname());
            }
            \rmdir($this->fixtureRoot);
        }
        parent::tearDown();
    }

    public function testEverySourceSymbolHasCommittedMetadataAndIdenticalRuntimeAlias(): void
    {
        $this->loadGenerator();
        $root = \dirname(__DIR__, 3);
        $map  = \generateClassMap($root.'/lib');
        $this->assertSame($map, require $root.'/class_map.php');
        $this->assertSame(\array_combine(\array_map('strtolower', $map), \array_keys($map)), require $root.'/lib/class_aliases.php');
        foreach ($map as $native => $alias) {
            $nativeDefinition = new ReflectionClass($native);
            $aliasDefinition  = new ReflectionClass($alias);
            $this->assertSame($nativeDefinition->getName(), $aliasDefinition->getName(), $alias);
            $this->assertSame($nativeDefinition->isInterface(), $aliasDefinition->isInterface());
            $this->assertSame($nativeDefinition->isTrait(), $aliasDefinition->isTrait());
            $this->assertSame($nativeDefinition->isEnum(), $aliasDefinition->isEnum());
        }
    }

    public function testCommittedOutputsMatchFreshGenerationByteForByte(): void
    {
        $this->loadGenerator();
        $root   = \dirname(__DIR__, 3);
        $output = $this->fixture();
        \writeGeneratedClassMaps($output, $root.'/lib');
        $this->assertSame(\file_get_contents($root.'/class_map.php'), \file_get_contents($output.'/class_map.php'));
        $this->assertSame(\file_get_contents($root.'/lib/class_aliases.php'), \file_get_contents($output.'/lib/class_aliases.php'));
    }

    public function testAliasedEnumCasesPreserveIdentityAndValues(): void
    {
        $this->assertSame(Swift_SendResult::SUCCESS, Swift\SendResult::SUCCESS);
        $this->assertSame(Swift_ReportResult::FAIL, Swift\ReportResult::FAIL);
        $this->assertSame(0x0010, Swift\SendResult::SUCCESS->value);
        $this->assertSame(0x10, Swift\ReportResult::FAIL->value);
    }

    public function testGeneratorHandlesNamedKindsWithoutExecutingSourceAndIsDeterministic(): void
    {
        $this->loadGenerator();
        $root = $this->fixture();
        \mkdir($root.'/lib/nested');
        \file_put_contents($root.'/lib/z.php', '<?php class Swift_Fixture_Z {} interface Swift_Fixture_Contract {} trait Swift_Fixture_Behavior {} enum Swift_Fixture_Choice: int { case OK = 1; } throw new RuntimeException("source must not execute");');
        \file_put_contents($root.'/lib/nested/a.php', '<?php class Swift_Fixture_A {} $unused = new class {}; class Other_Library {} class Swift {}');
        \file_put_contents($root.'/lib/ignored.txt', '<?php class Swift_Fixture_Ignored {}');
        \file_put_contents($root.'/lib/namespaces.php', '<?php namespace External { class Swift_Fixture_Namespaced {} } namespace { class Swift_Fixture_Global {} }');
        $expected = [
            'Swift_Fixture_A'        => 'Swift\\Fixture\\A',
            'Swift_Fixture_Behavior' => 'Swift\\Fixture\\Behavior',
            'Swift_Fixture_Choice'   => 'Swift\\Fixture\\Choice',
            'Swift_Fixture_Contract' => 'Swift\\Fixture\\Contract',
            'Swift_Fixture_Global'   => 'Swift\\Fixture\\Global',
            'Swift_Fixture_Z'        => 'Swift\\Fixture\\Z',
        ];
        $this->assertSame(6, \writeGeneratedClassMaps($root, $root.'/lib'));
        $this->assertSame($expected, require $root.'/class_map.php');
        $this->assertSame([
            'swift\\fixture\\a'        => 'Swift_Fixture_A',
            'swift\\fixture\\behavior' => 'Swift_Fixture_Behavior',
            'swift\\fixture\\choice'   => 'Swift_Fixture_Choice',
            'swift\\fixture\\contract' => 'Swift_Fixture_Contract',
            'swift\\fixture\\global'   => 'Swift_Fixture_Global',
            'swift\\fixture\\z'        => 'Swift_Fixture_Z',
        ], require $root.'/lib/class_aliases.php');
        $map     = \file_get_contents($root.'/class_map.php');
        $aliases = \file_get_contents($root.'/lib/class_aliases.php');
        $this->assertSame(6, \writeGeneratedClassMaps($root, $root.'/lib/nested', $root.'/lib'));
        $this->assertSame($map, \file_get_contents($root.'/class_map.php'));
        $this->assertSame($aliases, \file_get_contents($root.'/lib/class_aliases.php'));
        $this->assertSame($expected, \generateClassMap($root.'/lib', $root.'/lib/nested'));
    }

    public function testEmptySourceTreeProducesValidEmptyOutputs(): void
    {
        $this->loadGenerator();
        $root = $this->fixture();
        $this->assertSame(0, \writeGeneratedClassMaps($root, $root.'/lib'));
        $this->assertSame([], require $root.'/class_map.php');
        $this->assertSame([], require $root.'/lib/class_aliases.php');
        $this->assertStringEndsWith("\n", \file_get_contents($root.'/lib/class_aliases.php'));
    }

    /** @dataProvider duplicateDeclarations */
    public function testDuplicateDeclarationsDoNotOverwriteOutputs(string $duplicate): void
    {
        $this->loadGenerator();
        $root = $this->fixture();
        \file_put_contents($root.'/lib/a.php', '<?php class Swift_Fixture_Duplicate {}');
        \file_put_contents($root.'/lib/b.php', '<?php interface '.$duplicate.' {}');
        $this->assertFailurePreservesOutputs($root, 'Duplicate declaration');
    }

    public static function duplicateDeclarations(): iterable
    {
        yield 'same spelling' => ['Swift_Fixture_Duplicate'];
        yield 'case insensitive PHP identity' => ['Swift_Fixture_duplicate'];
    }

    public function testParseErrorDoesNotOverwriteEitherOutput(): void
    {
        $this->loadGenerator();
        $root = $this->fixture();
        \file_put_contents($root.'/lib/a-valid.php', '<?php class Swift_Fixture_Valid {}');
        \file_put_contents($root.'/lib/z-invalid.php', '<?php class Swift_Fixture_Broken {');
        $this->assertFailurePreservesOutputs($root, 'Parse error in '.$root.'/lib/z-invalid.php');
    }

    public function testCliGeneratesBothFilesAndReportsSyntaxFailureWithoutReplacingThem(): void
    {
        $root = $this->fixture();
        \mkdir($root.'/vendor');
        \file_put_contents($root.'/vendor/autoload.php', '<?php require_once '.\var_export(\dirname(__DIR__, 3).'/vendor/autoload.php', true).';');
        \copy(\dirname(__DIR__, 3).'/generate_class_map.php', $root.'/generate_class_map.php');
        \file_put_contents($root.'/lib/valid.php', '<?php class Swift_Fixture_Cli {}');
        $command = \escapeshellarg(\PHP_BINARY).' '.\escapeshellarg($root.'/generate_class_map.php').' 2>&1';
        \exec($command, $output, $exit);
        $this->assertSame(0, $exit, \implode("\n", $output));
        $this->assertStringContainsString('Generated class map and namespace aliases with 1 entries.', \implode("\n", $output));
        $this->assertSame(['Swift_Fixture_Cli' => 'Swift\\Fixture\\Cli'], require $root.'/class_map.php');
        $this->assertSame(['swift\\fixture\\cli' => 'Swift_Fixture_Cli'], require $root.'/lib/class_aliases.php');
        $map     = \file_get_contents($root.'/class_map.php');
        $aliases = \file_get_contents($root.'/lib/class_aliases.php');
        \file_put_contents($root.'/lib/invalid.php', '<?php class Swift_Fixture_Invalid {');
        $output = [];
        \exec($command, $output, $exit);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Class map generation failed: Parse error', \implode("\n", $output));
        $this->assertSame($map, \file_get_contents($root.'/class_map.php'));
        $this->assertSame($aliases, \file_get_contents($root.'/lib/class_aliases.php'));
    }

    public function testIncludingGeneratorDoesNotWriteOutputsOrPrintStatus(): void
    {
        $root = $this->fixture();
        \mkdir($root.'/vendor');
        \file_put_contents($root.'/vendor/autoload.php', '<?php require_once '.\var_export(\dirname(__DIR__, 3).'/vendor/autoload.php', true).';');
        \copy(\dirname(__DIR__, 3).'/generate_class_map.php', $root.'/generate_class_map.php');
        \file_put_contents($root.'/class_map.php', 'map sentinel');
        \file_put_contents($root.'/lib/class_aliases.php', 'alias sentinel');
        $code = 'require '.\var_export($root.'/generate_class_map.php', true).';';
        \exec(\escapeshellarg(\PHP_BINARY).' -r '.\escapeshellarg($code).' 2>&1', $output, $exit);
        $this->assertSame(0, $exit, \implode("\n", $output));
        $this->assertSame([], $output);
        $this->assertSame('map sentinel', \file_get_contents($root.'/class_map.php'));
        $this->assertSame('alias sentinel', \file_get_contents($root.'/lib/class_aliases.php'));
    }

    public function testGeneratedAliasesWorkForClassesInterfacesTraitsAndEnums(): void
    {
        $this->loadGenerator();
        $root   = $this->fixture();
        $source = $root.'/lib/kinds.php';
        \file_put_contents($source, '<?php class Swift_Fixture_Class {} abstract class Swift_Fixture_Abstract {} interface Swift_Fixture_Interface {} trait Swift_Fixture_Trait {} enum Swift_Fixture_Enum { case VALUE; }');
        $this->assertSame(5, \writeGeneratedClassMaps($root, $root.'/lib'));
        // The fixture originals are required directly, not through Swift::autoload(), so an alias
        // appears only once its own name is autoloaded. The library map registered by Composer keeps working.
        $result = $this->runIsolated($root, <<<'PHP'
            <?php
            [, $autoload, $source, $aliases] = $argv;
            require $autoload;
            require $source;
            Swift::registerAliases(require $aliases);
            $object = new Swift_Fixture_Class();
            $result = ['declaredBeforeUse' => class_exists('Swift\Fixture\Class', false), 'instanceofBeforeUse' => $object instanceof Swift\Fixture\Class];
            $result['traitUse'] = array_values(class_uses(new class { use Swift\Fixture\Trait; }));
            foreach (['Class', 'Abstract', 'Interface', 'Trait', 'Enum'] as $kind) {
                $result['names'][] = (new ReflectionClass('Swift\Fixture\\'.$kind))->getName();
            }
            $result['instanceofAfterUse'] = $object instanceof Swift\Fixture\Class;
            $result['enumIdentity']       = Swift\Fixture\Enum::VALUE === Swift_Fixture_Enum::VALUE;
            $result['libraryAlias']       = (new ReflectionClass('Swift\Message'))->getName();
            echo json_encode($result);
            PHP, \dirname(__DIR__, 3).'/vendor/autoload.php', $source, $root.'/lib/class_aliases.php');
        $this->assertSame([
            'declaredBeforeUse'   => false,
            'instanceofBeforeUse' => false,
            'traitUse'            => ['Swift_Fixture_Trait'],
            'names'               => ['Swift_Fixture_Class', 'Swift_Fixture_Abstract', 'Swift_Fixture_Interface', 'Swift_Fixture_Trait', 'Swift_Fixture_Enum'],
            'instanceofAfterUse'  => true,
            'enumIdentity'        => true,
            'libraryAlias'        => 'Swift_Message',
        ], $result);
    }

    /** @dataProvider bootstrapEntryPoints */
    public function testBootstrapLoadsNoSwiftClassUntilANamespacedNameIsUsed(string $entryPoint): void
    {
        // swift_required.php is a Composer "files" entry, so it runs on every request of the host
        // application. Requests that never send mail must not pay for loading the library.
        $result = $this->runIsolated($this->fixture(), <<<'PHP'
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
        // Eager aliases made this fatal: they loaded classes that extend optional SDK classes.
        yield 'library without Composer' => [\dirname(__DIR__, 3).'/lib/swift_required.php'];
    }

    public function testAliasIsDeclaredWithItsOriginalSoNamespacedTypeChecksAcceptUnderscoreObjects(): void
    {
        // instanceof and parameter types never autoload. Declaring the alias when the original loads
        // keeps them equivalent to the former eager aliases for every object the library can create.
        // Only the existence check of an alias whose original was never used differs (false, was true).
        $result = $this->runIsolated($this->fixture(), <<<'PHP'
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

    public function testWriteFailureThrowsInsteadOfReportingSuccessfulGeneration(): void
    {
        $this->loadGenerator();
        $root = $this->fixture();
        \mkdir($root.'/lib/class_aliases.php');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to write '.$root.'/lib/class_aliases.php');
        \writeGeneratedClassMaps($root, $root.'/lib');
    }

    private function assertFailurePreservesOutputs(string $root, string $expectedMessage): void
    {
        \file_put_contents($root.'/class_map.php', 'map sentinel');
        \file_put_contents($root.'/lib/class_aliases.php', 'alias sentinel');
        try {
            \writeGeneratedClassMaps($root, $root.'/lib');
            $this->fail('Invalid source must fail before either output is replaced.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString($expectedMessage, $error->getMessage());
        }
        $this->assertSame('map sentinel', \file_get_contents($root.'/class_map.php'));
        $this->assertSame('alias sentinel', \file_get_contents($root.'/lib/class_aliases.php'));
    }

    /** Run a script in a fresh PHP process, where no Swift class is loaded yet; it must print one JSON document. */
    private function runIsolated(string $directory, string $script, string ...$arguments): array
    {
        \file_put_contents($directory.'/isolated.php', $script);
        $command = \escapeshellarg(\PHP_BINARY).' '.\escapeshellarg($directory.'/isolated.php');
        foreach ($arguments as $argument) {
            $command .= ' '.\escapeshellarg($argument);
        }
        \exec($command.' 2>&1', $output, $exit);
        $this->assertSame(0, $exit, \implode("\n", $output));

        return \json_decode(\implode("\n", $output), true, flags: \JSON_THROW_ON_ERROR);
    }

    private function loadGenerator(): void
    {
        require_once \dirname(__DIR__, 3).'/generate_class_map.php';
    }

    private function fixture(): string
    {
        $this->fixtureRoot = \sys_get_temp_dir().'/swiftmailer-class-map-'.\bin2hex(\random_bytes(8));
        \mkdir($this->fixtureRoot.'/lib', 0700, true);

        return $this->fixtureRoot;
    }

    /** @dataProvider missingAliases */
    public function testPreviouslyMissingAliasMatchesNativeDefinition(string $native, string $alias): void
    {
        $nativeReflection = new ReflectionClass($native);
        $this->assertTrue(\class_exists($alias) || \interface_exists($alias), 'Missing namespace alias '.$alias);
        $this->assertSame($nativeReflection->getName(), (new ReflectionClass($alias))->getName());
        $this->assertSame($nativeReflection->isInterface(), (new ReflectionClass($alias))->isInterface());
    }

    public static function missingAliases(): iterable
    {
        foreach ([
            'Swift_SendResult',
            'Swift_ReportResult',
            'Swift_MessageLimits',
            'Swift_Plugins_ReadReceiptPlugin',
            'Swift_Transport_Api_Calendar_IcsParser',
            'Swift_Transport_Api_Calendar_ParsedEvent',
            'Swift_Transport_UrlValidator',
            'Swift_Webhook_TimestampExtractorInterface',
        ] as $native) {
            yield $native => [$native, \str_replace('_', '\\', $native)];
        }
    }
}
