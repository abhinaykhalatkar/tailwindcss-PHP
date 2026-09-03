---
name: Bug report
about: Something compiles wrongly, is served wrongly, or crashes
title: ''
labels: bug
assignees: ''
---

<!--
  Please do NOT report security vulnerabilities here — use private reporting via
  GitHub Security Advisories instead. See SECURITY.md.
-->

## What happened

<One or two sentences. What did you expect instead?>

## Environment

- **PHP version:** <e.g. 7.4.33>
- **Library version:** <tag, or `dev-main` plus the commit>
- **Compiler driver:** <`auto`, `php` or `cli` — the value in your config>
- **Driver actually used:** <what `doctor` reports; often differs from `auto`>
- **Host type:** <shared hosting / VPS / Docker / local built-in server — name the
  provider if it is shared hosting>
- **`disable_functions`:** <paste the ini value, or "empty">
- **Web server:** <Apache + mod_rewrite / nginx / built-in>

## `doctor` output

```
<paste the full output of `vendor/bin/tailwind-php doctor` here>
```

## Minimal reproduction

Input stylesheet (`assets/styles/main.css` or your entry file):

```css
@import "tailwindcss";
```

Template snippet containing the classes involved:

```html
<div class="..."></div>
```

Relevant configuration (redact anything private):

```php
return [
    // ...
];
```

## Expected CSS

```css
```

## Actual CSS

```css
```

<!--
  If the `cli` driver produces the right CSS and the `php` engine does not, say so —
  that is a conformance bug and the most useful kind of report we get.
-->
