## Summary

<One paragraph: what changes and why. Link the milestone issue.>

Closes #

## Plan references

- Requirements: FR-…, NFR-…
- Design docs followed: design doc <nn> § <section>
- ADRs relied on: ADR-0xx
- Plan documents updated in this PR (if behaviour changed): design doc <nn>

## Type of change

- [ ] feat  - [ ] fix  - [ ] perf  - [ ] refactor  - [ ] test  - [ ] docs  - [ ] chore  - [ ] security

## Checklist

- [ ] PHP 8.2 floor — `php -l` on 8.2, PHPStan and the fixer clean
- [ ] Tests added/updated; `@covers-requirement` tags present
- [ ] Docblocks complete; no I/O in constructors; dependencies injected
- [ ] No new runtime dependency (ADR-008)
- [ ] If touching src/Engine: corpus + goldens updated, Conformance green, KNOWN-DIFFERENCES reviewed, COMPAT bump considered (engine-conformance skill)
- [ ] If touching Support/Path, Cache/, Compiler/Cli/, Http/, Console/InstallCli, Scanner/, Config/: security checklist below filled (security-review skill)
- [ ] Benchmarks within tolerance (`composer bench`) if touching the request path or engine
- [ ] CHANGELOG.md `Unreleased` updated

## Security checklist (delete if not applicable)

Areas touched:
Threats reviewed (design doc 12): T-
New/updated tests: tests/Security/
Static checks: pass / fail
composer audit: clean
Notes for reviewer:

## How to verify

<Commands or steps a reviewer can run. For behaviour visible in a browser, list the
request(s) and expected headers/status.>
