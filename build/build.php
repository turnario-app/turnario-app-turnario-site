<?php

declare(strict_types=1);

/**
 * Builds the static public site of Turnario into dist/.
 *
 *   php build/build.php [--env=prod|qa]
 *
 * The page templates are the ones the API used to render (Twig + the `web` and `enums` translation catalogs); here
 * they are rendered once per language: Italian at the root (/funzioni), English under /en (/en/funzioni). The
 * values that change per environment (public host, web app, stores, mailboxes) come from config/<env>.json.
 */

require __DIR__ . '/../vendor/autoload.php';

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

const LOCALES = ['it', 'en'];
const DEFAULT_LOCALE = 'it';

$root = \dirname(__DIR__);
$env = 'prod';
foreach (\array_slice($argv, 1) as $arg) {
    if (\str_starts_with($arg, '--env=')) {
        $env = \substr($arg, 6);
    }
}
$configFile = "$root/config/$env.json";
if (!\is_file($configFile)) {
    \fwrite(\STDERR, "Unknown environment '$env' (no $configFile)\n");
    exit(1);
}
/** @var array<string, mixed> $config */
$config = \json_decode((string) \file_get_contents($configFile), true, flags: \JSON_THROW_ON_ERROR);
/** @var array<string, mixed> $catalog prices and corps, see data/catalog.json */
$catalog = \json_decode((string) \file_get_contents("$root/data/catalog.json"), true, flags: \JSON_THROW_ON_ERROR);

$siteUrl = \rtrim((string) $config['siteUrl'], '/');
$dynamicUrl = \rtrim((string) $config['dynamicUrl'], '/');
$webAppUrl = \rtrim((string) $config['webAppUrl'], '/');
$dist = "$root/dist";

// Static pages: route => [path, indexed in the sitemap, translated to English]. Legal texts are Italian only.
$pages = [
    'web_home' => ['/', true],
    'web_features' => ['/funzioni', true],
    'web_pricing' => ['/prezzi', true],
    'web_support' => ['/supporto', true],
    'web_terms' => ['/terms', false],
    'web_privacy' => ['/privacy', false],
    'web_cookie' => ['/cookie', false],
];
// Pages that need the backend (forms with a token, account deletion): always served by the API.
$dynamicRoutes = [
    'web_waitlist' => '/in-arrivo',
    'web_delete_account' => '/delete-account',
];
$corpsSlugs = \array_column($catalog['corps'], 'value');

$translator = new Translator(DEFAULT_LOCALE);
$translator->setFallbackLocales([DEFAULT_LOCALE]);
$translator->addLoader('yaml', new YamlFileLoader());
foreach (LOCALES as $locale) {
    foreach (['web', 'enums'] as $domain) {
        $translator->addResource('yaml', "$root/translations/$domain.$locale.yaml", $locale, $domain);
    }
}

/** What the templates ask of `app.request`: the current route and its parameters. */
final class RequestStub
{
    public object $attributes;

    public function __construct(public string $route, public array $params)
    {
        $this->attributes = new class($route, $params) {
            public function __construct(private string $route, private array $params) {}

            public function get(string $key): mixed
            {
                return match ($key) {
                    '_route' => $this->route,
                    '_route_params' => $this->params,
                    default => null,
                };
            }
        };
    }
}

$currentLocale = DEFAULT_LOCALE;

$twig = new Environment(new FilesystemLoader("$root/templates"), ['strict_variables' => true, 'autoescape' => 'html']);
$twig->addFilter(new TwigFilter('trans', static fn (string $id, array $params = [], ?string $domain = null, ?string $locale = null): string => $translator->trans($id, $params, $domain, $locale)));
$twig->addFunction(new TwigFunction('price', static function (float|int $cents, string $locale, string $currency = 'EUR'): string {
    $formatted = (new NumberFormatter($locale, NumberFormatter::CURRENCY))->formatCurrency($cents / 100, $currency);

    return $formatted === false ? \sprintf('%.2f %s', $cents / 100, $currency) : $formatted;
}));
$twig->addFunction(new TwigFunction('month_title', static fn (DateTimeInterface $d, string $locale): string => \mb_convert_case((string) (new IntlDateFormatter($locale, IntlDateFormatter::NONE, IntlDateFormatter::NONE, $d->getTimezone(), null, 'LLLL yyyy'))->format($d), \MB_CASE_TITLE)));
$twig->addFunction(new TwigFunction('enum_cases', static fn (string $enum): array => $enum === 'App\\Enum\\CorpsLandingEnum' ? $catalog['corps'] : throw new RuntimeException("Unknown enum $enum")));
$twig->addFunction(new TwigFunction('constant', static fn (string $name): mixed => $catalog['constants'][$name] ?? (\defined($name) ? \constant($name) : throw new RuntimeException("Unknown constant $name"))));
$twig->addFunction(new TwigFunction('store_badge', static function (string $store, string $locale) use ($root): ?string {
    foreach ([$locale, 'en'] as $candidate) {
        foreach (['svg', 'png'] as $ext) {
            $name = \sprintf('%s-%s.%s', $store, $candidate, $ext);
            if (\is_file("$root/public/images/badges/$name")) {
                return '/images/badges/' . $name;
            }
        }
    }

    return null;
}));
$twig->addFunction(new TwigFunction('path', static function (string $route, array $params = []) use (&$currentLocale, $pages, $dynamicRoutes, $dynamicUrl): string {
    if (isset($dynamicRoutes[$route])) {
        return $dynamicUrl . $dynamicRoutes[$route] . ($params === [] ? '' : '?' . \http_build_query($params));
    }
    $prefix = $currentLocale === DEFAULT_LOCALE ? '' : '/' . $currentLocale;
    if ($route === 'web_corps') {
        return $prefix . '/turni-' . $params['slug'];
    }
    $path = $pages[$route][0] ?? throw new RuntimeException("Unknown route $route");
    // Legal texts exist in Italian only.
    if (\in_array($route, ['web_terms', 'web_privacy', 'web_cookie'], true)) {
        return $path;
    }

    return $path === '/' ? ($prefix === '' ? '/' : $prefix . '/') : $prefix . $path;
}));

$site = [
    'baseUrl' => $siteUrl,
    'webAppUrl' => $webAppUrl,
    'signInUrl' => $webAppUrl === '' ? '' : $webAppUrl . '/accedi',
    'signUpUrl' => $webAppUrl === '' ? '' : $webAppUrl . '/registrati',
    'iosStoreUrl' => (string) $config['iosStoreUrl'],
    'androidStoreUrl' => (string) $config['androidStoreUrl'],
    'supportEmail' => (string) $config['supportEmail'],
];
$twig->addGlobal('site', $site);
$twig->addGlobal('consent', ['categories' => []]);

$plans = $catalog['plans'];
$context = [
    'features' => \array_map(static fn (string $n): array => ['name' => $n], $catalog['features']),
    'plans' => $plans,
    'offers' => $catalog['offers'],
    'monthlyAmount' => $catalog['monthlyAmount'],
    'version' => $config['legalVersion'],
    'contactEmail' => $config['legalContactEmail'],
];

$write = static function (string $path, string $content) use ($dist): void {
    // GitHub Pages answers /funzioni with funzioni.html, no redirect: the address stays the canonical one.
    $file = $dist . (\str_ends_with($path, '/') ? $path . 'index.html' : $path . '.html');
    \is_dir(\dirname($file)) || \mkdir(\dirname($file), 0o777, true);
    \file_put_contents($file, $content);
};

// Fresh dist/.
\is_dir($dist) && \array_map('unlink', \array_filter(\glob("$dist/*") ?: [], 'is_file'));
(new Filesystem())->remove($dist);

/** @var list<array{path: string, locale: string, route: string}> $built */
$built = [];
foreach (LOCALES as $locale) {
    $currentLocale = $locale;
    $prefix = $locale === DEFAULT_LOCALE ? '' : '/' . $locale;
    $jobs = [];
    foreach ($pages as $route => [$path]) {
        $legal = \in_array($route, ['web_terms', 'web_privacy', 'web_cookie'], true);
        if ($legal && $locale !== DEFAULT_LOCALE) {
            continue;
        }
        $template = match ($route) {
            'web_home' => 'web/site/home.html.twig',
            'web_features' => 'web/site/features.html.twig',
            'web_pricing' => 'web/site/pricing.html.twig',
            'web_support' => 'web/site/support.html.twig',
            'web_terms' => 'legal/terms.html.twig',
            'web_privacy' => 'legal/privacy.html.twig',
            'web_cookie' => 'legal/cookie.html.twig',
        };
        $jobs[] = [$route, $legal ? $path : ($path === '/' ? $prefix . '/' : $prefix . $path), $template, []];
    }
    foreach ($catalog['corps'] as $landing) {
        $jobs[] = ['web_corps', $prefix . '/turni-' . $landing['value'], 'web/site/corps.html.twig', ['landing' => $landing]];
    }

    foreach ($jobs as [$route, $path, $template, $extra]) {
        $params = isset($extra['landing']) ? ['slug' => $extra['landing']['value']] : [];
        $alternates = [];
        if (!\in_array($route, ['web_terms', 'web_privacy', 'web_cookie'], true)) {
            $base = \preg_replace('#^/en(?=/|$)#', '', $path) ?: '/';
            foreach (LOCALES as $alt) {
                $altPath = $alt === DEFAULT_LOCALE ? $base : ($base === '/' ? '/en/' : '/en' . $base);
                $alternates[] = ['locale' => $alt, 'url' => $siteUrl . $altPath];
            }
        }
        $html = $twig->render($template, [
            ...$context,
            'alternates' => $alternates,
            ...$extra,
            'locale' => $locale,
            'app' => ['request' => new RequestStub($route, $params)],
        ]);
        $write($path, $html);
        $built[] = ['path' => $path, 'locale' => $locale, 'route' => $route];
    }
}

// Sitemap: every indexed page in both languages, with the alternates.
$indexed = [];
foreach ($built as $page) {
    if ($page['route'] === 'web_corps' || ($pages[$page['route']][1] ?? false)) {
        $indexed[] = $page;
    }
}
$unprefixed = static fn (string $path): string => \preg_replace('#^/en(?=/|$)#', '', $path) ?: '/';
$entries = '';
foreach ($indexed as $page) {
    $base = $unprefixed($page['path']);
    $alternates = '';
    foreach (LOCALES as $alt) {
        $altPath = $alt === DEFAULT_LOCALE ? $base : ($base === '/' ? '/en/' : '/en' . $base);
        $alternates .= \sprintf('<xhtml:link rel="alternate" hreflang="%s" href="%s"/>', $alt, $siteUrl . $altPath);
    }
    $entries .= \sprintf("  <url><loc>%s</loc>%s</url>\n", $siteUrl . $page['path'], $alternates);
}
\file_put_contents("$dist/sitemap.xml", "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\" xmlns:xhtml=\"http://www.w3.org/1999/xhtml\">\n$entries</urlset>\n");

$robots = $config['indexable'] ? "User-agent: *\nDisallow:\n\nSitemap: $siteUrl/sitemap.xml\n" : "User-agent: *\nDisallow: /\n";
\file_put_contents("$dist/robots.txt", $robots);

// Static assets, the custom domain for GitHub Pages and the 404 page.
(new Filesystem())->mirror("$root/public", $dist);
\file_put_contents("$dist/CNAME", \parse_url($siteUrl, \PHP_URL_HOST) . "\n");
\file_put_contents("$dist/.nojekyll", '');
\file_put_contents("$dist/404.html", $twig->render('web/site/not_found.html.twig', ['locale' => DEFAULT_LOCALE, 'app' => ['request' => new RequestStub('web_home', [])], ...$context]));

echo \sprintf("Built %d pages for %s into %s\n", \count($built), $siteUrl, $dist);
