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
    // deluge: health_widget is BACKLOG. The dashboard widget's chip list does
    // not include deluge at all, so this is a widget gap, not a color gap.
    'deluge' => ['health_cache', 'health_widget'],

    // gluetun: EXEMPT across the board. A VPN status probe with no page, no route
    // and no sidebar entry. Only 'health' is BACKLOG: with no arm in
    // HealthService::pingFor it falls through to a default that always reports healthy.
    'gluetun' => ['controller', 'templates', 'health', 'health_api', 'health_cache', 'health_widget', 'route_guard', 'sidebar', 'smoke'],

    // mdblist: controller and templates are EXEMPT. It is a list source behind the
    // discover page, served by DiscoverController, so it owns no page of its own.
    // health_widget, setup, route_guard and smoke are BACKLOG.
    'mdblist' => ['controller', 'templates', 'health_cache', 'health_widget', 'setup', 'route_guard', 'smoke'],

    // nzbget and sabnzbd: controller, client and templates are EXEMPT. Both are
    // served by UsenetController from templates/usenet/ and
    // src/Service/Media/Usenet/. health_widget and route_guard are BACKLOG.
    'nzbget' => ['controller', 'client', 'templates', 'health_cache', 'health_widget', 'route_guard'],
    'sabnzbd' => ['controller', 'client', 'templates', 'health_cache', 'health_widget', 'route_guard'],

    // tautulli: setup and smoke are BACKLOG.
    'tautulli' => ['health_cache', 'setup', 'smoke'],

    // tmdb: templates is EXEMPT. TMDB is a metadata source rendered inside other
    // modules' pages and owns no template directory.
    'tmdb' => ['templates', 'health_cache'],

    // trakt: templates is EXEMPT since the discover rework, which moved the trakt
    // page under templates/discover/. setup, route_guard and smoke are BACKLOG
    // and are the current work's real gap list.
    'trakt' => ['templates', 'health_cache', 'setup', 'route_guard', 'smoke'],

    // transmission: health_widget is BACKLOG.
    'transmission' => ['health_cache', 'health_widget'],
];
