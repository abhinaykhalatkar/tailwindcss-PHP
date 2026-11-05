# Security Policy

## Reporting a vulnerability

Please report security vulnerabilities **privately**, never as a public issue, pull
request or discussion.

Use GitHub's private vulnerability reporting:

1. Go to <https://github.com/abhinaykhalatkar/tailwindcss-PHP/security/advisories/new>.
2. Describe the issue, the affected version, and the host configuration (PHP version,
   compiler driver, whether `disable_functions` is set).
3. Include a minimal reproduction if you can — an input stylesheet, a template snippet,
   or a request sequence is usually enough.

There is no security contact e-mail. GitHub Security Advisories keeps the report
private until we publish it together, and gives us a place to prepare and test a fix
without disclosing it.

We aim to acknowledge a report within 7 days and to send a substantive assessment
within 30 days.

## Coordinated disclosure

We follow a **90-day coordinated disclosure** window. We will work with you on a fix
and publish an advisory as soon as one is available; if a fix is not ready after 90
days from the initial report, we will publish what we know at that point, crediting
you unless you ask us not to. We are happy to extend the window by agreement when a
fix is genuinely complex, and to shorten it when a vulnerability is already being
exploited.

## Supported versions

| Version | Supported | Notes |
|---------|-----------|-------|
| `main` | ✅ | Pre-release. The only branch receiving fixes today. |
| 1.x | ⏳ Upcoming | From 1.0.0 onwards, the latest 1.x patch release will receive security fixes. |

The package is not yet published on Packagist. Until 1.0.0 there are no tagged
releases to back-port to, so fixes land on `main`.

The library requires PHP 8.2 or newer. PHP 7.3 to 8.1 are not supported.

## What is in scope

Vulnerabilities in this library's own code, in particular:

- **Path confinement** — any way to make the library read or write a file outside the
  configured project root, cache directory or source directories, including via
  configuration values, the public route, or symlinks.
- **Cache artefacts** — anything that turns a cache artefact into code execution, lets
  an attacker influence the artefacts another request will serve, or exposes the cache
  directory's contents over HTTP.
- **CLI driver** — command or argument injection into the compiler invocation, and any
  path that would execute an unverified or tampered compiler binary.
- **HTTP responder** — header injection, response splitting, unsafe request parsing,
  incorrect cache-control or fingerprint handling that leads to serving the wrong
  bytes, and information disclosure through error output.
- **`install-cli`** — the download and checksum-verification path for the official
  standalone binary, including TLS handling and where the binary is written.

Denial of service through unbounded compilation work, and unsafe file permissions on
created directories, are also in scope.

## What is out of scope

- Vulnerabilities in PHP itself, in the web server, or in the operating system.
- The fact that a host runs an end-of-life PHP version. This library requires PHP 8.2
  or newer; a host running an unsupported PHP carries a risk this library cannot
  remove. Reports amounting to "PHP x.y is EOL" will be closed.
- Consumer misconfiguration — for example placing the cache directory inside the
  document root and then serving it directly, enabling debug mode in production, or
  granting the web server write access to the source tree. These are documented
  trade-offs; if the documentation is what misled you, that is a documentation bug and
  very welcome, but not a vulnerability in the library.
- Vulnerabilities in the official Tailwind CSS standalone binary. Report those to
  Tailwind Labs. Issues in *how we download or verify* that binary are in scope.
- Findings from automated scanners with no demonstrated impact on this library.

## Our process

Every change touching path handling, the cache, the CLI driver, the HTTP layer, the
scanner or `install-cli` carries a security checklist in its pull request and is
reviewed against the project's threat model. Each threat has at least one test in the
`Security` test suite. Every release runs `composer audit`, the static security
checks, and a manual pass over the threat table.
