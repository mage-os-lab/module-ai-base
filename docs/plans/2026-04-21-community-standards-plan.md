# Community Standards Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add GitHub "Community Standards" files (Code of Conduct, Contributing, Security, issue + PR templates) to `mage-os/module-ai-base`.

**Architecture:** Static markdown + YAML files in the repo root and `.github/`. No code paths are touched. Each file is self-contained; tasks are independent and can be done in any order.

**Tech Stack:** Markdown, GitHub issue forms (YAML).

**Design doc:** `docs/plans/2026-04-21-community-standards-design.md`

**Branch:** Work on the currently-checked-out branch (`feat/v1.0.0-release`). Do NOT create a new branch.

**Verification approach:** This repo has no markdown linter or YAML linter configured. Verification per task = visual inspection of the rendered file + a `python -c "import yaml; yaml.safe_load(open('...'))"` parse check for the two issue form YAMLs.

---

### Task 1: Add CODE_OF_CONDUCT.md

**Files:**
- Create: `CODE_OF_CONDUCT.md`

**Step 1: Fetch the Contributor Covenant v2.1 text**

Download the canonical markdown version:

```bash
curl -fsSL https://raw.githubusercontent.com/EthicalSource/contributor_covenant/release/content/version/2/1/code_of_conduct.md -o CODE_OF_CONDUCT.md
```

**Step 2: Substitute the enforcement contact**

The template contains the literal placeholder `[INSERT CONTACT METHOD]`. Replace it with `david@run-as-root.sh`.

Use Edit tool:
- old_string: `[INSERT CONTACT METHOD]`
- new_string: `david@run-as-root.sh`

**Step 3: Verify**

```bash
grep -c "david@run-as-root.sh" CODE_OF_CONDUCT.md   # expect: 1
grep -c "INSERT CONTACT METHOD" CODE_OF_CONDUCT.md  # expect: 0
head -3 CODE_OF_CONDUCT.md                          # expect: "# Contributor Covenant Code of Conduct"
```

**Step 4: Commit**

```bash
git add CODE_OF_CONDUCT.md
git commit -m "docs: add Contributor Covenant v2.1 code of conduct"
```

---

### Task 2: Add CONTRIBUTING.md

**Files:**
- Create: `CONTRIBUTING.md`

**Step 1: Write the file**

Contents:

```markdown
# Contributing to MageOS_AiBase

Thanks for your interest in improving this module. This guide covers how to report issues, propose changes, and develop locally.

## Reporting bugs

Open an issue using the **Bug report** template. Please include your Magento version, module version, and reproduction steps.

## Proposing changes

For anything larger than a typo fix, please open a **Feature request** issue first so we can align on the approach before you invest time in a PR.

## Local development

This repo contains a Magento 2 module, not a runnable Magento instance. To work on it:

1. Clone this repository outside your Magento install.
2. In your Magento 2 project, add a Composer path repository pointing at your clone:
   ```json
   "repositories": [
     { "type": "path", "url": "/absolute/path/to/module-ai-base" }
   ]
   ```
3. Require the module and enable it:
   ```bash
   composer require mage-os/module-ai-base:@dev
   bin/magento module:enable MageOS_AiBase
   bin/magento setup:upgrade
   bin/magento setup:di:compile
   ```

## Coding conventions

- PHP 8 constructor property promotion with `readonly` is the norm — match it for new classes.
- No `declare(strict_types=1)` header is used in existing files; keep things consistent unless you're explicitly modernizing in a dedicated PR.
- Coding standard: see `phpcs.xml.dist`. Run `vendor/bin/phpcs` from a host Magento install that includes this module.

## Branching and pull requests

- Branch off `main` using `feat/<short-name>` or `fix/<short-name>`.
- Target PRs at `main`.
- Keep PRs focused — one concern per PR.
- Fill in the PR template (summary, linked issue, test plan).

## Tests

Run the test suite with:

```bash
vendor/bin/phpunit -c phpunit.xml.dist
```

New PHP unit tests must be `final` classes and use `snake_case` method names.

## Commit messages

Conventional-style prefixes (`feat:`, `fix:`, `docs:`, `ci:`, `chore:`) are encouraged but not enforced.
```

**Step 2: Verify**

```bash
head -1 CONTRIBUTING.md   # expect: "# Contributing to MageOS_AiBase"
wc -l CONTRIBUTING.md     # sanity check: file is non-trivial
```

**Step 3: Commit**

```bash
git add CONTRIBUTING.md
git commit -m "docs: add CONTRIBUTING guide"
```

---

### Task 3: Add SECURITY.md

**Files:**
- Create: `SECURITY.md`

**Step 1: Write the file**

Contents:

```markdown
# Security Policy

## Supported versions

Only the **latest released version** receives security updates.

## Reporting a vulnerability

Please do **not** open public GitHub issues for security vulnerabilities.

Preferred channel: **GitHub Private Vulnerability Reporting**. Go to the [Security tab](../../security/advisories/new) of this repository and click "Report a vulnerability".

If you cannot use GitHub, email one of:

- `david@run-as-root.sh`
- `security@mage-os.org`

Please include:

- Affected module version and Magento version
- Reproduction steps or proof-of-concept
- Expected impact

We aim to acknowledge reports within **7 days**. Once a fix is available, we will coordinate a disclosure timeline with you.
```

**Step 2: Verify**

```bash
head -1 SECURITY.md                       # expect: "# Security Policy"
grep -c "security@mage-os.org" SECURITY.md  # expect: 1
grep -c "david@run-as-root.sh" SECURITY.md  # expect: 1
```

**Step 3: Commit**

```bash
git add SECURITY.md
git commit -m "docs: add SECURITY policy"
```

---

### Task 4: Add issue template config

**Files:**
- Create: `.github/ISSUE_TEMPLATE/config.yml`

**Step 1: Write the file**

```yaml
blank_issues_enabled: false
```

**Step 2: Verify it parses as YAML**

```bash
python3 -c "import yaml; print(yaml.safe_load(open('.github/ISSUE_TEMPLATE/config.yml')))"
```
Expected output: `{'blank_issues_enabled': False}`

**Step 3: Commit**

```bash
git add .github/ISSUE_TEMPLATE/config.yml
git commit -m "ci: disable blank issues"
```

---

### Task 5: Add bug report issue form

**Files:**
- Create: `.github/ISSUE_TEMPLATE/bug_report.yml`

**Step 1: Write the file**

```yaml
name: Bug report
description: Report a bug in MageOS_AiBase
title: "[Bug]: "
labels: ["bug"]
body:
  - type: markdown
    attributes:
      value: |
        Thanks for taking the time to file a bug report. Please fill in the fields below.
  - type: input
    id: module-version
    attributes:
      label: Module version
      placeholder: "1.0.0"
    validations:
      required: true
  - type: input
    id: magento-version
    attributes:
      label: Magento / Mage-OS version
      placeholder: "Magento 2.4.7 / Mage-OS 2.2.0"
    validations:
      required: true
  - type: textarea
    id: expected
    attributes:
      label: Expected behavior
      description: What did you expect to happen?
    validations:
      required: true
  - type: textarea
    id: actual
    attributes:
      label: Actual behavior
      description: What actually happened?
    validations:
      required: true
  - type: textarea
    id: reproduction
    attributes:
      label: Reproduction steps
      description: Minimal steps to reproduce the issue.
      placeholder: |
        1. Go to ...
        2. Click on ...
        3. See error
    validations:
      required: true
  - type: textarea
    id: logs
    attributes:
      label: Logs / stack trace
      description: Any relevant output from var/log/ or the browser console.
      render: shell
    validations:
      required: false
```

**Step 2: Verify**

```bash
python3 -c "import yaml; d = yaml.safe_load(open('.github/ISSUE_TEMPLATE/bug_report.yml')); assert d['name'] == 'Bug report'; assert len(d['body']) >= 6; print('ok')"
```
Expected output: `ok`

**Step 3: Commit**

```bash
git add .github/ISSUE_TEMPLATE/bug_report.yml
git commit -m "ci: add bug report issue form"
```

---

### Task 6: Add feature request issue form

**Files:**
- Create: `.github/ISSUE_TEMPLATE/feature_request.yml`

**Step 1: Write the file**

```yaml
name: Feature request
description: Suggest a new feature or enhancement
title: "[Feature]: "
labels: ["enhancement"]
body:
  - type: textarea
    id: problem
    attributes:
      label: Problem
      description: What problem are you trying to solve? Who is affected?
    validations:
      required: true
  - type: textarea
    id: solution
    attributes:
      label: Proposed solution
      description: How would you like this to work?
    validations:
      required: true
  - type: textarea
    id: alternatives
    attributes:
      label: Alternatives considered
      description: Any other approaches you considered and why you ruled them out.
    validations:
      required: false
```

**Step 2: Verify**

```bash
python3 -c "import yaml; d = yaml.safe_load(open('.github/ISSUE_TEMPLATE/feature_request.yml')); assert d['name'] == 'Feature request'; print('ok')"
```
Expected output: `ok`

**Step 3: Commit**

```bash
git add .github/ISSUE_TEMPLATE/feature_request.yml
git commit -m "ci: add feature request issue form"
```

---

### Task 7: Add PR template

**Files:**
- Create: `.github/PULL_REQUEST_TEMPLATE.md`

**Step 1: Write the file**

```markdown
## Summary

<!-- What does this PR do and why? 1-3 sentences. -->

## Linked issue

Closes #

## Change type

- [ ] Bug fix
- [ ] New feature
- [ ] Documentation
- [ ] Chore / refactor / CI
- [ ] Breaking change

## Test plan

<!-- How did you verify this change? Commands, manual steps, screenshots. -->

## Checklist

- [ ] Tests added or updated
- [ ] Docs / README updated if behavior changed
- [ ] `phpcs` and `phpunit` pass locally
```

**Step 2: Verify**

```bash
head -1 .github/PULL_REQUEST_TEMPLATE.md   # expect: "## Summary"
```

**Step 3: Commit**

```bash
git add .github/PULL_REQUEST_TEMPLATE.md
git commit -m "ci: add pull request template"
```

---

### Task 8: Link community files from README

**Files:**
- Modify: `README.md` (append two sections to end)

**Step 1: Read current README end**

Use Read tool on `README.md` to see current final line (expected: line 47 with the closing backticks of the code block).

**Step 2: Append Contributing + Security sections**

Use Edit tool to replace the final line of the file. Exact transformation:

- old_string: (the last line of the existing README plus a trailing newline — verify with Read first)
- new_string: (same final line) followed by:

```markdown

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) and our [Code of Conduct](CODE_OF_CONDUCT.md).

## Security

Security issues: see [SECURITY.md](SECURITY.md). Please do **not** file public issues for vulnerabilities.
```

**Step 3: Verify**

```bash
tail -8 README.md   # expect the two new sections
grep -c "CONTRIBUTING.md" README.md   # expect: 1
grep -c "SECURITY.md" README.md       # expect: 1
grep -c "CODE_OF_CONDUCT.md" README.md  # expect: 1
```

**Step 4: Commit**

```bash
git add README.md
git commit -m "docs: link community files from README"
```

---

### Task 9: Operational follow-up (not code)

Remind the user to enable **Private Vulnerability Reporting** in the repo settings:

> GitHub → repository **Settings** → **Code security and analysis** → **Private vulnerability reporting** → **Enable**.

This is required for the `../../security/advisories/new` link in `SECURITY.md` to resolve to a working reporting form. Do not push this task onto the executor — just surface it in the final summary.

---

## Global success criteria

After all tasks are done:

```bash
git status        # clean
git log --oneline -10   # 8 new commits in order Task 1..8
ls CODE_OF_CONDUCT.md CONTRIBUTING.md SECURITY.md
ls .github/ISSUE_TEMPLATE/*.yml .github/PULL_REQUEST_TEMPLATE.md
```

All files should exist. The repo's GitHub **Insights → Community Standards** page should then show a full green check once pushed.
