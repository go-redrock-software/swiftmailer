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
        foreach ($map as $native => $alias) {
            $nativeDefinition = new ReflectionClass($native);
            $aliasDefinition  = new ReflectionClass($alias);
            $this->assertSame($nativeDefinition->getName(), $aliasDefinition->getName(), $alias);
            $this->assertSame($nativeDefinition->isInterface(), $aliasDefinition->isInterface());
            $this->assertSame($nativeDefinition->isTrait(), $aliasDefinition->isTrait());
            $this->assertSame($nativeDefinition->isEnum(), $aliasDefinition->isEnum());
        }
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
        $map     = \file_get_contents($root.'/class_map.php');
        $aliases = \file_get_contents($root.'/lib/class_aliases.php');
        foreach ($expected as $native => $alias) {
            $this->assertStringContainsString("\\class_alias('".$native."', '".$alias."');", $aliases);
        }
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
        $this->assertStringNotContainsString('\\class_alias(', \file_get_contents($root.'/lib/class_aliases.php'));
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
        $this->assertStringContainsString("\\class_alias('Swift_Fixture_Cli', 'Swift\\Fixture\\Cli');", \file_get_contents($root.'/lib/class_aliases.php'));
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
        \file_put_contents($source, '<?php class Swift_Fixture_Class {} interface Swift_Fixture_Interface {} trait Swift_Fixture_Trait {} enum Swift_Fixture_Enum { case VALUE; }');
        $this->assertSame(4, \writeGeneratedClassMaps($root, $root.'/lib'));
        $code = 'require '.\var_export($source, true).'; require '.\var_export($root.'/lib/class_aliases.php', true).';'
            .'foreach (["Class", "Interface", "Trait", "Enum"] as $kind) { echo (new ReflectionClass("Swift\\\\Fixture\\\\".$kind))->getName(), "\\n"; }';
        \exec(\escapeshellarg(\PHP_BINARY).' -r '.\escapeshellarg($code).' 2>&1', $output, $exit);
        $this->assertSame(0, $exit, \implode("\n", $output));
        $this->assertSame(['Swift_Fixture_Class', 'Swift_Fixture_Interface', 'Swift_Fixture_Trait', 'Swift_Fixture_Enum'], $output);
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
