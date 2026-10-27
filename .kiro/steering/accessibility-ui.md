# Accessibility & UI Copy

The app is used by volunteers of varying technical confidence, so interfaces must be clear and accessible. Keep these conventions when writing markup or user-facing text.

## Accessible markup

- Label every form control. Associate `<label for="id">` with its input's `id`. For visually-hidden labels, use the `sr-only` class rather than omitting the label.
- Give error summaries `role="alert"` and `tabindex="-1"` so they're announced and focusable, as the existing form error summaries do.
- Use `role="radiogroup"` with an `aria-label` for grouped radio options; use `aria-label` on map/widget containers that have no visible text.
- Mark purely decorative elements with `aria-hidden="true"` (e.g. preview tiles).
- Preserve visible focus styles — keep the existing focus outline treatment (e.g. the yellow `outline` on hover/focus) rather than removing outlines.
- Use semantic HTML: real `<button>`/`<a>` for actions, headings in order, lists for lists.
- Don't rely on colour alone to convey meaning; pair it with text or an icon.

## Writing UI copy

- Plain, friendly British English. Address the user directly ("Choose who to notify", "Enter an event title").
- Use the organisation's vocabulary from the domain glossary (leader, GLV, group, District Event) — not generic tech terms.
- Validation messages should say what to do, not just what's wrong: "Enter a valid start date and time", "Choose at least one person to notify, or change who gets emailed."
- Keep helper text ("form-text") short and specific; explain the consequence of a choice where it isn't obvious.
- Use British spellings (organise, colour, cancelled) consistently.

## Styling

- Match the existing `dc-`/`dce-`/`lt-` class naming and the CSS custom properties already in use (`--dc-border`, `--dc-ink`, `--dc-muted`, `--dc-canvas`) rather than hard-coding one-off colours.
- Any inline `<script>` must carry the CSP nonce (`<?= csp_nonce_attr() ?>`) — see the security steering.

> Note: these conventions support accessibility but are not a substitute for real validation. Full WCAG conformance requires manual testing with assistive technologies and expert review.
