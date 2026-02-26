# Contributing to AI WP Dynamic Cache Plugin

Thank you for your interest in contributing! This document explains how to set up a development environment, follow project conventions, and submit high-quality pull requests.

---

## Table of Contents

1. [Code of Conduct](#code-of-conduct)
2. [Development Setup](#development-setup)
3. [Repository Layout](#repository-layout)
4. [PHP Coding Standards](#php-coding-standards)
5. [TypeScript Coding Standards](#typescript-coding-standards)
6. [Commit Message Format](#commit-message-format)
7. [Pull Request Process](#pull-request-process)
8. [Testing Requirements](#testing-requirements)
9. [Documentation](#documentation)

---

## Code of Conduct

This project follows the [Contributor Covenant Code of Conduct](https://www.contributor-covenant.org/version/2/1/code_of_conduct/). By participating you agree to uphold these standards.

---

## Development Setup

### Prerequisites

| Tool | Minimum version |
|------|----------------|
| Docker Desktop (or Docker Engine + Compose plugin) | 24.x |
| Node.js | 20 LTS |
| PHP | 8.1 |
| Composer | 2.x |
| WP-CLI | 2.x |

### Quick Start with Docker Sandbox

The `sandbox/` directory contains a pre-configured Docker Compose environment that spins up:

- WordPress (latest) on PHP 8.1-FPM + Nginx
- MariaDB 10.11
- Redis (for object cache benchmarks)
- A local Cloudflare Worker simulation via [Miniflare](https://miniflare.dev/)
- k6 for load-test benchmarks

```bash
# 1. Clone the repository
git clone https://github.com/example/AI_WP_Dynamic_Cache_Plugin.git  # TODO: replace with the actual repository URL
cd AI_WP_Dynamic_Cache_Plugin

# 2. Copy environment file and edit secrets
cp sandbox/.env.example sandbox/.env
$EDITOR sandbox/.env

# 3. Start the full stack
docker compose -f sandbox/docker-compose.yml up -d

# 4. Install PHP dependencies
composer install

# 5. Install Node/TypeScript dependencies
npm ci

# 6. Activate the plugin in the sandbox WP
docker compose -f sandbox/docker-compose.yml exec wordpress \
  wp plugin activate ai-wp-dynamic-cache --allow-root
```

WordPress will be available at `http://localhost:8080` and the Miniflare worker at `http://localhost:8787`.

### Stopping the Sandbox

```bash
docker compose -f sandbox/docker-compose.yml down -v
```

---

## Repository Layout

```
.
├── includes/           # PHP plugin core (classes, interfaces)
├── admin/              # WordPress admin UI (PHP + assets)
├── worker/             # Cloudflare Worker (TypeScript)
│   ├── src/
│   └── wrangler.toml
├── sandbox/            # Docker Compose dev environment
│   ├── docker-compose.yml
│   ├── .env.example
│   └── k6/             # Load-test scripts
├── tests/
│   ├── php/            # PHPUnit test suites
│   └── worker/         # Vitest/Jest suites for Worker
├── docs/               # Architecture and operational docs
└── bin/                # Helper CLI scripts
```

---

## PHP Coding Standards

All PHP code **must** pass:

1. **[PSR-12](https://www.php-fig.org/psr/psr-12/)** – enforced via PHP_CodeSniffer.
2. **[WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/)** (`WordPress-Extra` ruleset) – enforced via `phpcs`.
3. **PHPStan** at level 8 (strict type safety).

### Running the Linters Locally

```bash
# PHP_CodeSniffer + WordPress Coding Standards
composer lint

# PHPStan static analysis
composer analyse

# Auto-fix fixable PHPCS issues
composer lint:fix
```

### Key PHP Conventions

- Use strict types: every file starts with `declare(strict_types=1);`.
- All public methods must have full PHPDoc blocks.
- Use dependency injection — avoid `global` and direct calls to `get_option()` outside of service constructors.
- Escape all output with `esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses_post()` as appropriate.
- Sanitize all inputs with `sanitize_text_field()`, `absint()`, etc., at the boundary.
- Use WordPress nonces for all form actions and AJAX endpoints.

---

## TypeScript Coding Standards

Worker code lives in `worker/src/` and targets the **Cloudflare Workers runtime**.

- TypeScript `strict` mode is enabled in `tsconfig.json`.
- ESLint with the `@typescript-eslint/recommended` ruleset.
- Prettier for formatting (config in `.prettierrc`).

```bash
# Lint
npm run lint

# Type-check
npm run typecheck

# Format
npm run format
```

### Key TypeScript Conventions

- Prefer `const` over `let`; avoid `var`.
- Use explicit return types on all exported functions.
- Handle `Response` errors explicitly — never swallow `catch` blocks silently.
- All secrets are read from `env` bindings, never hard-coded.

---

## Commit Message Format

This project uses **[Conventional Commits](https://www.conventionalcommits.org/en/v1.0.0/)**.

```
<type>(<scope>): <short summary>

[optional body]

[optional footer(s)]
```

### Types

| Type       | When to use                                          |
|------------|------------------------------------------------------|
| `feat`     | A new feature                                        |
| `fix`      | A bug fix                                            |
| `docs`     | Documentation-only changes                           |
| `style`    | Formatting; no logic change                          |
| `refactor` | Code restructuring without feature/fix               |
| `perf`     | Performance improvement                              |
| `test`     | Adding or fixing tests                               |
| `chore`    | Build process, dependency updates, CI configuration  |
| `security` | Security-related changes                             |

### Scopes

Common scopes: `plugin`, `worker`, `admin`, `cache-key`, `purge`, `preload`, `sandbox`, `docs`, `ci`.

### Examples

```
feat(purge): add batch tag purge endpoint with idempotency key
fix(cache-key): normalize query params before hashing
docs(architecture): add mermaid system diagram
security(worker): validate HMAC timestamp window to 300s
```

Breaking changes must include `BREAKING CHANGE:` in the footer.

---

## Pull Request Process

1. **Fork** the repository and create a branch from `main`.
   ```bash
   git checkout -b feat/my-feature main
   ```

2. **Write code and tests** that satisfy the requirements.

3. **Update documentation** — if your change affects behaviour described in `docs/`, update the relevant document.

4. **Run the full test suite** locally and ensure it passes (see [Testing Requirements](#testing-requirements)).

5. **Open a PR** against `main`. Fill in the PR template completely.

6. A maintainer will review within 5 business days. Address all requested changes.

7. Once approved and all CI checks pass, a maintainer will squash-merge the PR.

### PR Checklist

- [ ] Conventional commit messages
- [ ] PHPUnit tests added/updated
- [ ] Worker Vitest tests added/updated
- [ ] `composer lint` passes
- [ ] `npm run lint` passes
- [ ] `composer test` passes
- [ ] `npm test` passes
- [ ] Relevant docs updated

---

## Testing Requirements

### PHP (PHPUnit)

```bash
composer test
```

- Unit tests live in `tests/php/Unit/`.
- Integration tests live in `tests/php/Integration/` and use a real WordPress + database provided by the sandbox.
- New features require ≥ 80% branch coverage for the touched classes.

### Worker (Vitest)

```bash
npm test
```

- Tests use the [Cloudflare Workers Vitest pool](https://developers.cloudflare.com/workers/testing/vitest-integration/).
- Mock `env` bindings (KV, R2, D1) using Miniflare's in-memory implementations.

### Sandbox Benchmarks

To run the full sandbox benchmark suite (used by CI on `main`):

```bash
cd sandbox && ./run-benchmarks.sh
```

See [`docs/sandbox-benchmarking.md`](docs/sandbox-benchmarking.md) for the full benchmark design.

---

## Documentation

Documentation lives in `docs/`. When contributing a new feature:

- Update or create the relevant doc file.
- Keep diagrams in Mermaid format so they render on GitHub.
- Spell-check with `npm run docs:spellcheck` before opening a PR.

---

Thank you for contributing! 🎉
