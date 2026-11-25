<?php

declare(strict_types=1);

/**
 * Registration sites a module is legitimately absent from.
 * Generated once from the tree, then shrunk by hand as gaps close.
 * Never add an entry to silence a NEW module: that is the drift this gate exists to catch.
 *
 * Two kinds of entry live here and they are not the same thing:
 *
 *   EXEMPT  the module genuinely has no business at that site and never will.
 *   BACKLOG the registration is simply missing. It is recorded so the gate can
 *           go green today, and it is meant to be deleted, not kept.
 *
 * Every line below says which it is. A line with no reason is a bug.
 *
 * Standing caveat on the health_cache column: ServiceHealthCache is
 * slug-agnostic and takes the service as a parameter, so no module is ever
 * really registered there. The five modules that pass it only match a @param
 * docblock example. Treat every health_cache entry as EXEMPT and that column
 * as carrying no signal.
 */

return [
    // deluge: only the no-signal health_cache column remains.
    'deluge' => ['health_cache'],

    // gluetun: EXEMPT across the board. A VPN status probe with no page, no
    // route and no sidebar entry; its health surfaces on the qBittorrent page.
    'gluetun' => ['controller', 'templates', 'health_api', 'health_cache', 'health_widget', 'route_guard', 'sidebar', 'smoke'],

    // mdblist: controller, templates, route_guard and smoke are EXEMPT. It is a
    // list source behind the discover page with no routes and no page of its
    // own, so there is no route prefix to guard and no route to smoke-test
    // (DiscoverPageControllerTest covers the page it feeds). sidebar is EXEMPT:
    // no sidebar entry of its own, and since upstream 1.3.0 the topbar health
    // labels come from HealthCatalog instead of base.html.twig.
    'mdblist' => ['controller', 'templates', 'health_cache', 'route_guard', 'sidebar', 'smoke'],

    // nzbget and sabnzbd: controller, client, templates and route_guard are all
    // EXEMPT. Both are served by UsenetController from templates/usenet/ and
    // src/Service/Media/Usenet/, and they share the single app_usenet_ route
    // prefix with a {client} parameter, which the guard's one-prefix-one-service
    // rule shape cannot express.
    'nzbget' => ['controller', 'client', 'templates', 'health_cache', 'route_guard'],
    'sabnzbd' => ['controller', 'client', 'templates', 'health_cache', 'route_guard'],

    // tautulli: only the no-signal health_cache column remains.
    'tautulli' => ['health_cache'],

    // tmdb: templates is EXEMPT. TMDB is a metadata source rendered inside other
    // modules' pages and owns no template directory.
    'tmdb' => ['templates', 'health_cache'],

    // trakt: templates is EXEMPT since the discover rework, which moved the trakt
    // page under templates/discover/. sidebar is EXEMPT for the same reason as
    // mdblist: the discover rework folded it into one sidebar entry, and the
    // topbar health labels now come from HealthCatalog.
    'trakt' => ['templates', 'health_cache', 'sidebar'],

    // transmission: only the no-signal health_cache column remains.
    'transmission' => ['health_cache'],

    // radarr and sonarr: health_api is EXEMPT. They are reported per instance,
    // and HealthCatalog (which the health API iterates) deliberately lists only
    // the single-instance services.
    'radarr' => ['health_api'],
    'sonarr' => ['health_api'],

    // bazarr, tracearr and emby arrived with upstream 1.3.0. health_cache is the
    // no-signal column. smoke is EXEMPT: each has its own upstream controller
    // test (BazarrControllerTest, TracearrControllerTest, EmbyControllerTest).
    'bazarr' => ['health_cache', 'smoke'],
    'tracearr' => ['health_cache', 'smoke'],

    // emby: also templates, route_guard and sidebar are EXEMPT. Upstream ships it
    // as the API behind the dashboard "now playing" widget, with no page, no
    // sidebar entry and no guarded page route.
    'emby' => ['templates', 'health_cache', 'route_guard', 'sidebar', 'smoke'],
];
