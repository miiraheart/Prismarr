# Prismarr

Symfony 8 / PHP 8.4 / Twig app. Two kinds of command, and they do not run in
the same place. Getting this wrong does not fail loudly, it produces a wrong
answer, so read both.

**App commands run in the container.** Anything that needs the kernel, the
database, the vendor tree or the app config: `bin/console`, `phpunit`,
`composer`, `lint:twig`, `lint:container`. There is no host PHP for the app, so
these go through `docker exec` (or the `pm-test` / `pm-check` wrappers, which
rsync to the server and run there).

**`symfony/tools/parity-check.php` runs on the HOST.** It is zero-dependency
pure PHP, it boots nothing, and host PHP 8.4.7 runs it fine:

```
make parity          # or: php symfony/tools/parity-check.php
```

Run it **from the git working tree, never on the server and never over
rsync'd files.** rsync copies empty directories and git cannot track them, so
running the checker on an rsync'd copy invents registrations that do not exist
in the repository, and the gate then disagrees with CI. That is not
hypothetical: `templates/trakt/` was left behind empty by a `git mv`, rsync
carried it to the server, and the checker reported trakt as registered in
`templates` against a waiver that was correctly claiming the opposite.

The general form of that trap: **any predicate that reads the filesystem
instead of git will drift from what is actually committed.** It is the one
class of bug this gate's design is structurally exposed to, so a new site
predicate should be judged against it before being added.

## Topology, read this before assuming anything

**The app does not run on the machine you are editing on. It runs on a remote
server.** There is no local instance to click around in unless someone has
deliberately started one.

A push to `audibox` builds a container image. A cron job on the server pulls the
rolling tag and redeploys, on the order of fifteen minutes later.

**Push equals deploy. There is no staging environment.** Treat every push to
`audibox` as a production release.

Consequences you have to plan around:

- `make parity` is the only Makefile target that runs without the container.
  Every other member of `make check` shells into `docker exec prismarr ...`, so
  running them needs Docker up plus `make dev` first.
- CI is the only place a full `make check` can ever execute, and it starts
  after the image build has already begun. **CI detects, it does not prevent.**
- The thing that actually prevents a broken push is the local `pre-push` hook,
  and that lives in `.git/hooks/`, so it is per machine and is not in this
  repository. A fresh clone does not have it.

## Commands

| Task | Command |
| --- | --- |
| Start dev | `make dev` |
| Full gate before commit | `make check` |
| Module registration parity | `make parity` |
| Parity grid | `make parity-matrix` |
| PHP syntax lint | `make lint` |
| Twig lint | `make lint-twig` |
| Container and YAML lint | `make lint-container` |
| Test suite | `make test` |
| Symfony console | `make console <command>` |
| Container logs | `make logs` |

`make check` runs lint, Twig lint, parity and the test suite. It is the
definition of done.

## Layout

- `symfony/src/Controller/<Module>Controller.php` one per integration
- `symfony/src/Service/Media/<Module>Client.php` HTTP client per integration
- `symfony/templates/<module>/` one template directory per integration
- `symfony/src/Service/HealthService.php` central health and configuration state
- `symfony/src/Twig/ConfigExtension.php` service visibility for templates
- `symfony/translations/messages+intl-icu.{en,fr}.yaml` all user facing strings
- `symfony/tools/parity-check.php` the registration gate

## The one thing to know

Integration modules are near duplicates of each other **by design**. This fork
stays continuously current with upstream, so editing an upstream owned file is
expensive at every sync and duplicating logic is cheap.

The cost of that choice: a fix applied to one module does not reach its
siblings, and a new module has to be registered by hand in sixteen places.

Before calling any module change done, run `make parity` and account for every
line it prints. `.claude/rules/module-parity.md` carries the full registration
surface and loads automatically when you open a file in the module tree.

## Branches

- `main` is a fast forward mirror of upstream. Never commit to it.
- `audibox` is the working branch and the repository default. All work goes here.

## Rules

- Stage explicit paths. Never `git add -A`, `git add .`, or `git commit -a`.
- Never bypass the pre-commit guard. If it fires, the content is wrong.
- This repository is **public**. Never commit hostnames, IP addresses, server
  filesystem paths, API keys, tokens, or passwords, including inside examples
  and comments. Use `https://example.com`, `<API_KEY>`, `/path/to/config`.
- No em dashes or en dashes in any file.
- Conventional commits: `type: description`, imperative, lowercase after the
  prefix, no trailing period, 50 characters maximum.
- Match the conventions already present in the file being edited. Prefer the
  smallest change that achieves the goal.
