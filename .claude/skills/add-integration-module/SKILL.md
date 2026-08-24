---
name: add-integration-module
description: Add, rename, or audit a Prismarr integration module (radarr, sonarr, prowlarr, trakt, mdblist, deluge, qbittorrent, transmission, sabnzbd, nzbget, jellyseerr, tautulli, tmdb and siblings). Walks every cross-cutting registration site and proves coverage with the parity checker before the work can be called done. Use when adding a new service integration, renaming one, or when a change to one module has to be mirrored onto its siblings.
argument-hint: "[module-id]"
arguments: module
paths:
  - "symfony/**"
allowed-tools: Bash(php symfony/tools/parity-check.php *) Bash(make parity) Bash(make check) Bash(ls *) Read Glob Grep Edit Write
---

## Live state

Registration grid right now:

```!
php "${CLAUDE_PROJECT_DIR}/symfony/tools/parity-check.php" --matrix || true
```

## Your task

Work on module `$module`.

1. Read `.claude/rules/module-parity.md` for the registration surface, and
   `.claude/rules/naming.md` before you invent any route prefix or config key.
2. Pick the most complete sibling from the grid above (`prowlarr`,
   `jellyseerr` and `qbittorrent` are fully registered today) and use it as the
   reference implementation.
3. For every site where `$module` shows MISSING, open the file, find how the
   reference module is registered, and register `$module` the same way. If the
   absence is genuinely correct, add it to `symfony/tools/parity-waivers.php`
   with a comment saying why it is EXEMPT rather than BACKLOG, and say so in
   your final message.
4. Templates: for each template under `symfony/templates/$module/`, check
   whether the same leaf filename exists under the sibling modules and match
   its structure. Copy rather than abstract: this fork prefers duplication over
   editing upstream owned files.
5. Translations: add every key to both
   `symfony/translations/messages+intl-icu.en.yaml` and `.fr.yaml`. A key added
   to one locale only is a defect.
6. Run `make parity` and paste the output. Do not claim completion while it
   reports a gap you have not explained.
7. Run `make check`. It needs the container. If the container is not running,
   say so plainly rather than reporting a pass you did not get.

## Constraints

- Never touch `main`. Work on `audibox`.
- Stage explicit paths only.
- A push to `audibox` deploys. Do not push a half registered module.
- This repository is public: no hostnames, IP addresses, server paths, keys, or
  passwords in any file you write, including examples. Use
  `https://example.com` and `<API_KEY>`.
- No em dashes or en dashes.
