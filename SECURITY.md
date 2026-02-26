# Security Policy

## Supported Versions

Only the latest stable release and the current development branch (`main`) receive security patches. Please upgrade promptly when new releases are published.

| Version       | Supported          |
| ------------- | ------------------ |
| `main` branch | ✅ Active          |
| Latest release | ✅ Active          |
| Prior releases | ❌ End of Life     |

## Reporting a Vulnerability

**Please do not open a public GitHub issue for security vulnerabilities.**

Report security issues by emailing **security@example.com** <!-- TODO: replace with the project's actual security contact email --> with the subject line:

```
[SECURITY] AI WP Dynamic Cache Plugin – <brief description>
```

Include the following in your report:

- **Description**: A clear description of the vulnerability and its potential impact.
- **Affected component**: Plugin (PHP), Cloudflare Worker (TypeScript), Admin UI, Signed Agent, or other.
- **Steps to reproduce**: A minimal, reliable reproduction case.
- **Affected versions**: The version(s) you tested against.
- **Suggested fix** *(optional)*: Any ideas you have about how to address the issue.

If the report involves credentials, keys, or other sensitive material, please use our PGP key (available at `https://example.com/.well-known/security.txt` <!-- TODO: replace with the actual domain -->) to encrypt the message.

## Response Timeline

| Stage              | Target SLA                              |
| ------------------ | --------------------------------------- |
| **Acknowledgement** | Within **48 hours** of receipt          |
| **Triage & severity assessment** | Within **7 days**      |
| **Patch & release** | Within **30 days** for Critical/High; **90 days** for Medium/Low |
| **Public disclosure** | Coordinated after patch is available |

We follow a coordinated disclosure model. We will credit reporters in the release notes and changelog unless you request anonymity.

## Threat Model

A full threat model for this project, covering trust boundaries, HMAC request signing, replay attack prevention, secrets management, and more, is maintained at:

📄 [`docs/threat-model.md`](docs/threat-model.md)

Review that document to understand the security assumptions, trust boundaries, and known risk areas of the system before conducting security research.

## Scope

The following are **in scope** for vulnerability reports:

- Authentication bypass or privilege escalation in the plugin or worker.
- HMAC/signature forgery allowing unauthorized cache purge or preload.
- Cache poisoning attacks via header injection or key manipulation.
- XSS or CSRF vulnerabilities in the WordPress admin UI.
- Injection vulnerabilities in Cloudflare D1 or KV interactions.
- Replay attacks against the signed agent API.
- Information disclosure of cached content that should bypass the cache (e.g., logged-in user pages, WooCommerce checkout).
- Secrets exposure (WordPress options, Cloudflare API tokens, signing keys).

The following are **out of scope**:

- Vulnerabilities in WordPress core, Cloudflare platform, or third-party dependencies that are not introduced by this plugin.
- Denial of service issues that require access to the origin server.
- Issues only reproducible with `WP_DEBUG` enabled in a development environment.
- Social engineering of maintainers or contributors.

## Security Updates

Security advisories are published via [GitHub Security Advisories](https://github.com/example/AI_WP_Dynamic_Cache_Plugin/security/advisories). <!-- TODO: replace `example/AI_WP_Dynamic_Cache_Plugin` with the actual repository path --> Subscribe to repository notifications to receive alerts.
