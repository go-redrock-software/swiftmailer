# Merge-train regression coverage

This inventory covers the 37 original merge requests and the integration,
reporter and compatibility follow-ups merged into `develop`. It identifies
executable behavior checks rather than claiming that every possible input is
covered. New behavioral fixes must extend the relevant contract test. Fixes
released before the merge train (v6.5.1 to v6.5.8, such as the TLS handshake and
Graph calendar corrections) have tests in their own suites, such as
`StreamBufferTest` and the `MicrosoftGraph*Test` files, but are not indexed here.

## Original merge requests

| MR | Change | Durable checks | Protected behavior |
|---|---|---|---|
| [!1](https://code.go-redrock.com/trac/swiftmailer/-/merge_requests/1) | HTML reporter output escaping | [HtmlReporterTest.php](../tests/unit/Swift/Plugins/Reporters/HtmlReporterTest.php) | UTF-8 text and escaped recipient output, including plugin delivery. |
| [!2](https://code.go-redrock.com/trac/swiftmailer/-/merge_requests/2) | Allowlist catch-all routing | [AllowlistPluginTest.php](../tests/unit/Swift/Plugins/AllowlistPluginTest.php) | To/Cc/Bcc filtering, catch-all delivery and original recipient restoration. |
| [!3](https://code.go-redrock.com/trac/swiftmailer/-/merge_requests/3) | Explicit SMTP Bcc envelope | [AbstractSmtpTest.php](../tests/unit/Swift/Transport/AbstractSmtpTest.php) | Bcc delivery and header stripping through the SMTP transport contract. |
| [!4](https://code.go-redrock.com/trac/swiftmailer/-/merge_requests/4) | Recipient policies with envelopes | [RecipientPolicyEnvelopeTest.php](../tests/unit/Swift/Integration/RecipientPolicyEnvelopeTest.php) | Policies govern delivery without corrupting caller headers. |
| [!5](https://code.go-redrock.com/trac/swiftmailer/-/merge_requests/5) | Mailpit smoke and deprecation policy | [MailpitSmokeTest.php](../tests/smoke/Swift/Smoke/MailpitSmokeTest.php) | Actual SMTP delivery; the CI process exit checks the deprecation policy. |
| [!6](https://code.go-redrock.com/trac/swiftmailer/-/merge_requests/6) | Infection interception bootstrap | [infection.json5](../infection.json5) | Native Infection and its isolated PHPUnit runner exercise actual mutants; see validation limits below. |
| [!7](https://code.go-redrock.com/trac/swiftmailer/-/merge_requests/7) | API envelope delivery | [ApiEnvelopeTest.php](../tests/unit/Swift/Integration/ApiEnvelopeTest.php) | Provider destinations, sender overrides, failed-recipient events, unsupported overrides and restoration. |
| [!8](https://code.go-redrock.com/trac/swiftmailer/-/merge_requests/8) | Scaleway Cc/Bcc | [ScalewayTransportTest.php](../tests/unit/Swift/Transport/Api/ScalewayTransportTest.php) | Separate To/Cc/Bcc payloads, display names and Reply-To. |
| [!9](https://code.go-redrock.com/trac/swiftmailer/-/merge_requests/9) | Graph calendar audience | [MicrosoftGraphCalendarMailboxTest.php](../tests/unit/Swift/Transport/Api/MicrosoftGraphCalendarMailboxTest.php) | Policy audiences, attendee roles, conditional cancellation and stale-version replay refusal. |
| [!10](https://code.go-redrock.com/trac/swiftmailer/-/merge_requests/10) | Default API listener dispatch | [ApiDefaultDispatcherTest.php](../tests/unit/Swift/Integration/ApiDefaultDispatcherTest.php), [AbstractApiTransportNullDispatcherTest.php](../tests/unit/Swift/Transport/AbstractApiTransportNullDispatcherTest.php) | Default and explicitly absent dispatchers retain the intended event lifecycle. |
| [!11](https://code.go-redrock.com/trac/swiftmailer/-/merge_requests/11) | Mutation target and child policy | [MutationDiffTest.php](../tests/unit/Swift/Ci/MutationDiffTest.php) | Real temporary Git repositories: shallow history, stale refs, target identity, literal arguments, filenames and hard failures; CI checks child deprecation behavior. |
| [!12](https://code.go-redrock.com/trac/swiftmailer/-/merge_requests/12) | Sweego recipient privacy | [SweegoRecipientPrivacyTest.php](../tests/unit/Swift/Transport/Api/SweegoRecipientPrivacyTest.php) | Recipient fields remain separate and blind recipients stay private. |
| [!13](https://code.go-redrock.com/trac/swiftmailer/-/merge_requests/13) | Sweego attachment encoding | [SweegoAttachmentEncodingTest.php](../tests/unit/Swift/Transport/Api/SweegoAttachmentEncodingTest.php) | Attachment content and encoding at the HTTP boundary. |
| [!14](https://code.go-redrock.com/trac/swiftmailer/-/merge_requests/14) | HTTP response acceptance | [HttpResponseBoundaryTest.php](../tests/unit/Swift/Transport/Api/HttpResponseBoundaryTest.php) | Status/body contradictions, malformed successes, metadata, failure events and refusal to replay uncertain acceptance. |
| [!15](https://code.go-redrock.com/trac/swiftmailer/-/merge_requests/15) | Generated class maps | [ClassMapGeneratorTest.php](../tests/unit/Swift/ClassMapGeneratorTest.php) | Every source symbol and runtime alias; deterministic output, enums, parse errors and atomic failure. |
| [!16](https://code.go-redrock.com/trac/swiftmailer/-/merge_requests/16) | Sweego HTTP contract | [SweegoHttpContractTest.php](../tests/unit/Swift/Transport/Api/SweegoHttpContractTest.php) | Real Guzzle middleware, status validation independent of parsers, options and sent metadata. |
| [!17](https://code.go-redrock.com/trac/swiftmailer/-/merge_requests/17) | HTTP redirect policy | [HttpRedirectPolicyTest.php](../tests/unit/Swift/Transport/Api/HttpRedirectPolicyTest.php) | Send and health-check redirects stop before another request; valid acknowledgements remain accepted. |
| [!18](https://code.go-redrock.com/trac/swiftmailer/-/merge_requests/18) | Gmail request policy | [GmailSdkRequestPolicyTest.php](../tests/unit/Swift/Transport/Api/GmailSdkRequestPolicyTest.php) | Native SDK redirects, retries, refresh callbacks, caller ownership, full MIME, envelope/policy cleanup and unsupported clients. |
| [!19](https://code.go-redrock.com/trac/swiftmailer/-/merge_requests/19) | Graph resource policy | [GraphSdkRequestPolicyTest.php](../tests/unit/Swift/Transport/Api/GraphSdkRequestPolicyTest.php) | Native Graph resource requests, redirect/retry controls and caller state. |
| [!20](https://code.go-redrock.com/trac/swiftmailer/-/merge_requests/20) | Graph OAuth policy and causes | [GraphOAuthRequestPolicyTest.php](../tests/unit/Swift/Transport/Api/GraphOAuthRequestPolicyTest.php), [ExceptionCauseTest.php](../tests/unit/Swift/ExceptionCauseTest.php) | Token request boundaries and preservation/classification of Exception and Error causes. |
| [!21](https://code.go-redrock.com/trac/swiftmailer/-/merge_requests/21) | SES request, credential and health policy | [SesRequestPolicyTest.php](../tests/unit/Swift/Transport/Api/SesRequestPolicyTest.php), [SesCredentialPolicyTest.php](../tests/unit/Swift/Transport/Api/SesCredentialPolicyTest.php), [SesPolicyCompatibilityTest.php](../tests/unit/Swift/Transport/Api/SesPolicyCompatibilityTest.php), [SesNativeRedirectTest.php](../tests/unit/Swift/Transport/Api/SesNativeRedirectTest.php) | Native AsyncAws policies, credential boundaries and actual loopback redirect refusal. |
| [!22](https://code.go-redrock.com/trac/swiftmailer/-/merge_requests/22) | SES raw MIME | [SesMimeDeliveryTest.php](../tests/unit/Swift/Transport/Api/SesMimeDeliveryTest.php) | MIME bytes, attachments, blind-recipient stripping and delivery destinations. |
| [!23](https://code.go-redrock.com/trac/swiftmailer/-/merge_requests/23) | Graph CAE grant refresh | [GraphOAuthGrantPolicyTest.php](../tests/unit/Swift/Transport/Api/GraphOAuthGrantPolicyTest.php) | Secret/certificate/PKCE grants, repeated challenges, fallback validation, endpoint identity, reentrancy and caller-state preservation. |
| [!24](https://code.go-redrock.com/trac/swiftmailer/-/merge_requests/24) | Terminal failures and wrappers | [TerminalFailurePolicyTest.php](../tests/unit/Swift/Transport/TerminalFailurePolicyTest.php), [DefaultRetryClassifierTest.php](../tests/unit/Swift/Transport/DefaultRetryClassifierTest.php), [ExceptionCauseTest.php](../tests/unit/Swift/ExceptionCauseTest.php), [FailoverTransportTest.php](../tests/unit/Swift/Transport/FailoverTransportTest.php) | Permanent markers prevent retry/fallback, including nested causes; ordinary failures retain recovery. |
| [!25](https://code.go-redrock.com/trac/swiftmailer/-/merge_requests/25) | Pool lifecycle | [PoolLifecyclePolicyTest.php](../tests/unit/Swift/Transport/PoolLifecyclePolicyTest.php) | Retired backends, cached selections, reentrancy, plugin registration, health checks and pool replacement. |
| [!26](https://code.go-redrock.com/trac/swiftmailer/-/merge_requests/26) | Pool retry periods | [PoolRetryPeriodTest.php](../tests/unit/Swift/Transport/PoolRetryPeriodTest.php), [DsnTransportFactoryRetryPeriodTest.php](../tests/unit/Swift/Transport/DsnTransportFactoryRetryPeriodTest.php), [PoolCompatibilityTest.php](../tests/unit/Swift/Transport/PoolCompatibilityTest.php) | Explicit cooldowns, zero and boundary times; legacy manual start recovery remains the default. |
| [!27](https://code.go-redrock.com/trac/swiftmailer/-/merge_requests/27) | Sendgrid tracking | [SendgridTrackingTest.php](../tests/unit/Swift/Transport/Api/SendgridTrackingTest.php) | Nullable options, literal false, payload reset/reuse, real HTTP retry and DSN wrapper validation. |
| [!28](https://code.go-redrock.com/trac/swiftmailer/-/merge_requests/28) | Public DSN mailer helper | [DsnMailerHelperTest.php](../tests/unit/Swift/DsnMailerHelperTest.php) | Actual null/HTTP sends, independent instances, decorators, pool periods and safe construction failures. |
| [!29](https://code.go-redrock.com/trac/swiftmailer/-/merge_requests/29) | SMTP DSN options and zero credentials | [DsnSmtpOptionsTest.php](../tests/unit/Swift/Transport/DsnSmtpOptionsTest.php) | Native defaults, timeout/auth/TLS options, no connection during construction, literal zero and loopback EHLO/AUTH bytes. |
| [!30](https://code.go-redrock.com/trac/swiftmailer/-/merge_requests/30) | HTTP provider DSNs | [DsnHttpProvidersTest.php](../tests/unit/Swift/Transport/DsnHttpProvidersTest.php), [DsnTransportMapContractTest.php](../tests/unit/Swift/Transport/DsnTransportMapContractTest.php) | Provider construction and endpoint/option mappings. |
| [!31](https://code.go-redrock.com/trac/swiftmailer/-/merge_requests/31) | DSN failure privacy | [DsnPrivacyTest.php](../tests/unit/Swift/DsnPrivacyTest.php), [DsnMailerHelperTest.php](../tests/unit/Swift/DsnMailerHelperTest.php) | Safe errors without credentials or sensitive exception graphs. |
| [!32](https://code.go-redrock.com/trac/swiftmailer/-/merge_requests/32) | SES DSN, acceptance and privacy | [DsnSesTest.php](../tests/unit/Swift/Transport/DsnSesTest.php), [SesAcceptanceTest.php](../tests/unit/Swift/Transport/Api/SesAcceptanceTest.php), [SesFailurePrivacyTest.php](../tests/unit/Swift/Transport/Api/SesFailurePrivacyTest.php) | Native DSN sends, valid/ambiguous acknowledgements, terminal replay guards and safe failures. |
| [!33](https://code.go-redrock.com/trac/swiftmailer/-/merge_requests/33) | Gmail acceptance and privacy | [GmailAcceptanceTest.php](../tests/unit/Swift/Transport/Api/GmailAcceptanceTest.php), [GmailFailurePrivacyTest.php](../tests/unit/Swift/Transport/Api/GmailFailurePrivacyTest.php), [AcceptedReplayCompatibilityTest.php](../tests/unit/Swift/Transport/Api/AcceptedReplayCompatibilityTest.php) | Native accepted/error responses, private diagnostics and accepted-response middleware replay guards. |
| [!34](https://code.go-redrock.com/trac/swiftmailer/-/merge_requests/34) | Gmail DSNs | [DsnGmailTest.php](../tests/unit/Swift/Transport/DsnGmailTest.php) | Native fixed-endpoint sends, authentication options and envelope/policy behavior. |
| [!35](https://code.go-redrock.com/trac/swiftmailer/-/merge_requests/35) | Graph failure metadata and privacy | [GraphFailureMetadataTest.php](../tests/unit/Swift/Transport/Api/GraphFailureMetadataTest.php), [GraphFailurePrivacyTest.php](../tests/unit/Swift/Transport/Api/GraphFailurePrivacyTest.php) | Delivery metadata and redacted errors across native SDK failure paths. |
| [!36](https://code.go-redrock.com/trac/swiftmailer/-/merge_requests/36) | Graph acceptance | [GraphAcceptanceTest.php](../tests/unit/Swift/Transport/Api/GraphAcceptanceTest.php) | Direct sends, paginated lookup, draft/upload progress, opaque URLs, malformed acceptance, reentrancy and refusal to replay accepted work. |
| [!37](https://code.go-redrock.com/trac/swiftmailer/-/merge_requests/37) | Graph DSNs and CAE | [DsnGraphTest.php](../tests/unit/Swift/Transport/DsnGraphTest.php), [DsnGraphPrivacyTest.php](../tests/unit/Swift/Transport/DsnGraphPrivacyTest.php) | Bearer/application authentication, token renewal, CAE challenges, draft/upload/calendar, endpoint restrictions and safe failures. |

## Integration and compatibility follow-ups

| MRs | Durable checks | Protected behavior |
|---|---|---|
| !38 / !39 | [GmailFailurePrivacyTest](../tests/unit/Swift/Transport/Api/GmailFailurePrivacyTest.php), [SesFailurePrivacyTest](../tests/unit/Swift/Transport/Api/SesFailurePrivacyTest.php), [GraphAcceptanceTest](../tests/unit/Swift/Transport/Api/GraphAcceptanceTest.php), [MicrosoftGraphCalendarMailboxTest](../tests/unit/Swift/Transport/Api/MicrosoftGraphCalendarMailboxTest.php) | Integrated failure callback signatures, preflight audiences, event identity and accepted delivery counts. |
| !40 / #41 | [HtmlReporterStreamingTest](../tests/unit/Swift/Plugins/Reporters/HtmlReporterStreamingTest.php) | Eight actual CLI/FPM cases inspect client bytes before completion using a barrier; buffered controls preserve the same escaped HTML. Required-FPM CI cannot silently skip FastCGI coverage. |
| !41 / #90 | [PoolCompatibilityTest](../tests/unit/Swift/Transport/PoolCompatibilityTest.php) | 68 cases preserve serialized/cloned pools, subclasses that skip the parent constructor, legacy manual recovery and explicit cooldowns. |
| !41 / #90 | [RecipientStateCompatibilityTest](../tests/unit/Swift/Integration/RecipientStateCompatibilityTest.php) | 41 cases preserve overridden getters, nested plugin snapshots, caller recipients and event state. |
| !41 / #90 | [EnvelopeCompatibilityTest](../tests/unit/Swift/Integration/EnvelopeCompatibilityTest.php) | 30 cases preserve equivalent derived envelopes, repeated addresses and provider delivery behavior. |
| !41 / #90, expanded by !42 / #91 | [AcceptedReplayCompatibilityTest](../tests/unit/Swift/Transport/Api/AcceptedReplayCompatibilityTest.php) | 97 cases cover Gmail and both SES transports at 200/201/202 through plain, retry, failover and round-robin delivery. Caller callbacks/strategies cannot replay accepted requests or trigger a fallback; message bytes and caller clients remain intact. A credential-request control retains legitimate caller retries. |

The Gmail request-policy suite also checks that repeated sends export complete MIME
exactly once, protecting the attachment-export correction in `lib/functions.php`.
Documentation-only type clarifications require no additional runtime behavior.

## Permanent native SDK dependency matrix

[SdkCompatibilityTest](../tests/unit/Swift/Integration/SdkCompatibilityTest.php)
runs each of the 12 [standalone contracts](../tests/compatibility/sdk.php) in a fresh
PHP process as part of normal PHPUnit. The same harness runs without development
dependencies in the `sdk-compatibility` CI jobs on PHP 8.3 and 8.5, after Composer
resolves the lowest versions permitted by the existing constraints and security
advisories. Normal PHPUnit jobs test current dependencies on both runtimes.

Contracts exercise pool function metadata, zero SMTP credentials, false Sendgrid
tracking options, native Gmail MIME, both native SES raw-MIME transports,
equivalent repeated-recipient envelopes through HTTP/Gmail/Graph, native Graph
recipient fields, and bearer/application Graph DSNs including token exchange.
All HTTP endpoints are synthetic handlers. No provider credentials or external
mail delivery are required. Failed contracts produce a nonzero process exit;
SDK objects and exception graphs are never serialized into the result output.

## Validation limits

The broad unit/acceptance/bug/smoke run remains necessary; the table is a navigation
index, not a replacement suite. Local acceptance/smoke cases requiring unconfigured
external services may skip; CI separately delivers to Mailpit. CLI-only systems
skip FPM unless `SWIFTMAILER_REQUIRE_FPM=1` makes its absence a failure.

Infrastructure changes (Infection bootstrap and inherited child-process
deprecation policy) are verified by the native tool/process exits, not tests that
merely assert configuration strings. The preceding #90 changed-line Infection
run killed 111 of 115 mutants, with one errored and three reviewed equivalent
survivors (97.39% MSI; the survivor review is recorded under "Native changed-line
mutation verification" in #90's handoff); no mutation threshold or baseline was relaxed. The #91
coverage-only change has no production PHP diff to mutate.

The historical upstream suite comparison in [#90's handoff](../docs/issues/90.md)
retains 93 known fixture/security-expectation differences; it was not presented
as an entirely passing upstream suite. Neither finite regression tests nor the
synthetic SDK boundary establish real-provider availability or universal absence
of regressions. These checks protect the concrete behaviors listed above.
