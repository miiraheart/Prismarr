---
paths:
  - "symfony/**"
  - ".github/**"
  - "Makefile"
  - ".gitignore"
  - ".dockerignore"
---

# Upstream boundary

This is a fork that stays continuously current with upstream. `main` is a
pristine fast forward mirror of upstream and is never committed to. `audibox`
is the working branch.

That makes every file fall into one of three tiers, and the tier decides how
expensive it is to touch.

## Tier 1: fork added. Free to edit

These files do not exist upstream, so there is nothing to merge. Change them
freely.

```
.github/workflows/audibox-image.yml
.github/workflows/upstream-sync.yml
.github/workflows/parity.yml
symfony/tools/
symfony/public/img/services/trakt.svg
symfony/src/Controller/DiscoverController.php
symfony/src/Controller/DiscoverPageController.php
symfony/src/Controller/MediaRequestController.php
symfony/src/Controller/TraktController.php
symfony/src/Service/Media/Discover/
symfony/src/Service/Media/LibraryIndex.php
symfony/src/Service/Media/MdblistClient.php
symfony/src/Service/Media/TmdbEnricher.php
symfony/src/Service/Media/TraktClient.php
symfony/templates/discover/
symfony/templates/decouverte/_detail_modal.html.twig
symfony/tests/  (the fork added ones only, see the command below)
```

## Tier 2: upstream owned, already fork modified. Conflict candidates

Editing these is not forbidden. It is the normal cost of the fork. But each
edit adds to what has to be resolved at the next sync, so prefer the smallest
change that works, and prefer adding a new fork owned file over growing one of
these.

```
symfony/src/Controller/AdminSettingsController.php
symfony/src/Controller/DashboardController.php
symfony/src/Controller/HealthController.php
symfony/src/Service/HealthService.php
symfony/src/Service/Media/JellyseerrClient.php
symfony/src/Twig/ConfigExtension.php
symfony/templates/_icons.html.twig
symfony/templates/admin/settings.html.twig
symfony/templates/base.html.twig
symfony/templates/decouverte/index.html.twig
symfony/translations/messages+intl-icu.en.yaml
symfony/translations/messages+intl-icu.fr.yaml
symfony/public/img/services/ATTRIBUTION.md
```

Note that most of the sixteen registration sites live in this tier. Registering
a module is therefore always an upstream owned edit. That is expected and is
not a reason to skip it.

## Tier 3: byte identical to upstream. Do not edit

Appending to one of these manufactures a merge conflict for no benefit. If you
think you need to change one, stop and ask first.

```
.dockerignore
.github/workflows/ci.yml
```

`ci.yml` in particular: it triggers on `push: branches: [main]` and
`pull_request`. This fork never pushes `main` and never opens pull requests, so
it has never run. Do not edit it to fix that. Add a new fork owned workflow
instead.

`.gitignore` and `Makefile` used to be in this tier and were deliberately moved
out to carry the parity gate. They now have exactly one fork hunk each. Keep it
that way.

## Do not trust the lists above

They rot. Re-derive them before relying on them:

```
git diff --name-only --diff-filter=A main...HEAD   # tier 1, fork added
git diff --name-only --diff-filter=M main...HEAD   # tier 2, fork modified
git diff --quiet main HEAD -- <file> && echo identical   # tier 3 test
```

Three dots, not two. `main...HEAD` diffs against the merge base, which is what
you want here.
