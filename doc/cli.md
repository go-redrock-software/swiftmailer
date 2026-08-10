# CLI: swiftmailer-test

`bin/swiftmailer-test` sends a single test email through any SwiftMailer-supported transport, built from a [DSN string](dsn.md). It is the quickest way to verify that a transport, its credentials, and network reachability are correct before wiring it into an application.

## Usage

```bash
php bin/swiftmailer-test <dsn> --to=<recipient> [options]
```

- `<dsn>` is a positional argument and is **always required** by the argument parser, even when you also set the `SWIFTMAILER_DSN` environment variable (see [DSN resolution](#dsn-resolution)).
- `--to` is the only required option.

Run `php bin/swiftmailer-test --help` (or `-h`) to print usage and exit `0`.

## Flags

All options use the `--key=value` form. A `--flag` without `=` is rejected (`Option "--flag" requires a value.`). Options other than those below are ignored.

| Flag | Required | Default | Description |
|-|-|-|-|
| `<dsn>` (positional) | Yes | — | Transport DSN, e.g. `smtp://user:pass@host:587`. Overridden by `SWIFTMAILER_DSN` when that is set. |
| `--to=<address>` | Yes | — | Recipient email address. |
| `--from=<address>` | No | `swiftmailer-test@localhost` | Sender address. |
| `--subject=<text>` | No | `SwiftMailer Test Email` | Message subject. |
| `--body=<text>` | No | `This is a test email sent by the SwiftMailer CLI test tool.` | Plain-text body. |
| `--help`, `-h` | No | — | Print usage and exit `0`. |

## DSN resolution

The DSN is resolved as:

```
SWIFTMAILER_DSN (environment)  →  falls back to  →  <dsn> positional argument
```

The `SWIFTMAILER_DSN` environment variable is **preferred and takes precedence** over the positional argument. Passing the DSN as a CLI argument exposes credentials in the host's process list (e.g. `ps aux`), so when the environment variable is **not** set and a DSN argument is used, the tool prints a warning:

```
Passing DSN as a CLI argument exposes credentials in the process list. Use SWIFTMAILER_DSN env var instead.
```

Because the positional `<dsn>` is still required by the parser, the safe pattern is to set `SWIFTMAILER_DSN` and pass a non-sensitive placeholder positionally (the placeholder is ignored):

```bash
export SWIFTMAILER_DSN='sendgrid://SG.your-key@default'
php bin/swiftmailer-test env --to=test@example.com
```

## Credential masking in output

Before doing anything, the tool echoes the DSN, from, to, and subject. The DSN is masked by replacing the password component of a `user:password@` pair with `****`:

```
DSN:       smtp://user:****@smtp.example.com
```

> Caveat: masking only covers the `user:password@` form. API DSNs where the key is the user with no password — e.g. `sendgrid://SG.xxxx@default` — are **not** masked, and the key is printed in full. Prefer redirecting or avoiding stdout capture when testing API transports with real keys. Console output additionally strips control/escape characters to prevent terminal-injection via crafted DSNs.

## Behaviour

The tool runs these steps, printing progress (info in cyan, success in green; warnings and errors go to stderr):

1. Parse arguments.
2. Resolve the DSN and print the masked summary.
3. Build the transport via `Swift_Transport_DsnTransportFactory` and print its class.
4. `start()` the transport (opens the connection / validates the API client).
5. Build a `Swift_Message` and send it via `Swift_Mailer`.
6. `stop()` the transport (always attempted, even on failure).

## Exit codes

| Code | Meaning |
|-|-|
| `0` | Email accepted for delivery (≥ 1 recipient), or `--help` shown. |
| `1` | Composer autoloader missing; invalid/missing arguments; no DSN resolved; transport creation failed; connection/`start()` failed; send threw; or the message was not accepted (0 recipients). |

Colour output honours the [`NO_COLOR`](https://no-color.org/) convention and is disabled when stdout is not a TTY.

## Examples

### SMTP

```bash
php bin/swiftmailer-test "smtp://user:pass@smtp.example.com:587" --to=test@example.com

# forced TLS, custom sender and subject
php bin/swiftmailer-test "smtp+tls://user:pass@smtp.example.com:587" \
  --to=test@example.com --from=noreply@myapp.com --subject="Prod SMTP check"
```

### API transport

```bash
# preferred: DSN via environment (keeps the key off the process list)
export SWIFTMAILER_DSN='sendgrid://SG.your-key@default'
php bin/swiftmailer-test env --to=test@example.com --from=noreply@myapp.com
```

### Provider SMTP convenience scheme

```bash
export SWIFTMAILER_DSN='sendgrid+smtp://apikey:apikey@default'
php bin/swiftmailer-test env --to=test@example.com
```

### Wrapper DSNs (failover / retry)

```bash
export SWIFTMAILER_DSN='failover(sendgrid://SG.key@default postmark://pmk-key@default)'
php bin/swiftmailer-test env --to=test@example.com

export SWIFTMAILER_DSN='retry(smtp+tls://user:pass@smtp.example.com:587)'
php bin/swiftmailer-test env --to=test@example.com
```

### No-op sink (no mail sent)

```bash
php bin/swiftmailer-test "null://default" --to=test@example.com
```

See [dsn.md](dsn.md) for the full DSN grammar, every supported scheme, query parameters, and wrapper semantics.
