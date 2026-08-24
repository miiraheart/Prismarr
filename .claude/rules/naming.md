---
paths:
  - "symfony/src/**"
  - "symfony/templates/**"
  - "symfony/translations/**"
  - "symfony/config/**"
  - "symfony/tests/**"
---

# Naming conventions

These are observed conventions, not derivable ones. Getting one wrong produces
a route that does not exist or a config key nothing reads, and both fail at
runtime rather than at lint time.

## Route prefixes split with NO rule

There is no pattern. Learn the two lists.

Without an `app_` prefix:
`radarr_`, `sonarr_`, `prowlarr_`, `jellyseerr_`, `trakt_`, `tmdb_`,
`admin_settings_`, `admin_instances_`.

With an `app_` prefix:
`app_media_`, `app_qbittorrent_`, `app_deluge_`, `app_transmission_`,
`app_usenet_`, `app_tautulli_`, `app_dashboard`, `app_calendrier`, `app_home`,
`app_login`, `app_logout`, `app_profile`, `app_setup_*`.

**Never derive a route prefix.** Copy it from the nearest sibling and confirm
with `debug:router`. Confirming needs the container:
`make console debug:router`.

## Language split

URL segments are French. Class names, method names, route names and variable
names are English.

French URL segments in use: `/tableau-de-bord`, `/decouverte`, `/medias`,
`/calendrier`, `/profil`, `/mises-a-jour`, `/sauvegardes`, `/manquants`,
`/historique`.

## Config keys

- `<service>_url`
- `<service>_api_key`
- `<service>_user`
- `<service>_password`
- `<service>_enabled`, where `'0'` means off and an absent key means on
- `sidebar_hide_<service>`
- `display_<thing>`

The `'0'` means off convention matters: testing for the key's presence gives the
wrong answer for a service that has never been touched.

## Templates

Line 1 extends `base.html.twig`. Line 2 imports `_icons.html.twig` as `ico`.

Blocks appear in this order: `title`, `head_meta`, `stylesheets`, `page_title`,
`body`, `javascripts`.

## Translations

Both locale files are edited together, always. A key present in one locale only
is a defect, not a partial translation.

- `symfony/translations/messages+intl-icu.en.yaml`
- `symfony/translations/messages+intl-icu.fr.yaml`

## Tests

Tests mirror the `src` namespace. Web tests extend
`App\Tests\AbstractWebTestCase`.
