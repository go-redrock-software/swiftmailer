<?php

class Swift_Ci_MutationDiffTest extends PHPUnit\Framework\TestCase
{
    private static string $root;

    private static array $environment;

    private static int $clones = 0;

    public static function setUpBeforeClass(): void
    {
        self::$root = \sys_get_temp_dir().'/swiftmailer-mutation-diff-'.\bin2hex(\random_bytes(6));
        \mkdir(self::$root);
        \file_put_contents(self::$root.'/gitconfig', '');
        self::$environment = \array_merge(\getenv(), [
            'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_CONFIG_GLOBAL'   => self::$root.'/gitconfig',
            'LC_ALL'              => 'C',
        ]);
        foreach (['GIT_DIR', 'GIT_WORK_TREE', 'GIT_INDEX_FILE', 'GIT_CONFIG_PARAMETERS', 'GIT_CONFIG_COUNT', 'CI_PROJECT_ID', 'CI_MERGE_REQUEST_PROJECT_ID'] as $name) {
            unset(self::$environment[$name]);
        }
        $seed = self::$root.'/seed';
        \mkdir($seed);
        self::git($seed, ['init', '--quiet', '--initial-branch=main']);
        foreach (['user.name' => 'Swift fixture', 'user.email' => 'fixture@example.invalid', 'commit.gpgsign' => 'false', 'core.autocrlf' => 'false', 'core.hooksPath' => self::$root.'/no-hooks'] as $key => $value) {
            self::git($seed, ['config', $key, $value]);
        }
        \mkdir($seed.'/lib/classes/Nested', 0777, true);
        \mkdir($seed.'/docs');
        \file_put_contents($seed.'/lib/classes/Base.php', "<?php // base\n");
        \file_put_contents($seed.'/lib/classes/Nested/Child.php', "<?php // child\n");
        \file_put_contents($seed.'/docs/readme.md', "Base\n");
        self::git($seed, ['add', '.']);
        self::git($seed, ['commit', '--quiet', '-m', 'Common base']);
        self::git($seed, ['branch', 'target/stack']);
        self::git($seed, ['checkout', '--quiet', '-b', 'source/ci']);
        \file_put_contents($seed.'/lib/classes/Base.php', "<?php // changed\n");
        \file_put_contents($seed.'/lib/classes/Nested/Child.php', "<?php // nested change\n");
        \file_put_contents($seed.'/docs/readme.md', "Source documentation\n");
        self::git($seed, ['add', '.']);
        self::git($seed, ['commit', '--quiet', '-m', 'Actual source changes']);
        for ($i = 0; $i < 25; ++$i) {
            self::git($seed, ['commit', '--quiet', '--allow-empty', '-m', 'Source history '.$i]);
        }
        self::git($seed, ['checkout', '--quiet', 'target/stack']);
        for ($i = 0; $i < 25; ++$i) {
            self::git($seed, ['commit', '--quiet', '--allow-empty', '-m', 'Target history '.$i]);
        }
        self::git($seed, ['branch', 'target/ci;literal']);
        self::git($seed, ['checkout', '--quiet', '-b', 'source/docs']);
        \file_put_contents($seed.'/docs/readme.md', "Documentation only\n");
        self::git($seed, ['add', '.']);
        self::git($seed, ['commit', '--quiet', '-m', 'Documentation only']);
        foreach (['comma' => 'Comma,Name.php', 'newline' => "Line\nBreak.php", 'names' => 'With space.php'] as $branch => $name) {
            self::git($seed, ['checkout', '--quiet', '-b', 'source/'.$branch, 'source/ci']);
            \file_put_contents($seed.'/lib/classes/'.$name, "<?php\n");
            if ('names' === $branch) {
                \file_put_contents($seed.'/lib/classes/Unicode-ä.php', "<?php\n");
            }
            self::git($seed, ['add', '.']);
            self::git($seed, ['commit', '--quiet', '-m', 'Filename fixture']);
        }
        self::git($seed, ['checkout', '--quiet', '--orphan', 'target/unrelated']);
        self::git($seed, ['rm', '--quiet', '-rf', '.']);
        \file_put_contents($seed.'/unrelated.txt', "Unrelated\n");
        self::git($seed, ['add', '.']);
        self::git($seed, ['commit', '--quiet', '-m', 'Unrelated root']);
        self::git(self::$root, ['init', '--quiet', '--bare', 'remote.git']);
        self::git($seed, ['push', '--quiet', '--all', self::$root.'/remote.git']);
    }

    public static function tearDownAfterClass(): void
    {
        if (isset(self::$root) && \is_dir(self::$root)) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::$root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                if ($file->isDir() && !$file->isLink()) {
                    \rmdir($file->getPathname());
                } else {
                    if (!$file->isLink()) {
                        \chmod($file->getPathname(), 0666);
                    }
                    \unlink($file->getPathname());
                }
            }
            \rmdir(self::$root);
        }
    }

    public function testShallowDetachedCheckoutFetchesMissingTargetAndDeepMergeBase(): void
    {
        $clone = $this->checkout();
        $this->assertSame("true\n", self::git($clone, ['rev-parse', '--is-shallow-repository']));
        $old = self::process($clone, ['git', 'diff', '--name-only', 'origin/target/stack...HEAD', '--', 'lib/classes/**.php']);
        $this->assertNotSame(0, $old['code'], 'The old job must reproduce the missing-ref failure');
        $result = $this->helper($clone, 'target/stack');
        $this->assertSame(0, $result['code'], $result['stderr']);
        $this->assertSame("lib/classes/Base.php,lib/classes/Nested/Child.php\n", $result['stdout']);
        $this->assertSame("false\n", self::git($clone, ['rev-parse', '--is-shallow-repository']));
        $this->assertGreaterThan(20, (int) self::git($clone, ['rev-list', '--count', 'HEAD']));
    }

    public function testFullCheckoutRefreshesAStaleTargetTrackingRef(): void
    {
        $clone = $this->checkout('source/ci', false);
        self::git($clone, ['update-ref', 'refs/remotes/origin/target/stack', 'HEAD']);
        $result = $this->helper($clone, 'target/stack');
        $this->assertSame(0, $result['code'], $result['stderr']);
        $this->assertSame("lib/classes/Base.php,lib/classes/Nested/Child.php\n", $result['stdout']);
        $this->assertSame(self::git(self::$root.'/seed', ['rev-parse', 'target/stack']), self::git($clone, ['rev-parse', 'refs/remotes/origin/target/stack']));
    }

    public function testDocumentationOnlyDiffIsAValidEmptyFilter(): void
    {
        $result = $this->helper($this->checkout('source/docs'), 'target/stack');
        $this->assertSame(0, $result['code'], $result['stderr']);
        $this->assertSame('', $result['stdout']);
    }

    public function testSubdirectoryInvocationKeepsTheRepositorySourceScope(): void
    {
        $result = $this->helper($this->checkout().'/lib', 'target/stack');
        $this->assertSame(0, $result['code'], $result['stderr']);
        $this->assertSame("lib/classes/Base.php,lib/classes/Nested/Child.php\n", $result['stdout']);
    }

    /** @dataProvider unavailableTargets */
    public function testUnavailableTargetOrMergeBaseFailsWithoutAFilter(string $target): void
    {
        $result = $this->helper($this->checkout(), $target);
        $this->assertNotSame(0, $result['code']);
        $this->assertSame('', $result['stdout']);
        $this->assertNotSame('', $result['stderr']);
    }

    public static function unavailableTargets(): iterable
    {
        yield 'missing target' => ['target/missing'];
        yield 'invalid ref' => ['target:other'];
        yield 'empty target' => [''];
        yield 'unrelated histories' => ['target/unrelated'];
    }

    public function testValidRefWithShellMetacharactersIsFetchedLiterally(): void
    {
        $result = $this->helper($this->checkout(), 'target/ci;literal');
        $this->assertSame(0, $result['code'], $result['stderr']);
        $this->assertSame("lib/classes/Base.php,lib/classes/Nested/Child.php\n", $result['stdout']);
    }

    /** @dataProvider invalidProjectContexts */
    public function testCrossProjectPipelineCannotSilentlyUseTheForksTargetBranch(array $environment): void
    {
        $result = $this->helper($this->checkout(), 'target/stack', $environment);
        $this->assertNotSame(0, $result['code']);
        $this->assertSame('', $result['stdout']);
        $this->assertStringContainsString('target-project checkout', $result['stderr']);
    }

    public static function invalidProjectContexts(): iterable
    {
        yield 'fork pipeline' => [['CI_PROJECT_ID' => '1', 'CI_MERGE_REQUEST_PROJECT_ID' => '2']];
        yield 'unknown MR project' => [['CI_PROJECT_ID' => '85', 'CI_MERGE_REQUEST_PROJECT_ID' => '']];
        yield 'unknown checkout project' => [['CI_PROJECT_ID' => '', 'CI_MERGE_REQUEST_PROJECT_ID' => '85']];
    }

    public function testSameProjectMetadataAllowsTheActualTargetFetch(): void
    {
        $result = $this->helper($this->checkout(), 'target/stack', ['CI_PROJECT_ID' => '85', 'CI_MERGE_REQUEST_PROJECT_ID' => '85']);
        $this->assertSame(0, $result['code'], $result['stderr']);
        $this->assertSame("lib/classes/Base.php,lib/classes/Nested/Child.php\n", $result['stdout']);
    }

    public function testSpacesAndUnicodeNamesRemainUnquoted(): void
    {
        $result = $this->helper($this->checkout('source/names'), 'target/stack');
        $this->assertSame(0, $result['code'], $result['stderr']);
        $this->assertSame("lib/classes/Base.php,lib/classes/Nested/Child.php,lib/classes/Unicode-ä.php,lib/classes/With space.php\n", $result['stdout']);
    }

    /** @dataProvider unrepresentableNames */
    public function testUnrepresentableNamesFailInsteadOfTestingOnlyPartOfTheDiff(string $branch): void
    {
        $result = $this->helper($this->checkout($branch), 'target/stack');
        $this->assertNotSame(0, $result['code']);
        $this->assertSame('', $result['stdout']);
        $this->assertNotSame('', $result['stderr']);
    }

    public static function unrepresentableNames(): iterable
    {
        yield 'comma separates Infection filters' => ['source/comma'];
        yield 'newline corrupts command output' => ['source/newline'];
    }

    private function checkout(string $branch = 'source/ci', bool $shallow = true): string
    {
        $destination = self::$root.'/checkout-'.++self::$clones;
        $arguments   = ['clone', '--quiet', '--no-tags', '--single-branch', '--branch', $branch];
        if ($shallow) {
            $arguments[] = '--depth=2';
        }
        $remote = \str_replace('\\', '/', self::$root.'/remote.git');
        $url    = 'file://'.(\str_starts_with($remote, '/') ? '' : '/').$remote;
        self::git(self::$root, \array_merge($arguments, [$url, $destination]));
        self::git($destination, ['checkout', '--quiet', '--detach']);

        return $destination;
    }

    private function helper(string $directory, string $target, array $environment = []): array
    {
        return self::process($directory, [PHP_BINARY, \dirname(__DIR__, 4).'/bin/ci/mutation-diff.php', $target], $environment);
    }

    private static function git(string $directory, array $arguments): string
    {
        $result = self::process($directory, \array_merge(['git'], $arguments));
        if (0 !== $result['code']) {
            throw new RuntimeException('Fixture Git failed: '.$result['stderr']);
        }

        return $result['stdout'];
    }

    private static function process(string $directory, array $arguments, array $environment = []): array
    {
        $process = \proc_open($arguments, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $directory, \array_merge(self::$environment, $environment));
        if (!\is_resource($process)) {
            throw new RuntimeException('Cannot start fixture process');
        }
        \fclose($pipes[0]);
        $stdout = \stream_get_contents($pipes[1]);
        $stderr = \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);

        return ['code' => \proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }
}
