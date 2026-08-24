---
paths:
  - "symfony/templates/**"
  - "symfony/src/Controller/**"
  - "symfony/src/Service/**"
  - "symfony/src/EventSubscriber/**"
  - "symfony/src/Twig/**"
  - "symfony/src/Dashboard/**"
  - "symfony/config/**"
  - "symfony/translations/**"
  - "symfony/tests/**"
---

# Module parity

Integration modules are duplicated on purpose. Editing an upstream owned file
costs more at every sync than carrying the same code twice, so a fix applied to
one module does not reach its siblings by itself.

Modules discovered at the time this file was written: deluge, gluetun,
jellyseerr, mdblist, nzbget, prowlarr, qbittorrent, radarr, sabnzbd, sonarr,
tautulli, tmdb, trakt, transmission.

Do not trust that list. Run the checker, which discovers it from the registries
themselves and will disagree with this file the moment one of them changes.

## Before editing

Name the sibling files that carry the same role. For a template, that is the
same leaf filename under the other module directories. For a controller or a
`Service/Media/*Client.php`, it is the same shaped class for each sibling.

## Before saying the work is done

```
make parity
```

Every line it prints is a registration you still owe or a deliberate exception.
Say which it is. Do not leave it unsaid. If it is genuinely deliberate, record
it in `symfony/tools/parity-waivers.php` with a comment saying why, and expect
that decision to be reviewed.

The waiver file separates EXEMPT from BACKLOG. An exempt module has no business
at that site and never will. A backlog entry is simply missing and is meant to
be deleted, not kept. Never add a waiver to silence a new module: that is the
exact drift this gate exists to catch.

## Where a module id has to be registered

A module is a lowercase service id used as a quoted string, an identifier
prefix, or a bare map key across the tree.

- `symfony/src/Controller/<Module>Controller.php`
- `symfony/src/Service/Media/<Module>Client.php`
- `symfony/templates/<module>/`
- `symfony/src/Service/HealthService.php` (ping map, configured map,
  `TOGGLEABLE_SERVICES`, diagnostic hints)
- `symfony/src/Controller/HealthController.php` (`FLAT_SERVICES`)
- `symfony/src/Service/Media/ServiceHealthCache.php`
- `symfony/templates/dashboard/_health.html.twig` (`service_colors`)
- `symfony/src/Controller/AdminSettingsController.php` (field maps, `$allowed`)
- `symfony/templates/admin/settings.html.twig` (icon map, group map, field map)
- `symfony/src/Controller/SetupController.php` (setup field map)
- `symfony/src/Twig/ConfigExtension.php` (`SERVICE_KEYS` or `INSTANCE_TYPES`)
- `symfony/src/EventSubscriber/ServiceRouteGuardSubscriber.php`
- `symfony/templates/base.html.twig` (sidebar entry)
- `symfony/translations/messages+intl-icu.en.yaml` and `.fr.yaml`
- `symfony/tests/Controller/ControllersSmokeTest.php` (`routesProvider`)

The list drifts. `symfony/tools/parity-check.php` is the source of truth.
Update this file when the checker surfaces a place that is missing here.

`HealthService.php` repeats the module list several times inside one file: the
constructor injection list, the type map, the ping match, `TOGGLEABLE_SERVICES`,
the configured check, the cache invalidation list and the probe map. The checker
tests the file, not each of those lists, so passing the `health` site does not
prove all of them agree. Read them.

`base.html.twig` likewise holds several registries: the nav markup, the badge
ids, the poller javascript and the topbar health javascript. Same caveat.

## Known weak spot in the checker

The `health_cache` column carries no signal. `ServiceHealthCache` is
slug-agnostic and takes the service as a parameter, so nothing is ever really
registered there. The handful of modules that pass it only match an example in
a docblock. Do not read that column as evidence of anything.

## Non negotiables

- Never commit to `main`. It is a fast forward mirror of upstream.
- Never `git add -A`, `git add .`, or `git commit -a`. Stage explicit paths.
- Never bypass the pre-commit guard.
- This repository is public. No hostnames, IP addresses, server paths, API
  keys, or passwords in any committed file, including examples.
- No em dashes or en dashes. Conventional commits, imperative, 50 characters.
