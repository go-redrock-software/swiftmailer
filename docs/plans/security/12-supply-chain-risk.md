# Threat 12: Supply Chain and Dependency Risk

**STRIDE Category:** Tampering, Elevation of Privilege
**Severity:** MEDIUM
**Likelihood:** Low
**CWE:** CWE-1357 (Reliance on Insufficiently Trustworthy Component)

---

## Description

Swiftmailer depends on multiple third-party packages for HTTP communication, DSN parsing, email validation, and cloud provider SDKs. A compromise of any dependency could introduce backdoors, credential theft, or malicious behavior that executes within the trust boundary of the application.

## Attack Vectors

1. **Guzzle HTTP compromise** — GuzzleHttp is the HTTP client for all 21 API transports; a compromised version could exfiltrate API keys
2. **Nyholm DSN parser** — Parses credential-containing DSN strings; a malicious version could capture credentials
3. **AsyncAws SES SDK** — Amazon SES transport delegates to `async-aws/ses`; SDK compromise = AWS credential theft
4. **Google API Client** — Google transport uses `google/apiclient`; OAuth token theft risk
5. **Microsoft Graph SDK** — Microsoft Graph transport delegates to MS SDK
6. **egulias/email-validator** — Email validation library; if compromised, could allow malformed addresses that bypass security checks
7. **PHPUnit/php-cs-fixer in development** — Dev dependencies that execute code; supply chain attack during CI
8. **Transitive dependencies** — Each direct dependency has its own dependency tree

## Affected Dependencies

| Package | Role | Risk Level |
|-|-|-|
| `guzzlehttp/guzzle` | HTTP client for all API transports | HIGH |
| `nyholm/dsn` | DSN string parsing (credentials) | HIGH |
| `async-aws/ses` | Amazon SES API client | HIGH |
| `google/apiclient` | Google Gmail API client | HIGH |
| `microsoft/microsoft-graph` | Microsoft Graph email client | HIGH |
| `egulias/email-validator` | Email address validation | MEDIUM |
| `psr/http-message` | HTTP message interfaces | LOW |
| `symfony/polyfill-*` | PHP compatibility polyfills | LOW |

## Existing Controls

- `composer.lock` pins exact versions (prevents silent upgrades)
- PSR interfaces (PSR-7, PSR-18) provide abstraction boundaries
- Most cloud SDK usage is encapsulated in individual transport classes
- `composer audit` can check for known vulnerabilities

## Control Gaps

1. **No `composer audit` in CI** — Known vulnerabilities not automatically detected
2. **No Dependabot/Renovate** — Dependency updates not automated
3. **No subresource integrity** — Composer packages verified by hash in lockfile but not by signature
4. **Wide dependency tree** — Guzzle alone pulls ~15 transitive dependencies
5. **No vendor isolation** — All dependencies share the same PHP namespace/autoload space
6. **Optional dependencies not security-audited** — `suggest` entries in `composer.json` may recommend unvetted packages
7. **No Software Bill of Materials (SBOM)** — No machine-readable dependency manifest for security scanning

## Mitigation Plan

### Phase 1: CI Security Scanning (Immediate)
- Add `composer audit` to CI pipeline:
  ```yaml
  security-check:
    script:
      - composer audit --format=json
    allow_failure: false
  ```
- Add `roave/security-advisories` as a dev dependency (prevents installing packages with known vulnerabilities)

### Phase 2: Dependency Monitoring (Short-term)
- Enable Dependabot or Renovate for automated PR creation on dependency updates
- Configure security-only updates to auto-merge after CI passes
- Set up GitHub/GitLab security advisory notifications

### Phase 3: Dependency Minimization (Medium-term)
- Audit whether all optional SDK dependencies are necessary
- Consider making cloud SDK packages truly optional (not installed unless needed):
  ```json
  "require": {
      "guzzlehttp/guzzle": "^7.0"
  },
  "suggest": {
      "async-aws/ses": "Required for Amazon SES API transport",
      "google/apiclient": "Required for Google Gmail API transport"
  }
  ```
- Implement runtime checks that throw clear errors when optional SDKs are missing

### Phase 4: SBOM Generation (Medium-term)
- Generate CycloneDX or SPDX SBOM from `composer.lock`
- Include SBOM in release artifacts
- Automate SBOM generation in CI

### Phase 5: Vendor Isolation (Long-term)
- Consider prefixing vendor namespaces using `humbug/php-scoper` for the distributed package
- This prevents conflicts and limits the blast radius of a compromised dependency

## Version Pinning Strategy

```json
{
    "require": {
        "guzzlehttp/guzzle": "^7.8",
        "nyholm/dsn": "^2.0",
        "egulias/email-validator": "^3.0 || ^4.0"
    },
    "config": {
        "lock": true,
        "sort-packages": true,
        "audit": {
            "abandoned": "fail"
        }
    }
}
```

## Monitoring Checklist

- [ ] `composer audit` in CI (blocking)
- [ ] Dependabot/Renovate enabled
- [ ] Security advisory notifications configured
- [ ] `roave/security-advisories` in dev dependencies
- [ ] Quarterly manual review of dependency tree
- [ ] SBOM generation in release pipeline

## Risk After Mitigation

**Residual Risk:** LOW-MEDIUM — Supply chain attacks remain an industry-wide challenge. With CI scanning, automated updates, and dependency minimization, the window of exposure is significantly reduced. Full elimination requires industry-wide improvements in package signing and verification.
