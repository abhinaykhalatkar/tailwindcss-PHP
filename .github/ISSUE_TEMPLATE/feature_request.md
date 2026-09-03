---
name: Feature request
about: A Tailwind utility, variant or directive the PHP engine does not support yet
title: ''
labels: enhancement
assignees: ''
---

## What is missing

<Name the utility, variant or at-rule. For example: the `@custom-variant` directive,
the `supports-*` variant, or the `mask-*` utility family.>

- **Kind:** <utility / variant / directive>
- **Classes or syntax involved:**

```html
<div class="..."></div>
```

## What the official Tailwind CLI produces for it

Run the official standalone CLI over the same input and paste its output. This is the
specification we implement against, so a request with this section filled in can be
turned into a conformance case directly.

```css
```

<!--
  If you have the CLI driver working, `vendor/bin/tailwind-php diff` compares the PHP
  engine against it on your own project and will show exactly what is missing.
-->

## Use case

<What are you building, and what does the workaround look like today? Concrete cases
help us judge how a partial implementation would behave in practice.>

## Anything else

<Upstream Tailwind documentation links, the Tailwind version you are targeting, or
notes on how this should interact with existing variants.>
