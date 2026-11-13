<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Robots\Controller\Admin;

use Quince\Engine\Controller\Admin\QuinceController;
use Quince\Robots\Registry\BotRegistry;
use Quince\Robots\Registry\Catalogue;
use Quince\Robots\Robots\RobotsBlock;
use Quince\Robots\Robots\RobotsFile;
use Quince\Robots\Robots\RobotsTest;
use Quince\Robots\Storage\Maintenance;
use Quince\Robots\Storage\Options;
use Quince\Robots\Traffic\RateLimit;
use Quince\Robots\Traffic\TrafficLog;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ekran robotów: kto puka do sklepu i kogo wpuszczamy.
 *
 * Dziedziczy po kontrolerze silnika, tak jak pozostałe moduły linii. Dzięki temu
 * ekran dostaje ten sam boczny pasek i tę samą powłokę co reszta panelu Quince,
 * a klikając w pozycję modułu nie wychodzi się poza panel silnika. Bez
 * `quince_engine` moduł się nie zainstaluje.
 *
 * Uwaga do `trans()`: `QuinceController` dziedziczy po `FrameworkBundleAdminController`,
 * gdzie domena jest **drugim** argumentem. Nowa klasa `PrestaShopAdminController`
 * przyjmuje ją jako trzeci. Pomyłka nie daje błędu, tylko napis bez tłumaczenia.
 */
final class RobotsController extends QuinceController
{
    private const DOMENA = 'Modules.Quincerobots.Admin';

    public function __construct(private readonly BotRegistry $registry)
    {
    }

    public function indexAction(): Response
    {
        return $this->renderPanel(
            '@Modules/quince_robots/views/templates/admin/robots.html.twig',
            $this->chrome('quince_robots') + [
                'categories' => $this->registry->byCategory(),
                'labels' => $this->etykietyKategorii(),
                'file' => (new RobotsFile($this->registry))->status(),
                'blockedCount' => count($this->registry->blocked()),
            ]
        );
    }

    /**
     * Stan pliku i podgląd tego, co moduł do niego wpisuje.
     *
     * Tu sprawdza się, czy decyzja z ekranu robotów naprawdę zeszła na dysk.
     * Plik bywa przepisywany przez rdzeń bez naszego udziału.
     */
    public function fileAction(): Response
    {
        return $this->renderPanel(
            '@Modules/quince_robots/views/templates/admin/file.html.twig',
            $this->chrome('quince_robots_file') + [
                'file' => (new RobotsFile($this->registry))->status(),
                'preview' => (new RobotsBlock($this->registry))->render(),
                'blockedCount' => count($this->registry->blocked()),
            ]
        );
    }

    /**
     * Ruch automatów: kto przychodzi i jak często.
     *
     * Dwie listy. Pierwsza mówi, co robią roboty, o których wiemy. Druga pokazuje
     * automaty, których rejestr nie zna.
     */
    public function trafficAction(): Response
    {
        $licznik = new TrafficLog();
        $sklepy = \Shop::getContextListShopID();
        $nazwy = [];

        foreach ($this->registry->all() as $wpis) {
            $nazwy[$wpis['key']] = $wpis;
        }

        return $this->renderPanel(
            '@Modules/quince_robots/views/templates/admin/traffic.html.twig',
            $this->chrome('quince_robots_traffic') + [
                'known' => $licznik->byBot($sklepy),
                'unknown' => array_map(
                    static fn (array $w): array => $w + ['guess' => BotRegistry::guessToken($w['user_agent'])],
                    $licznik->unknown($sklepy)
                ),
                'bots' => $nazwy,
                'categories' => $this->etykietyKategorii(),
                'counting' => Options::bool(Options::LOG_ON, true),
            ]
        );
    }

    /**
     * Sprawdzenie, co roboty naprawdę widzą.
     *
     * Plik pobieramy po HTTP, nie z dysku. Robot czyta to, co odda serwer, a między
     * jednym a drugim potrafi stanąć reguła serwera albo przekierowanie. Test
     * zaglądający na dysk przechodziłby wtedy, gdy sklep jest zepsuty.
     */
    public function testAction(): Response
    {
        $wynik = (new RobotsTest($this->registry))->run();

        return $this->renderPanel(
            '@Modules/quince_robots/views/templates/admin/test.html.twig',
            $this->chrome('quince_robots_test') + [
                'result' => $wynik,
                'labels' => $this->etykietyKategorii(),
                'mismatches' => count(array_filter($wynik['rows'], static fn (array $w): bool => $w['mismatch'])),
            ]
        );
    }

    /**
     * Dodanie wpisu własnego, zwykle z listy nierozpoznanych automatów.
     *
     * Po dodaniu wracamy na ekran robotów, a nie na ruch.
     */
    public function addBotAction(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('quince_robots_bot_add', $request->request->get('_csrf_token'))) {
            $this->addFlash('error', $this->trans('Invalid security token, please try again.', self::DOMENA));

            return $this->redirectToRoute('quince_robots_traffic');
        }

        $klucz = $this->registry->add([
            'token' => (string) $request->request->get('token', ''),
            'label' => (string) $request->request->get('label', ''),
            'category' => (string) $request->request->get('category', 'other'),
            'policy' => (string) $request->request->get('policy', 'block'),
            'description' => (string) $request->request->get('description', ''),
        ]);

        if ($klucz === '') {
            $this->addFlash('error', $this->trans(
                'That entry could not be added. The token needs at least three characters.',
                self::DOMENA
            ));

            return $this->redirectToRoute('quince_robots_traffic');
        }

        $this->poZmianie();

        return $this->redirectToRoute('quince_robots');
    }

    /** Skasowanie wpisu własnego. Wpisów z listy nie kasujemy, te się wpuszcza. */
    private function kasujWpis(string $klucz): RedirectResponse
    {
        if (!$this->registry->remove($klucz)) {
            $this->addFlash('error', $this->trans(
                'Only entries you added yourself can be removed. The rest are allowed instead.',
                self::DOMENA
            ));

            return $this->redirectToRoute('quince_robots');
        }

        $this->poZmianie();

        return $this->redirectToRoute('quince_robots');
    }

    /**
     * Przeniesienie bota do innej kategorii.
     *
     * Od tej chwili wpis jest odporny na odświeżanie spisu: wybór sprzedawcy
     * nie wraca do naszego.
     */
    private function przepnijKategorie(string $klucz, string $kategoria): RedirectResponse
    {
        if (!$this->registry->setCategory($klucz, $kategoria)) {
            $this->addFlash('error', $this->trans('That category could not be saved.', self::DOMENA));

            return $this->redirectToRoute('quince_robots');
        }

        $this->poZmianie();

        return $this->redirectToRoute('quince_robots');
    }

    /**
     * Wycofanie własnej decyzji o wpisie.
     *
     * Pusta wartość znaczy „wróć do naszej rekomendacji" i jest stanem osobnym
     * od obu decyzji.
     */
    private function wycofajDecyzje(string $klucz): RedirectResponse
    {
        if (!$this->registry->setPolicy($klucz, '')) {
            $this->addFlash('error', $this->trans('That decision could not be saved.', self::DOMENA));

            return $this->redirectToRoute('quince_robots');
        }

        $this->poZmianie();

        return $this->redirectToRoute('quince_robots');
    }

    /**
     * Ustawienia modułu.
     *
     * Wszystkie są globalne dla instalacji, bo `robots.txt` jest jeden na instalację
     * i rozdzielanie ich po sklepach obiecywałoby wybór, którego plik nie odda.
     */
    public function settingsAction(): Response
    {
        $prog = new RateLimit();

        return $this->renderPanel(
            '@Modules/quince_robots/views/templates/admin/settings.html.twig',
            $this->chrome('quince_robots_settings') + [
                'options' => [
                    'robots_on' => Options::bool(Options::ROBOTS_ON, true),
                    'enforce_on' => Options::bool(Options::ENFORCE_ON, false),
                    'log_on' => Options::bool(Options::LOG_ON, true),
                    'retention' => Options::int(Options::RETENTION_MONTHS, 3),
                    'rate_on' => Options::bool(Options::RATE_ON, false),
                    'rate_limit' => Options::int(Options::RATE_LIMIT, 120),
                    'rate_cooldown' => Options::int(Options::RATE_COOLDOWN, 5),
                ],
                // Ile wpisów włącznik ogólny faktycznie dotyczy. Przy botach
                // honorujących plik egzekwowanie nic nie zmienia.
                'enforceable' => count(array_filter(
                    $this->registry->blocked(),
                    static fn (array $b): bool => $b['respects'] === 'no' || $b['enforce_set']
                )),
                'listChecked' => Catalogue::lastChecked(),
                'listCount' => count($this->registry->all()),
                'pending' => (new TrafficLog())->pending(),
                'tripped' => $prog->tripped(),
                'lastPurge' => Maintenance::lastRun(),
            ]
        );
    }

    /**
     * Zapis ustawień.
     *
     * Liczby przycinamy do zakresu, zamiast odrzucać formularz.
     */
    public function saveSettingsAction(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('quince_robots_settings_save', $request->request->get('_csrf_token'))) {
            $this->addFlash('error', $this->trans('Invalid security token, please try again.', self::DOMENA));

            return $this->redirectToRoute('quince_robots_settings');
        }

        $dane = $request->request;

        Options::save(Options::ROBOTS_ON, (int) (bool) $dane->get('robots_on'));
        Options::save(Options::ENFORCE_ON, (int) (bool) $dane->get('enforce_on'));
        Options::save(Options::LOG_ON, (int) (bool) $dane->get('log_on'));
        Options::save(Options::RETENTION_MONTHS, min(60, max(1, (int) $dane->get('retention'))));
        Options::save(Options::RATE_ON, (int) (bool) $dane->get('rate_on'));
        Options::save(Options::RATE_LIMIT, min(100000, max(10, (int) $dane->get('rate_limit'))));
        Options::save(Options::RATE_COOLDOWN, min(60, max(1, (int) $dane->get('rate_cooldown'))));

        // Rejestr liczy egzekwowanie z ustawień, a trzyma wynik w pamięci żądania.
        // Bez unieważnienia ekran po zapisie pokazywałby stan sprzed zapisu.
        BotRegistry::forget();

        $this->addFlash('success', $this->trans('Settings saved.', self::DOMENA));

        return $this->redirectToRoute('quince_robots_settings');
    }

    /**
     * Pobranie spisu robotów i uzgodnienie go z rejestrem.
     *
     * Brak spisu nie jest awarią. Powstaje przy pierwszym wydaniu i do tego czasu
     * moduł działa na zestawie startowym, więc pokazujemy informację, nie błąd.
     */
    public function refreshIndexAction(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('quince_robots_index_refresh', $request->request->get('_csrf_token'))) {
            $this->addFlash('error', $this->trans('Invalid security token, please try again.', self::DOMENA));

            return $this->redirectToRoute('quince_robots_settings');
        }

        $wynik = Catalogue::refresh($this->registry);

        if (!$wynik['ok']) {
            $this->addFlash(
                $wynik['error'] === 'not-published' ? 'info' : 'error',
                $wynik['error'] === 'not-published'
                    ? $this->trans('The Quince index has not been published yet. The module works from its built-in list until then.', self::DOMENA)
                    : $this->trans('The list could not be fetched: %reason%', self::DOMENA, ['%reason%' => $wynik['error']])
            );

            return $this->redirectToRoute('quince_robots_settings');
        }

        $this->addFlash('success', $this->trans(
            '%added% bots added, %updated% updated. Your decisions were left untouched.',
            self::DOMENA,
            ['%added%' => $wynik['added'], '%updated%' => $wynik['updated']]
        ));

        if ($wynik['dropped'] > 0) {
            $this->addFlash('info', $this->trans(
                '%count% entries are no longer on the source list. They keep working and keep your decision. The list simply stopped carrying them.',
                self::DOMENA,
                ['%count%' => $wynik['dropped']]
            ));
        }

        return $this->redirectToRoute('quince_robots_settings');
    }

    /** Skasowanie danych po terminie, na żądanie. */
    public function purgeAction(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('quince_robots_purge', $request->request->get('_csrf_token'))) {
            $this->addFlash('error', $this->trans('Invalid security token, please try again.', self::DOMENA));

            return $this->redirectToRoute('quince_robots_settings');
        }

        $wynik = Maintenance::run(true);

        $this->addFlash('success', $this->trans(
            '%count% days of counter history removed.',
            self::DOMENA,
            ['%count%' => $wynik['days']]
        ));

        return $this->redirectToRoute('quince_robots_settings');
    }

    /**
     * Zapis całego ekranu decyzji.
     *
     * Jeden zapis dla całego formularza, nie jeden na przełącznik.
     *
     * **Zapisujemy tylko to, co się zmieniło.** Formularz przysyła stan wszystkich
     * wpisów, także nietkniętych. Gdybyśmy zapisywali wszystko, każdy bot dostałby
     * po pierwszym zapisie decyzję sprzedawcy, choć sprzedawca jej nie podjął,
     * a rekomendacje zamieniłyby się w wybory.
     */
    public function saveDecisionsAction(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('quince_robots_save', $request->request->get('_csrf_token'))) {
            $this->addFlash('error', $this->trans('Invalid security token, please try again.', self::DOMENA));

            return $this->redirectToRoute('quince_robots');
        }

        // Czynności mają pierwszeństwo przed zaznaczeniami: kasowanego wpisu nie ma
        // sensu najpierw zapisywać, a wycofanie się z decyzji jest jej odwrotnością,
        // nie kolejną decyzją.
        if (($doKasacji = trim((string) $request->request->get('remove', ''))) !== '') {
            return $this->kasujWpis($doKasacji);
        }

        if (($doWycofania = trim((string) $request->request->get('reset', ''))) !== '') {
            return $this->wycofajDecyzje($doWycofania);
        }

        if (($doPrzepiecia = trim((string) $request->request->get('recategorise', ''))) !== '') {
            return $this->przepnijKategorie($doPrzepiecia, (string) $request->request->get('category', ''));
        }

        $przyslane = $request->request->all('policy');
        $biezace = [];

        foreach ($this->registry->all() as $wpis) {
            $biezace[$wpis['key']] = $wpis;
        }

        $zmienione = 0;

        foreach ($przyslane as $klucz => $polityka) {
            $wpis = $biezace[(string) $klucz] ?? null;
            $polityka = (string) $polityka;

            // Wyszukiwarek nie da się stąd przestawić nawet podrobionym formularzem:
            // steruje nimi panel SEO sklepu.
            if ($wpis === null || $wpis['category'] === 'search' || $polityka === $wpis['effective_policy']) {
                continue;
            }

            if ($this->registry->setPolicy((string) $klucz, $polityka)) {
                ++$zmienione;
            }
        }

        if ($zmienione === 0) {
            $this->addFlash('info', $this->trans('Nothing changed.', self::DOMENA));

            return $this->redirectToRoute('quince_robots');
        }

        $this->addFlash('success', $this->trans('%count% decisions saved.', self::DOMENA, ['%count%' => $zmienione]));
        $this->poZmianie();

        return $this->redirectToRoute('quince_robots');
    }

    /** Przepisanie `robots.txt` rękami rdzenia, razem z naszym blokiem. */
    public function writeFileAction(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('quince_robots_write', $request->request->get('_csrf_token'))) {
            $this->addFlash('error', $this->trans('Invalid security token, please try again.', self::DOMENA));

            return $this->redirectToRoute('quince_robots');
        }

        // Wynik zapamiętany: `rebuild()` przepisuje plik, więc wywołanie go
        // w warunku i jeszcze raz w treści zapisałoby go dwukrotnie.
        $udane = (new RobotsFile($this->registry))->rebuild();

        $this->addFlash(
            $udane ? 'success' : 'error',
            $udane
                ? $this->trans('robots.txt was written.', self::DOMENA)
                : $this->trans('robots.txt could not be written. Check the file permissions in the shop root.', self::DOMENA)
        );

        return $this->redirectToRoute('quince_robots');
    }

    /**
     * Nagłówek, zakładki i podświetlenie pozycji w bocznym pasku.
     *
     * Wpisujemy wyłącznie trasy, które istnieją. Zakładka wskazująca na trasę,
     * której nie ma, wywraca panel przy budowaniu odnośnika.
     *
     * @return array<string,mixed>
     */
    private function chrome(string $trasa): array
    {
        return [
            'layoutTitle' => 'Robots & AI',
            'layoutTitleDomain' => self::DOMENA,
            'quinceTabs' => [
                ['label' => 'Robots', 'route' => 'quince_robots'],
                ['label' => 'robots.txt', 'route' => 'quince_robots_file'],
                ['label' => 'Traffic', 'route' => 'quince_robots_traffic'],
                ['label' => 'Test', 'route' => 'quince_robots_test'],
                ['label' => 'Settings', 'route' => 'quince_robots_settings'],
            ],
            'quinceTabsDomain' => self::DOMENA,
            'quinceRoute' => $trasa,
            // Pasek boczny podświetla pozycję modułu; widok pliku jest jego
            // zakładką, a nie osobną pozycją w menu.
            'quinceNavRoute' => 'quince_robots',
        ];
    }

    /**
     * Po każdej zmianie decyzji plik przestaje odpowiadać rejestrowi, więc
     * pokazujemy ostrzeżenie od razu.
     */
    private function poZmianie(): void
    {
        if (!(new RobotsFile($this->registry))->status()['current']) {
            $this->addFlash('warning', $this->trans(
                'The decision is saved, but robots.txt still holds the previous rules. Write the file to apply it.',
                self::DOMENA
            ));
        }
    }

    /**
     * Nazwy kategorii razem z jednym zdaniem o tym, co ta grupa robi z treścią
     * sklepu.
     *
     * @return array<string,array{name:string,hint:string}>
     */
    private function etykietyKategorii(): array
    {
        return [
            'search' => [
                'name' => $this->trans('Search engines', self::DOMENA),
                'hint' => $this->trans('Steered by the shop SEO settings, not here. Listed so you know they are accounted for.', self::DOMENA),
            ],
            'answer' => [
                'name' => $this->trans('Answer engines', self::DOMENA),
                'hint' => $this->trans('Quote the shop in answers and link back to it. Blocking them removes the shop from those answers.', self::DOMENA),
            ],
            'training' => [
                'name' => $this->trans('Model training', self::DOMENA),
                'hint' => $this->trans('Collect the content to train models. They take and send nothing back.', self::DOMENA),
            ],
            'seo' => [
                'name' => $this->trans('SEO tools', self::DOMENA),
                'hint' => $this->trans('Map links between sites. Useful if you use these tools yourself.', self::DOMENA),
            ],
            'monitoring' => [
                'name' => $this->trans('Monitoring', self::DOMENA),
                'hint' => $this->trans('Check whether the shop is answering. Usually your own tools.', self::DOMENA),
            ],
            'other' => [
                'name' => $this->trans('Other', self::DOMENA),
                'hint' => $this->trans('Everything the list does not place elsewhere.', self::DOMENA),
            ],
        ];
    }
}
