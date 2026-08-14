# Contributing to CFP.DEV WordPress Shortcodes

Thank you for your interest in contributing! Here is everything you need to get started.

---

## Code of Conduct

Please be respectful and constructive in all interactions. We follow the [Contributor Covenant](https://www.contributor-covenant.org/).

---

## Getting Started

This project requires PHP 8.1 or later.

```bash
git clone https://github.com/patbaumgartner/cfp-dev-shortcodes.git
cd cfp-dev-shortcodes
composer install
```

---

## Coding Standards

All PHP must pass [PHP_CodeSniffer](https://github.com/squizlabs/PHP_CodeSniffer) with the [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/):

```bash
composer check       # lint and test
composer lint-fix    # auto-fix
```

Zero errors/warnings are required before a PR can be merged.

Key rules:
- Indentation with **tabs** (not spaces)
- Output always escaped with `esc_html()`, `esc_url()`, `esc_attr()`, or `wp_kses_post()`
- All `$_POST` / `$_GET` inputs sanitized with `sanitize_text_field(wp_unslash(...))`
- Nonces on all admin form submissions
- No direct database queries — use WordPress functions

---

## Translatable Strings

Every user-facing string goes through the `cfp-dev-shortcodes` text domain. If
you add, change or remove one, refresh the template:

```bash
composer i18n        # needs WP-CLI
```

`composer test` fails when `languages/cfp-dev-shortcodes.pot` and the source
disagree, so translators never silently lose a string.

---

## Workflow

1. Fork the repository and create a feature branch from `main`:
   ```bash
   git checkout -b feature/my-improvement
   ```
2. Make your changes and commit with a clear message.
3. Run `composer check` — fix any issues.
4. Run `composer i18n` if you touched a translatable string.
5. Update `CHANGELOG.md` under the appropriate version heading.
6. Update `README.md` if you changed or added shortcodes / settings.
7. Open a pull request against `main` using the PR template.

---

## Reporting Bugs

Use the [Bug Report issue template](https://github.com/patbaumgartner/cfp-dev-shortcodes/issues/new?template=bug_report.md).

## Requesting Features

Use the [Feature Request issue template](https://github.com/patbaumgartner/cfp-dev-shortcodes/issues/new?template=feature_request.md).

---

## Local Testing

You need a running WordPress instance and a CFP.DEV key. Copy the plugin directory into `wp-content/plugins/`, activate it, and enter your CFP.DEV key in **Settings → CFP.DEV**.

---

## License

By contributing you agree that your contributions will be licensed under [GPL-2.0-or-later](../LICENSE.txt).
