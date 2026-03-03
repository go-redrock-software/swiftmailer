# Threat #23: ReDoS via PHRASE_PATTERN in Header Parsing

**Severity:** MEDIUM
**STRIDE Category:** Denial of Service
**Status:** NOT STARTED

---

## Description

The `PHRASE_PATTERN` constant in `AbstractHeader.php` is an extremely complex recursive regex (~1500 chars) with deeply nested alternations and repetitions (`(?:...)*` patterns with overlapping character classes). It is matched against user-supplied header phrase strings via `preg_match('/^'.self::PHRASE_PATTERN.'$/D', $phraseStr)`. Crafted input strings that almost-but-don't-quite match can cause catastrophic backtracking, consuming CPU for seconds or minutes.

## Affected Files

- `lib/classes/Swift/Mime/Headers/AbstractHeader.php` (lines 18, 212)

## Attack Scenario

An attacker who controls a display name in a From/To/CC field (e.g., via an API or form input) provides a specially crafted string that triggers exponential backtracking in the regex engine, tying up the PHP process and causing denial of service.

## Recommended Mitigations

1. Replace the monolithic recursive regex with an iterative tokenizing parser
2. Alternatively, set a `pcre.backtrack_limit` guard or use `preg_match` with a timeout-aware wrapper
3. At minimum, validate input length before applying the regex (reject phrases longer than 998 characters per RFC 2822)

## Implementation Status (2026-03-02)

| Mitigation | Status | Evidence |
|-|-|-|
| Input length pre-validation | NOT STARTED | — |
| Regex replacement/simplification | NOT STARTED | — |
| Backtrack limit guard | NOT STARTED | — |
