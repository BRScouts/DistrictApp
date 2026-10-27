---
inclusion: manual
name: git-workflow
description: Conventions for staging, committing, and pushing changes to GitHub. Pull this in whenever committing or pushing.
---

# Git & GitHub Workflow

Rules for turning working changes into clear, reviewable commits and pushes. Follow these whenever I ask to commit or push.

## Before committing

1. Run `git status` and `git diff` (staged and unstaged) to see exactly what changed. Never commit blind.
2. Summarise what actually changed back to me in one or two sentences before writing the commit message.
3. Stage files explicitly by name (`git add path/to/file`). Do not use `git add .` or `git add -A` so unrelated changes aren't swept in.
4. Flag any file that looks like it holds secrets before staging it: `.env`, `config.php`, credential/token files, anything with API keys. Never commit these unless I confirm.
5. Run the test suite before committing when code changed: `composer test` (PHPUnit). Mention the result.

## Commit message format

Use Conventional Commits. The message must describe *what changed and why*, never placeholders like "changes", "updates", "fix", or random characters.

```
<type>(<scope>): <short summary in imperative mood, <= 72 chars>

<body: what changed and why, wrapped at ~72 cols>

<optional footer: refs #issue, BREAKING CHANGE: ...>
```

**Types:**
- `feat` — a new feature or capability
- `fix` — a bug fix
- `refactor` — code change that neither fixes a bug nor adds a feature
- `perf` — performance improvement
- `style` — formatting only, no logic change
- `docs` — documentation only
- `test` — adding or fixing tests
- `chore` — build, deps, config, tooling, cron scripts
- `security` — security-related fix or hardening

**Scope** is the area of the app touched, e.g. `dc`, `cron`, `auth`, `comms`, `db`, `help`, `deps`. Keep it short and lowercase. Omit if the change is truly global.

**Summary line:** imperative mood ("add", "fix", "remove" — not "added"/"fixes"), no trailing period, lowercase after the colon.

**Body:** explain the motivation and the effect of the change, not a restatement of the diff. Include it for anything non-trivial. Use bullet points when several distinct things changed.

### Good examples

```
feat(dc): add district event creation with risk assessment upload

Lets district coordinators create multi-section events and attach a
risk assessment PDF. Adds the add-district-event handler plus the
004_district_events migration.
```

```
fix(cron): stop reminder job double-sending on queue retry

Reminders-and-clense re-queued rows that were already sent because the
status check ran before the transaction committed. Move the status
update inside the transaction.
```

```
chore(deps): bump phpmailer to ^7.1
```

### Bad examples (do not do this)

```
changes
updates
fix
sdsd
```

## Pushing

1. Confirm the current branch with `git branch --show-current`.
2. Never push directly to `main`/`master` unless I explicitly ask. Default to a feature branch named `type/short-description` (e.g. `feat/district-events`), and push with `git push -u origin <branch>`.
3. If I ask for a pull request, create it with the GitHub CLI (`gh pr create`). Keep the title under 70 chars; put detail in the body.
4. PR description structure:
   - **Summary** — what changed and why
   - **Changes** — bullet list of the notable edits
   - **Testing** — what was run (e.g. `composer test`) and the result
   - **Notes** — anything blocked, deferred, or needing follow-up

## Safety

- Don't amend or force-push pushed commits. Prefer new commits.
- Don't skip hooks (`--no-verify`) unless I ask.
- Don't change git config.
- If a pre-commit hook fails, fix the issue, re-stage, and make a new commit — don't amend.
