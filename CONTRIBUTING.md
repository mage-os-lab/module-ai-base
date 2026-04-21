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
