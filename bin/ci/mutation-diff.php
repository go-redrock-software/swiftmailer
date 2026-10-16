<?php

/*
 * Copyright (c) 2026. Redrock Software Corporation
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

// Stdout is exclusively the comma-separated Infection filter. Diagnostics go to stderr.
$git = static function (array $arguments): string {
    $process = \proc_open(\array_merge(['git'], $arguments), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => STDERR], $pipes);
    if (!\is_resource($process)) {
        throw new RuntimeException('Mutation diff: cannot start Git.');
    }
    \fclose($pipes[0]);
    $output = \stream_get_contents($pipes[1]);
    \fclose($pipes[1]);
    $status = \proc_close($process);
    if (0 !== $status) {
        throw new RuntimeException(\sprintf('Mutation diff: git %s failed (exit %d).', $arguments[0], $status));
    }

    return $output;
};

try {
    if (2 !== \count($argv) || '' === $argv[1]) {
        throw new InvalidArgumentException('Usage: php bin/ci/mutation-diff.php <target-branch>');
    }
    $project       = \getenv('CI_PROJECT_ID');
    $targetProject = \getenv('CI_MERGE_REQUEST_PROJECT_ID');
    if ((false !== $project || false !== $targetProject) && (!$project || !$targetProject || $project !== $targetProject)) {
        throw new RuntimeException('Mutation diff: a cross-project MR requires a target-project checkout; origin must contain the actual target branch.');
    }
    $target = $argv[1];
    $git(['check-ref-format', 'refs/heads/'.$target]);
    $root = \rtrim($git(['rev-parse', '--show-toplevel']), "\r\n");
    if (!\chdir($root)) {
        throw new RuntimeException('Mutation diff: cannot enter the checkout root.');
    }

    $source      = \trim($git(['rev-parse', '--verify', 'HEAD^{commit}']));
    $trackingRef = 'refs/remotes/origin/'.$target;
    $fetch       = ['fetch', '--quiet', '--no-tags'];
    $shallow     = 'true' === \trim($git(['rev-parse', '--is-shallow-repository']));
    if ($shallow) {
        $fetch[] = '--unshallow';
    }
    $fetch[] = 'origin';
    $fetch[] = '+refs/heads/'.$target.':'.$trackingRef;
    if ($shallow) {
        // A target-only fetch may leave the detached source ancestry truncated.
        $fetch[] = $source;
    }
    $git($fetch);

    $targetCommit = \trim($git(['rev-parse', '--verify', $trackingRef.'^{commit}']));
    $output       = $git(['diff', '--name-only', '-z', $targetCommit.'...'.$source, '--', 'lib/classes/**.php']);
    $paths        = \array_values(\array_filter(\explode("\0", $output), static fn (string $path): bool => '' !== $path));
    foreach ($paths as $path) {
        if (\str_contains($path, ',') || false !== \strpbrk($path, "\r\n")) {
            throw new RuntimeException('Mutation diff: a source filename cannot be represented in the comma-separated Infection filter.');
        }
    }
    \fwrite(STDERR, \sprintf("Mutation diff: target %s; %d PHP source file(s).\n", $targetCommit, \count($paths)));
    if ($paths) {
        \fwrite(STDOUT, \implode(',', $paths)."\n");
    }
} catch (Throwable $error) {
    \fwrite(STDERR, $error->getMessage()."\n");
    exit(1);
}
