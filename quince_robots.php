<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';

use Quince\Robots\Registry\BotRegistry;
use Quince\Robots\Robots\RobotsBlock;
use Quince\Robots\Storage\Maintenance;
use Quince\Robots\Storage\Options;
use Quince\Robots\Storage\Schema;
use Quince\Robots\Traffic\Classifier;
use Quince\Robots\Traffic\RateLimit;
use Quince\Robots\Traffic\TrafficLog;
use Quince\Robots\Traffic\Verifier;

/**
 * Quince Robots — kto z automatów wchodzi do sklepu i na jakich zasadach.
 *
 * Moduł jest jedyną warstwą w linii Quince, która zajmuje się ruchem automatów.
 * Pozostałe moduły go pytają hookiem `actionQuinceClassifyVisitor`, ale same
 * nikogo nie rozpoznają i nikogo nie blokują.
 *
 * **Wymaga silnika.** Ekran modułu siedzi w jego powłoce — ten sam boczny pasek
 * i te same komponenty co reszta panelu Quince, bez kopiowania czegokolwiek.
 * Decyzja właściciela projektu z 03.09.2026, zastępująca wcześniejsze założenie
 * o samodzielności modułu.
 *
 * Zależność dotyczy wyłącznie panelu. Rozpoznawanie robotów, `robots.txt` i licznik
 * odwiedzin nie sięgają do silnika ani razu.
 */
class Quince_Robots extends Module
{
    /** @var array<string,array<string,mixed>> werdykty policzone w tym żądaniu */
    private array $werdykty = [];

    private ?Classifier $klasyfikator = null;

    public function __construct()
    {
        $this->name = 'quince_robots';
        $this->tab = 'administration';
        $this->version = '0.1.1';
        $this->author = 'Quince Project';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '9.0.0', 'max' => '9.99.99'];
        $this->bootstrap = true;

        // Ekran modułu dziedziczy po kontrolerze silnika, a szablon po jego layoucie.
        // Bez tej deklaracji PrestaShop pozwoli wyłączyć silnik, a moduł przestanie
        // otwierać swój ekran bez żadnego ostrzeżenia.
        $this->dependencies = ['quince_engine'];

        // Jeden wpis w gałęzi „Quince project" w menu PrestaShopa, prowadzący
        // na ekran modułu. Podstron nie zgłaszamy: moduł ma je we własnym
        // bocznym pasku, a menu sklepu urosłoby wtedy o kilkanaście pozycji.
        // Kolejność wpisów w gałęzi ustala silnik.
        $this->tabs = [
            [
                'class_name' => 'AdminQuinceRobots',
                'visible' => true,
                'name' => 'Robots and AI',
                'parent_class_name' => 'AdminQuinceProject',
                'route_name' => 'quince_robots',
                'wording' => 'Robots and AI',
                'wording_domain' => 'Modules.Quincerobots.Admin',
            ],
        ];

        parent::__construct();

        $this->displayName = $this->trans('Quince Robots', [], 'Modules.Quincerobots.Admin');
        $this->description = $this->trans(
            'Decide which crawlers and AI agents may enter the shop, and see which of them actually come.',
            [],
            'Modules.Quincerobots.Admin'
        );
        $this->confirmUninstall = $this->trans(
            'Uninstall the module? Its rules stay in robots.txt until PrestaShop generates the file again (Shop Parameters > Traffic & SEO). After that every bot is allowed again.',
            [],
            'Modules.Quincerobots.Admin'
        );
    }

    public function install(): bool
    {
        if (!parent::install()) {
            return false;
        }

        Options::installDefaults();

        return (new Schema())->installTables()
            && (new BotRegistry())->seed()
            && $this->registerHook('actionQuinceRegisterFeature')
            && $this->registerHook('actionAdminMetaAfterWriteRobotsFile')
            // Najwcześniejszy punkt w cyklu żądania, jaki daje rdzeń: komentarz
            // przy nim mówi wprost, że jest do logowania i do rzeczy podstawowych.
            // Później znaczyłoby, że zablokowany bot i tak zdążył wywołać zapytania
            // budujące stronę.
            && $this->registerHook('actionDispatcherBefore')
            && $this->registerHook('actionQuinceClassifyVisitor')
            // Jedyny zegar, jaki moduł ma: wejście pracownika do panelu.
            && $this->registerHook('displayBackOfficeHeader');
    }

    public function uninstall(): bool
    {
        // Kolejność ma znaczenie: najpierw sprzątamy po sobie, a dopiero potem
        // oddajemy moduł PrestaShopowi. Odwrotna zostawia tabele osierocone,
        // gdy `parent::uninstall()` przerwie w połowie.
        Options::removeAll();
        (new Schema())->removeTables();

        return parent::uninstall();
    }

    /**
     * Zgłoszenie do silnika: co dostarczamy i gdzie tego szukać.
     *
     * **Bez `type`, i to nie jest przeoczenie.** Typ jest potrzebny wyłącznie
     * wtedy, gdy silnik ma z możliwości skorzystać — wybrać dostawcę wyszukiwarki
     * albo menu spośród kilku. Ten moduł niczego nie dostarcza silnikowi; mówi
     * tylko „mam własny ekran, oto jego adres". Wpisanie mu typu byłoby wymyśleniem
     * pojęcia po to, żeby wypełnić pole.
     *
     * Zgłoszenie jest bezpieczne także wtedy, gdy silnika nie ma albo jest starszy
     * i deklaracji nie przyjmie — ekran wisi wtedy osobno, na liście modułów,
     * i wszystko działa dalej.
     *
     * **Zgłaszamy się dopiero, gdy trasa naprawdę istnieje.** Silnik przy braku
     * routera nie umie sprawdzić trasy i wtedy pozycję pokazuje — słusznie, bo
     * ukrywanie wszystkiego niesprawdzonego kasowało całą grupę „Moduły". Ale
     * znaczy to, że moduł zgłaszający trasę, której nie ma, wywraca panel przy
     * budowaniu odnośnika. Zabezpieczenie silnika jest siatką, nie przyzwoleniem.
     *
     * @return array<int,array{id:string,label:string,route:string,icon:string}>
     */
    public function hookActionQuinceRegisterFeature(): array
    {
        if (!$this->trasaGotowa()) {
            return [];
        }

        return [[
            'id' => 'quince_robots',
            'label' => $this->trans('Robots & AI', [], 'Modules.Quincerobots.Admin'),
            'route' => 'quince_robots',
            // Nazwa z zestawu paczki design systemu, nie treść. Ikona spoza zestawu
            // rozjeżdża panel, a surowy SVG ze zgłoszenia czyniłby z niego miejsce,
            // w którym cudzy moduł rysuje, co chce.
            'icon' => 'shield',
        ]];
    }

    /**
     * Dopisanie własnych reguł do `robots.txt`.
     *
     * Rdzeń otwiera plik w trybie zapisu i pisze go od zera, więc cokolwiek ktoś
     * wkleiłby tam ręcznie, znika przy pierwszym „Wygeneruj plik robots.txt".
     * Ten hook daje **otwarty uchwyt** tuż przed zamknięciem pliku — dopisujemy
     * się na końcu i pojawiamy się sami przy każdym generowaniu.
     *
     * @param array{rb_data?:array<string,mixed>,write_fd?:resource} $params
     */
    public function hookActionAdminMetaAfterWriteRobotsFile(array $params): void
    {
        if (!Options::bool(Options::ROBOTS_ON, true)) {
            return;
        }

        $uchwyt = $params['write_fd'] ?? null;

        if (!is_resource($uchwyt)) {
            return;
        }

        $blok = (new RobotsBlock(new BotRegistry()))->render();

        if ($blok !== '') {
            fwrite($uchwyt, $blok);
        }
    }

    /**
     * Rozpoznanie żądania: licznik odwiedzin i ewentualne odrzucenie.
     *
     * Liczymy wyłącznie automaty — rozpoznane z rejestru albo takie, o których
     * mówi `Connection::isBot()` z rdzenia. Wpisywanie tu każdego człowieka
     * zamieniłoby licznik w rejestr odwiedzin sklepu, czyli w coś, czego ten
     * moduł nie ma prawa prowadzić.
     *
     * @param array{controller_type?:int} $params
     */
    public function hookActionDispatcherBefore(array $params): void
    {
        // Panel zostawiamy w spokoju: pracownik nie jest ruchem automatów,
        // a pomyłka w regule nie może odciąć sprzedawcy dostępu do sklepu.
        if ((int) ($params['controller_type'] ?? 0) !== Dispatcher::FC_FRONT) {
            return;
        }

        $agent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $ip = (string) Tools::getRemoteAddr();
        $werdykt = $this->rozpoznaj($agent, $ip);

        // Próg częstości mierzy ruch, który się nie przedstawia, więc liczy
        // wszystkich — także zwykłych odwiedzających. Domyślnie wyłączony.
        if ((new RateLimit())->hit($ip)) {
            header('HTTP/1.1 429 Too Many Requests');
            header('Retry-After: 60');
            exit;
        }

        $znany = $werdykt['bot'] !== '';
        $automat = $znany || (class_exists('Connection') && Connection::isBot($agent));

        if (!$automat) {
            return;
        }

        $odrzucamy = $znany && $this->klasyfikator()->shouldBlock($werdykt);

        (new TrafficLog())->record(
            (int) $this->context->shop->id,
            $werdykt['bot'],
            $agent,
            $werdykt['verified'],
            $odrzucamy
        );

        if ($odrzucamy) {
            header('HTTP/1.1 403 Forbidden');
            header('Content-Type: text/plain; charset=utf-8');
            echo "This shop does not allow this crawler.\n";
            exit;
        }
    }

    /**
     * Sprzątanie po module, raz na dobę, przy okazji wejścia do panelu.
     *
     * Nic nie zwracamy do nagłówka — moduł nie dokłada panelowi ani arkusza,
     * ani skryptu. Hook jest tu wyłącznie zegarem, bo własnego moduł nie ma.
     */
    public function hookDisplayBackOfficeHeader(): string
    {
        Maintenance::run();

        return '';
    }

    /**
     * Odpowiedź dla pozostałych modułów linii: kto puka.
     *
     * Pytający dostaje werdykt i decyduje sam, co z niego wynika u niego. Ten
     * moduł nie wie, czym jest zgoda, koszyk ani zamówienie, i nie ma prawa
     * rozstrzygać za nie.
     *
     * @param array{user_agent?:string,ip?:string} $params
     *
     * @return array<string,mixed>
     */
    public function hookActionQuinceClassifyVisitor(array $params): array
    {
        return $this->rozpoznaj(
            (string) ($params['user_agent'] ?? $_SERVER['HTTP_USER_AGENT'] ?? ''),
            (string) ($params['ip'] ?? Tools::getRemoteAddr())
        );
    }

    /**
     * Werdykt liczony raz na żądanie.
     *
     * Rozpoznanie sięga do bazy i bywa, że do DNS-u, a pytać o nie mogą w jednym
     * żądaniu i ten moduł, i każdy inny.
     *
     * @return array<string,mixed>
     */
    private function rozpoznaj(string $agent, string $ip): array
    {
        $klucz = $agent . '|' . $ip;

        return $this->werdykty[$klucz] ??= $this->klasyfikator()->classify($agent, $ip);
    }

    private function klasyfikator(): Classifier
    {
        return $this->klasyfikator ??= new Classifier(
            new BotRegistry(),
            new Verifier()
        );
    }

    /**
     * Przycisk „Konfiguruj" w menedżerze modułów.
     *
     * Bez tej metody PrestaShop wygasza przycisk i do modułu nie da się wejść
     * inaczej niż przez boczny pasek silnika — a sprzedawca, który właśnie go
     * wgrał, szuka go najpierw tam, gdzie wgrywał.
     */
    public function getContent(): void
    {
        Tools::redirectAdmin($this->get('router')->generate('quince_robots'));
    }

    /**
     * Czy ekran modułu jest już podpięty pod trasę.
     *
     * Sprawdzamy obecność pliku tras, a nie samego routera: hook bywa wołany
     * w kontekstach, w których routera nie ma, a wtedy odpowiedź „nie wiem"
     * musi znaczyć „nie zgłaszaj się". Plik tras jest tu wiarygodnym sygnałem,
     * bo powstaje razem z kontrolerem.
     */
    private function trasaGotowa(): bool
    {
        return is_file(__DIR__ . '/config/routes.yml');
    }

}
