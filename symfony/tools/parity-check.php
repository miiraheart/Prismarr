<?php

/**
 * Cross-cutting registration parity gate.
 *
 * The module list is DISCOVERED from independent registries, never declared
 * by hand: forgetting to add a module to one registry makes the union larger
 * than that registry and the run fails there. Every discovered
 * module must then appear in every registration site listed below, unless the
 * absence is recorded in parity-waivers.php. A waiver that no longer applies
 * also fails, so the waiver file cannot rot.
 *
 * Plain PHP, no autoload, no kernel boot: runs on the host, in the container
 * and in a git hook alike. Exit 0 = every module is registered everywhere.
 */

declare(strict_types=1);

$root = getenv('PARITY_ROOT') ?: dirname(__DIR__);

$read = static fn (string $rel): ?string
    => is_file($root . '/' . $rel) ? (string) file_get_contents($root . '/' . $rel) : null;

$listFromArray = static function (?string $body, string $regex): array {
    if ($body === null || !preg_match($regex, $body, $m)) {
        return [];
    }
    preg_match_all("/'([a-z0-9_]+)'/", $m['body'], $keys);

    return array_values(array_unique($keys[1]));
};

$listFromMapKeys = static function (?string $body, string $regex): array {
    if ($body === null || !preg_match($regex, $body, $m)) {
        return [];
    }
    preg_match_all("/'([a-z0-9_]+)'\s*=>/", $m['body'], $keys);

    return array_values(array_unique($keys[1]));
};

$health = $read('src/Service/HealthService.php');
$twig   = $read('src/Twig/ConfigExtension.php');
$admin  = $read('src/Controller/AdminSettingsController.php');

/**
 * One entry per discovery pattern, never a merge of two. A merged registry
 * stays non-empty when one half's regex rots, which silences the emptiness
 * guard below and lets the run pass while discovering a short list.
 */
$registries = [
    'HealthService::TOGGLEABLE_SERVICES' =>
        $listFromArray($health, '/TOGGLEABLE_SERVICES\s*=\s*\[(?P<body>[^\]]*)\]/'),
    'ConfigExtension::SERVICE_KEYS' =>
        $listFromMapKeys($twig, '/SERVICE_KEYS\s*=\s*\[(?P<body>.*?)\n    \];/s'),
    'ConfigExtension::INSTANCE_TYPES' =>
        $listFromMapKeys($twig, '/INSTANCE_TYPES\s*=\s*\[(?P<body>.*?)\n    \];/s'),
    'AdminSettingsController::$allowed' =>
        $listFromArray($admin, '/\$allowed\s*=\s*\[(?P<body>[^\]]*)\]/'),
    'Service/Media/*Client.php' => (static function () use ($root): array {
        // Two explicit globs rather than GLOB_BRACE, which is only defined on
        // platforms whose libc provides it and would fatal where it is not.
        $files = array_merge(
            glob($root . '/src/Service/Media/*Client.php') ?: [],
            glob($root . '/src/Service/Media/*/*Client.php') ?: [],
        );

        $out = [];
        foreach ($files as $file) {
            $out[] = strtolower(basename($file, 'Client.php'));
        }

        return array_values(array_unique($out));
    })(),
];

$registryFailures = [];

foreach ($registries as $name => $set) {
    if ($set === []) {
        $registryFailures[] = sprintf('registry "%s": pattern matched nothing. The file moved or was reshaped; fix this script before trusting the run.', $name);
    }
}

$failures = $registryFailures;

/**
 * radarr and sonarr are multi-instance and are deliberately absent from
 * TOGGLEABLE_SERVICES. They are added after the guard above so that they can
 * never mask a registry whose pattern stopped matching.
 */
$modules = ['radarr', 'sonarr'];
foreach ($registries as $set) {
    $modules = array_merge($modules, $set);
}
$modules = array_values(array_unique($modules));
sort($modules);

/**
 * Registration sites. `needle` files are searched for the module token:
 * a quoted 'slug', an identifier prefix slug_, or a bare YAML/Twig key slug:.
 * `file` sites test path existence. `route` sites accept a controller class
 * name or a URL path containing the slug.
 */
$sites = [
    'controller'    => ['kind' => 'file',   'path' => 'src/Controller/{Class}Controller.php'],
    'client'        => ['kind' => 'file',   'path' => 'src/Service/Media/{Class}Client.php'],
    'templates'     => ['kind' => 'dir',    'path' => 'templates/{slug}'],
    'health'        => ['kind' => 'needle', 'path' => 'src/Service/HealthService.php'],
    'health_api'    => ['kind' => 'needle', 'path' => 'src/Controller/HealthController.php'],
    'health_cache'  => ['kind' => 'needle', 'path' => 'src/Service/Media/ServiceHealthCache.php'],
    'health_widget' => ['kind' => 'needle', 'path' => 'templates/dashboard/_health.html.twig'],
    'admin_ctrl'    => ['kind' => 'needle', 'path' => 'src/Controller/AdminSettingsController.php'],
    'admin_page'    => ['kind' => 'needle', 'path' => 'templates/admin/settings.html.twig'],
    'setup'         => ['kind' => 'needle', 'path' => 'src/Controller/SetupController.php'],
    'twig_config'   => ['kind' => 'needle', 'path' => 'src/Twig/ConfigExtension.php'],
    'route_guard'   => ['kind' => 'needle', 'path' => 'src/EventSubscriber/ServiceRouteGuardSubscriber.php'],
    'sidebar'       => ['kind' => 'needle', 'path' => 'templates/base.html.twig'],
    'trans_en'      => ['kind' => 'needle', 'path' => 'translations/messages+intl-icu.en.yaml'],
    'trans_fr'      => ['kind' => 'needle', 'path' => 'translations/messages+intl-icu.fr.yaml'],
    'smoke'         => ['kind' => 'route',  'path' => 'tests/Controller/ControllersSmokeTest.php'],
];

$waiverFile = $root . '/tools/parity-waivers.php';
$waivers = is_file($waiverFile) ? (array) require $waiverFile : [];

$classOf = static fn (string $slug): string => match ($slug) {
    'qbittorrent' => 'QBittorrent',
    default       => ucfirst($slug),
};

$present = static function (string $slug, array $site) use ($root, $classOf): bool {
    $path = strtr($site['path'], ['{slug}' => $slug, '{Class}' => $classOf($slug)]);
    $full = $root . '/' . $path;

    if ($site['kind'] === 'file') {
        return is_file($full);
    }
    if ($site['kind'] === 'dir') {
        return is_dir($full);
    }
    if (!is_file($full)) {
        return false;
    }

    $body = (string) file_get_contents($full);
    $q = preg_quote($slug, '/');

    if ($site['kind'] === 'route') {
        return (bool) preg_match("/{$q}Controller::|(['\"])[\/a-z0-9\-]*{$q}[\/a-z0-9\-]*\\1/i", $body);
    }

    return (bool) preg_match("/(['\"])$q\\1|\\b{$q}_[a-z]|^[ \\t]*$q:/mi", $body);
};

$matrix = [];

foreach ($modules as $slug) {
    foreach ($sites as $id => $site) {
        $ok = $present($slug, $site);
        $waived = in_array($id, $waivers[$slug] ?? [], true);
        $matrix[$slug][$id] = $ok;

        if (!$ok && !$waived) {
            $failures[] = sprintf(
                'module "%s" is not registered in %s (site "%s")',
                $slug,
                strtr($site['path'], ['{slug}' => $slug, '{Class}' => $classOf($slug)]),
                $id,
            );
        }

        if ($ok && $waived) {
            $failures[] = sprintf(
                'stale waiver: "%s" IS now registered in site "%s". Remove it from tools/parity-waivers.php.',
                $slug,
                $id,
            );
        }
    }
}

foreach ($waivers as $slug => $ids) {
    if (!in_array($slug, $modules, true)) {
        $failures[] = sprintf('stale waiver: "%s" is not a discovered module. Remove it from tools/parity-waivers.php.', $slug);
    }
}

if (in_array('--matrix', $argv, true)) {
    printf("%-14s", 'module');
    foreach (array_keys($sites) as $id) {
        printf('%-15s', $id);
    }
    echo "\n";
    foreach ($matrix as $slug => $row) {
        printf('%-14s', $slug);
        foreach ($row as $id => $ok) {
            printf('%-15s', $ok ? 'ok' : (in_array($id, $waivers[$slug] ?? [], true) ? 'waived' : 'MISSING'));
        }
        echo "\n";
    }
    echo "\n";
}

if (in_array('--generate-waivers', $argv, true)) {
    if ($registryFailures !== []) {
        fwrite(STDERR, "Refusing to generate waivers: registry discovery is broken.\n");
        foreach ($registryFailures as $failure) {
            fwrite(STDERR, '  - ' . $failure . "\n");
        }
        fwrite(STDERR, "Generating now would record the whole tree as a legitimate absence.\n");
        exit(1);
    }

    if (is_file($waiverFile) && !in_array('--force', $argv, true)) {
        fwrite(STDERR, "Refusing to overwrite tools/parity-waivers.php.\n");
        fwrite(STDERR, "That file is curated by hand and is only ever meant to shrink.\n");
        fwrite(STDERR, "Re-run with --force if you really mean to regenerate it from the tree.\n");
        exit(1);
    }

    $out = "<?php\n\ndeclare(strict_types=1);\n\n"
        . "/**\n * Registration sites a module is legitimately absent from.\n"
        . " * Generated once from the tree, then shrunk by hand as gaps close.\n"
        . " * Never add an entry to silence a NEW module: that is the drift this gate exists to catch.\n */\n\nreturn [\n";
    foreach ($matrix as $slug => $row) {
        $missing = array_keys(array_filter($row, static fn (bool $ok): bool => !$ok));
        if ($missing === []) {
            continue;
        }
        $out .= sprintf("    '%s' => [%s],\n", $slug, "'" . implode("', '", $missing) . "'");
    }
    $out .= "];\n";
    file_put_contents($waiverFile, $out);
    printf("Wrote %s\n", $waiverFile);
    exit(0);
}

if ($failures !== []) {
    fwrite(STDERR, "\nPARITY CHECK FAILED\n\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, '  - ' . $failure . "\n");
    }
    fwrite(STDERR, "\nEvery integration module must be registered in every site above.\n");
    fwrite(STDERR, "Run  php tools/parity-check.php --matrix  to see the full grid.\n");
    fwrite(STDERR, "If an absence is correct, record it in tools/parity-waivers.php with a comment saying why.\n\n");
    exit(1);
}

printf("Parity check passed: %d modules across %d registration sites.\n", count($modules), count($sites));
exit(0);
