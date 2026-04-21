# Community Standards Design

**Date:** 2026-04-21
**Scope:** Add the community health files recommended by opensource.guide and GitHub's "Community Standards" checklist to `mage-os/module-ai-base`.

## Goal

Bring the repo up to GitHub's "Community Standards" green-check state with the essentials: Code of Conduct, Contributing guide, Security policy, issue templates, and a PR template. Scope is deliberately narrow — no governance docs, no roadmap, no maintainers list.

## Files to add

```
CODE_OF_CONDUCT.md
CONTRIBUTING.md
SECURITY.md
.github/ISSUE_TEMPLATE/bug_report.yml
.github/ISSUE_TEMPLATE/feature_request.yml
.github/ISSUE_TEMPLATE/config.yml
.github/PULL_REQUEST_TEMPLATE.md
```

## Files to modify

- `README.md` — add short **Contributing** and **Security** sections linking to the new files.

## Content specification

### CODE_OF_CONDUCT.md
Verbatim Contributor Covenant v2.1. Enforcement contact: `david@run-as-root.sh`.

### CONTRIBUTING.md
Short, practical. Sections:
- **Reporting bugs** — link to issue template.
- **Proposing changes** — link to feature-request template; encourage an issue before a large PR.
- **Local development** — use a composer path repository into a Magento 2 install; `bin/magento module:enable MageOS_AiBase && bin/magento setup:upgrade && bin/magento setup:di:compile`.
- **Coding conventions** — references `phpcs.xml.dist` for PHPCS rules; match existing style (PHP 8 constructor property promotion + `readonly`; no `declare(strict_types=1)` to stay consistent with surrounding code).
- **Branching** — `feat/*`, `fix/*` branches off `main`; PRs target `main`.
- **Tests** — run via `vendor/bin/phpunit -c phpunit.xml.dist`.
- **Commit style** — conventional-style prefix recommended but not enforced.

### SECURITY.md
- **Supported versions** — only the latest released version.
- **Reporting** — preferred channel is GitHub Private Vulnerability Reporting; fallback via email to `david@run-as-root.sh` **or** `security@mage-os.org`.
- **What to include** — reproduction steps, affected version, expected impact.
- **Response SLA** — acknowledge within 7 days.
- **Disclosure** — please do not open public issues for vulnerabilities.

### .github/ISSUE_TEMPLATE/bug_report.yml
GitHub issue form. Fields:
- Module version (text, required)
- Magento version (text, required)
- Expected behavior (textarea, required)
- Actual behavior (textarea, required)
- Reproduction steps (textarea, required)
- Logs / stack trace (textarea, optional)

### .github/ISSUE_TEMPLATE/feature_request.yml
GitHub issue form. Fields:
- Problem (textarea, required)
- Proposed solution (textarea, required)
- Alternatives considered (textarea, optional)

### .github/ISSUE_TEMPLATE/config.yml
```yaml
blank_issues_enabled: false
```
No `contact_links` — user declined.

### .github/PULL_REQUEST_TEMPLATE.md
Sections:
- Summary
- Linked issue (`Closes #`)
- Change type checklist (bug fix / feature / docs / chore / breaking)
- Test plan
- Docs updated (checkbox)

### README.md additions
Two short sections near the end:

```markdown
## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) and our [Code of Conduct](CODE_OF_CONDUCT.md).

## Security

Security issues: see [SECURITY.md](SECURITY.md) — please do **not** file public issues for vulnerabilities.
```

## Out of scope

- GOVERNANCE.md, MAINTAINERS.md, roadmap — overkill for this module.
- SUPPORT.md — no dedicated support channel to point at.
- README badges — separate concern.
- FUNDING.yml — not requested.

## Success criteria

- GitHub repo "Community Standards" page shows all items checked.
- Opening a new issue from the GitHub UI presents the two templates (bug / feature) and hides the blank option.
- Opening a new PR pre-fills the PR template.
- `SECURITY.md` surfaces the "Report a vulnerability" button on the repo's Security tab (requires Private Vulnerability Reporting to be enabled in repo settings — noted as an operational follow-up, not a code change).

## Operational follow-up (not code)

- Enable **Private Vulnerability Reporting** in repo Settings → Code security & analysis, so the link in `SECURITY.md` resolves to the built-in reporting UI.
