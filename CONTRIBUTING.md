# Contributing

Thanks for helping maintain SwiftMailer. This fork is kept alive by
[Redrock Software Corporation](https://www.go-redrock.com/) for legacy and enterprise
applications that cannot migrate to Symfony Mailer. This guide covers the practical
workflow: setup, tests, style, static analysis, mutation testing, and CI.

The package is published on Packagist as **`go-redrock/swiftmailer`**. The primary
development remote is the self-hosted GitLab at `code.go-redrock.com`; the GitHub
repository (`go-redrock-software/swiftmailer`) is a mirror. Both run CI — see
[Continuous integration](#continuous-integration).

For how the codebase fits together, read [doc/architecture.md](doc/architecture.md)
first.

## Requirements

| Requirement | Version / notes |
|-|-|
| PHP | 8.3+ (the suite's CI matrix is 8.3, 8.4, 8.5) |
| Extensions | `iconv`, `mbstring`, `intl`, `openssl` (add `dom`, `curl`, `libxml`, `zip` to run the full test matrix locally) |
| Composer | v2 |

The `composer.json` `config.platform.php` is pinned to `8.3.0`, so dependency
resolution and PHPStan behave identically regardless of the PHP you run locally.

## Setup

```bash
composer install
```

That installs runtime and dev dependencies (PHPUnit + Symfony bridge, Mockery,
PHP-CS-Fixer, PHPStan, Rector, Infection). Applications consuming the library instead
install it from Packagist:

```bash
composer require go-redrock/swiftmailer
```

## Running tests

| Action | Command |
|-|-|
| Full suite | `composer test` |
| Single file | `vendor/bin/simple-phpunit tests/unit/Swift/SomeTest.php` |
| One suite | `vendor/bin/simple-phpunit --testsuite="SwiftMailer unit tests"` |

Suites (defined in `phpunit.xml.dist`): `SwiftMailer unit tests` (`tests/unit`),
`SwiftMailer acceptance tests` (`tests/acceptance`), `SwiftMailer bug` (`tests/bug`),
`SwiftMailer smoke tests` (`tests/smoke`). Acceptance and smoke suites are gated on
`tests/acceptance.conf.php` / `tests/smoke.conf.php` and are skipped without them.

### Always use `composer test`

The `composer test` script exports the Symfony deprecation baseline before invoking
`simple-phpunit`:

```
SYMFONY_DEPRECATIONS_HELPER='max[direct]=0&baselineFile=./tests/deprecation-baseline.json'
```

The suite intentionally emits some known deprecations (from dependencies and PHP 8.4+
notices). The baseline file lists exactly those, so the run exits `0`. This variable
**must** be in the environment before PHPUnit starts — the bridge caches its config at
process start, before `phpunit.xml.dist`'s `<env>` is applied — so a bare
`vendor/bin/simple-phpunit` run gets `max[direct]=0` **without** the baseline and exits
non-zero on those known deprecations. When you legitimately add or remove a deprecation,
regenerate the baseline with the bridge's `generateBaseline` option pointed at the same
file, and commit the diff.

## Code style

```bash
composer php-cs-fixer      # apply fixes
```

The ruleset is `.php-cs-fixer.dist.php` — `@Symfony` plus PHPUnit-100 migration, short
arrays, alphabetical imports, aligned `=>` operators, native function invocation, and
trailing commas in multiline calls. CI runs it in dry-run mode
(`php-cs-fixer fix --dry-run --diff`), so run it before pushing.

## Static analysis

```bash
composer phpstan
```

PHPStan runs at **level 5** over `lib/classes`, configured by `phpstan.neon.dist`
(pinned to `phpVersion: 80300`). Known/accepted issues live in `phpstan-baseline.neon`,
which the config includes. If a legitimate change alters the baseline, regenerate it:

```bash
vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --generate-baseline phpstan-baseline.neon
```

Aim to **reduce** the baseline over time; don't grow it to hide a real regression.

## Mutation testing

Infection checks that tests actually assert behavior, not just execute it.

| Action | Command |
|-|-|
| Full run | `composer infection` |
| Changed lines only (vs `master`) | `composer infection:diff` |

Config is `infection.json5`: it mutates `lib/classes` (excluding
`DependencyContainer.php` and `Preferences.php`), runs the unit suite, and enforces
`minMsi: 78` / `minCoveredMsi: 95`. Both `composer` scripts first generate coverage
(`infection:coverage`, which needs Xdebug in coverage mode).

Coverage generation and Infection's mutant runner both use the isolated
`vendor/bin/simple-phpunit` executable. Keep these in sync. The project-installed
`vendor/bin/phpunit` loads the project's Composer files before PHPUnit executes
its configured bootstrap. Swift's eager namespace aliases then load source
classes before Infection can install its include interceptor, causing tests to
exercise the original classes and report escaping mutants. The isolated runner
loads the project through the test bootstrap after interception is active.

The merge request job obtains its file filter with
`php bin/ci/mutation-diff.php "$CI_MERGE_REQUEST_TARGET_BRANCH_NAME"`. It fetches
the exact target branch into an explicit tracking ref before comparing immutable
commit IDs. `GIT_DEPTH: "0"` requests full history; the helper also recovers both
source and target ancestry when invoked in a shallow checkout. Its stderr
diagnostic records the fetched target commit and source-file count.

Both mutation CI jobs and the Composer mutation scripts export the normal test
deprecation policy to Infection and its child processes, including the PHPUnit
version probe. Coverage generation keeps its existing command-local override.
Neither the baseline nor mutation thresholds change.

Only a successful comparison with no changed PHP source files skips mutation
testing. A missing target, unavailable merge base or failed fetch fails the job.
Git arguments are passed without a shell and filenames are read with NUL
separators. Spaces and Unicode are supported; commas and line breaks fail
explicitly because Infection's comma-separated filter cannot represent them.
The helper requires a target-project checkout: fork-project pipelines must run
in the parent project rather than compare against a same-named branch in the
fork. See Git's [fetch refspecs](https://git-scm.com/docs/git-fetch) and GitLab's
[merge request variables](https://docs.gitlab.com/ci/variables/predefined_variables/).

## Adding a class → regenerate the class map

Because classes are PSR-0 underscore-named, adding one may require regenerating the
namespace map used by the forward-compat aliases and the Rector migration tooling:

```bash
php generate_class_map.php
```

This parses `lib/` and rewrites both `class_map.php` (underscore → namespace)
and `lib/class_aliases.php` (runtime `Swift\...` aliases) from one sorted map.
Commit both generated files alongside your source change. The command includes
named global `Swift_` classes, interfaces, traits and enums; it ignores anonymous
classes and already namespaced declarations. Re-running it leaves the outputs
unchanged. Invalid syntax or conflicting declarations stop generation before
either output is written; read/write failures produce a nonzero exit status.
No source file is executed during parsing.

The unit suite checks source/map/runtime alias agreement and exercises generation
against temporary fixture trees. PHPStan also analyzes the generator.

## Commit messages

Use Conventional Commits — `type(scope): description` — matching the existing history:

```
fix(phpstan): pin phpVersion to 8.3 and regenerate baseline
test: drop no-op ReflectionMethod::setAccessible() calls
ci(tests): add php 8.5 to the matrix
```

Common types: `feat`, `fix`, `test`, `ci`, `docs`, `refactor`, `chore`. Keep commits
focused — one logical change each; don't batch unrelated work.

## Pull requests

Open PRs against `master`. Fill in `.github/PULL_REQUEST_TEMPLATE.md` — the bug-fix /
new-feature / doc-update / BC-break / deprecation grid, any fixed ticket numbers, and
the MIT license line. Before requesting review, make sure the checks below pass
locally:

```bash
composer test
composer phpstan
composer php-cs-fixer   # then review/commit any changes
```

Add or update tests with every behavioral change — a regression test for every bug fix
(a good home is `tests/bug/`), unit coverage for every new feature.

## Continuous integration

CI runs in two places. `.gitlab-ci.yml` (GitLab, the primary remote) is the canonical
pipeline; `.github/workflows/ci.yml` runs an equivalent set of checks on the GitHub
mirror. Both trigger on pushes to `master`/`main` and on merge/pull requests.

### GitLab — `.gitlab-ci.yml` (primary)

Four stages on a `php:8.5-cli` base image, on runners tagged `t5-aws-docker`.
`COMPOSER_ROOT_VERSION` is pinned to **`6.5.x-dev`**: CI checks out a shallow, detached
HEAD with no tags, so without the pin Composer can't infer the root version and
defaults it to `1.0.0`, which trips roave/security-advisories' `swiftmailer/swiftmailer
<6.2.5` conflict rule and breaks resolution. Bump the minor line when the version moves.

| Stage | Jobs |
|-|-|
| quality | `qodana` — JetBrains Qodana scan (`develop` branch only) |
| test | `validate` (`composer validate --strict`), `security-audit` (`composer audit`), `code-style` (PHP-CS-Fixer dry-run), `phpstan` (level 5, 1G), `tests` (PHP **8.3 + 8.5** matrix, JUnit artifact) |
| integration | `smoke-tests` — runs `tests/smoke/Swift/Smoke/MailpitSmokeTest.php` against an `axllent/mailpit` service; `allow_failure` |
| mutation | `mutation-testing` (full Infection; scheduled + `master`/`main`) and `mutation-testing-mr` (Infection on changed `lib/classes` files only, on MRs); both `allow_failure` |

The `tests` jobs set `SYMFONY_DEPRECATIONS_HELPER=max[direct]=0&max[self]=0` directly,
rather than the baseline-file form the local `composer test` uses.
The Mailpit smoke job exports the same baseline-file policy as `composer test`
before starting PHPUnit, so known dependency deprecations do not turn passing
delivery assertions into a failed job. New direct deprecations still fail.

### GitHub Actions — `.github/workflows/ci.yml` (mirror)

| Job | What it does |
|-|-|
| Composer Validate | `composer validate --strict --no-check-publish` |
| Security Audit | `composer audit` |
| Code Style | PHP-CS-Fixer dry-run against `.php-cs-fixer.dist.php` |
| PHPStan | `phpstan analyse` (level 5, memory 1G) |
| Tests | Unit suite via `composer test` across the PHP **8.3 + 8.4 + 8.5** matrix |

The matrices differ deliberately — GitLab runs 8.3 + 8.5, GitHub adds 8.4. The GitHub
test job goes through `composer test` so the deprecation baseline file is applied (a
bare `simple-phpunit` run would fail on the known deprecations).

### Running the GitLab pipeline locally

You can execute the GitLab jobs on your own machine with
[`gitlab-ci-local`](https://github.com/firecow/gitlab-ci-local) (Docker-backed). It
reads `.gitlab-ci.yml` and keeps its state — the flattened `expanded-gitlab-ci.yml`,
builds, cache, and artifacts — under the git-ignored `.gitlab-ci-local/` directory.
Useful for reproducing a CI failure without pushing.

### HTML reporter streaming tests

The unit suite exercises real CLI and PHP-FPM workers over private Unix sockets.
A separate ready/release barrier holds the worker after `notify()`, so assertions
inspect client bytes before completion rather than relying on sleeps. Both
captured and streamed output must retain the same escaped HTML. The worker loads
the exact selected reporter file before Composer aliases. Under Infection 0.29,
its checked active include-replacement mapping supplies the mutant path because
Reflection retains the original filename. Each runtime case also invokes the
reporter in the parent to compare captured HTML with client output and provide
actual source-line coverage.

Install a matching PHP-FPM binary alongside PHP CLI, or set
`SWIFTMAILER_PHP_FPM` to its absolute executable path. CI uses the official PHP FPM
images and sets `SWIFTMAILER_REQUIRE_FPM=1`, making a missing runtime a failure.
Local CLI-only installations explicitly skip FPM cases; those skips do not prove
FastCGI streaming. Windows/non-Unix environments cannot run the private socket
matrix. Child processes receive only the controlled test configuration and
`XDEBUG_MODE=off`, and are reaped on success, failure and bounded timeouts.

The reporter flushes backend buffers, not PHP user buffers or proxy/browser
buffers. Buffered controls document that limit. Do not suppress the two flush
mutations or reduce the repository's MSI thresholds to make the audit pass.
