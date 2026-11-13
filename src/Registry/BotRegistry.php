<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Robots\Registry;

use Db;
use Quince\Robots\Storage\Options;
use Quince\Robots\Storage\Schema;

/**
 * Rejestr robotów: odczyt, decyzje sprzedawcy i uzgadnianie ze spisem.
 *
 * Trzy źródła wpisów mają różne prawa. `builtin` i `manifest` przychodzą z zewnątrz
 * i wolno je odświeżać, ale **wyłącznie w części opisowej**. Decyzja sprzedawcy
 * (`policy`, `enforce`, `active`) nie jest nigdy nadpisywana. Gdyby była, bot
 * raz zablokowany wracałby po odświeżeniu spisu odblokowany.
 *
 * `custom` to wpisy dodane ręcznie. Odświeżenie ich nie dotyka.
 */
final class BotRegistry
{
    /**
     * Rejestr odczytany w tym żądaniu.
     *
     * Rozpoznanie robota odbywa się przy **każdym** wejściu na stronę sklepu,
     * a wpisów jest kilkadziesiąt i mieszczą się w pamięci. Bez tego zapytanie
     * szłoby do bazy tyle razy, ile razy ktokolwiek zapyta o werdykt w jednym
     * żądaniu (pytają też inne moduły linii).
     *
     * @var array<int,array<string,mixed>>|null
     */
    private static ?array $pamiec = null;

    /** Unieważnia pamięć po zapisie. Wołane stąd, po każdej zmianie decyzji. */
    public static function forget(): void
    {
        self::$pamiec = null;
    }

    /**
     * Wszystkie wpisy, w kolejności czytelnej dla człowieka: kategoriami,
     * a wewnątrz alfabetycznie.
     *
     * @return array<int,array<string,mixed>>
     */
    public function all(): array
    {
        if (self::$pamiec !== null) {
            return self::$pamiec;
        }

        $kolejnosc = [];

        foreach (Schema::CATEGORIES as $i => $kategoria) {
            $kolejnosc[] = 'WHEN "' . pSQL($kategoria) . '" THEN ' . $i;
        }

        $wiersze = Db::getInstance()->executeS(
            'SELECT * FROM `' . _DB_PREFIX_ . Schema::TABLE_BOT . '`
              ORDER BY CASE `category` ' . implode(' ', $kolejnosc) . ' ELSE 99 END, `label` ASC'
        ) ?: [];

        return self::$pamiec = array_map([$this, 'ubierz'], $wiersze);
    }

    /**
     * Wpisy pogrupowane kategoriami, w układzie ekranu decyzji.
     *
     * @return array<string,array<int,array<string,mixed>>>
     */
    public function byCategory(): array
    {
        $out = array_fill_keys(Schema::CATEGORIES, []);

        foreach ($this->all() as $wpis) {
            $out[$wpis['category']][] = $wpis;
        }

        return array_filter($out, static fn (array $lista): bool => $lista !== []);
    }

    /**
     * Wpisy, które trafiają do `robots.txt` jako zakaz.
     *
     * Dwa pominięcia, oba celowe. Zgoda nie generuje **żadnej** grupy: robot
     * spada wtedy do `User-agent: *` i dostaje zakazy rdzenia, a wypisanie mu
     * `Allow: /` odcięłoby go od nich. Wyszukiwarek nie dotykamy w ogóle, bo
     * należą do panelu SEO.
     *
     * @return array<int,array<string,mixed>>
     */
    public function blocked(): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (array $b): bool => $b['active']
                && $b['category'] !== 'search'
                && $b['effective_policy'] === Schema::POLICY_BLOCK
        ));
    }

    /**
     * Wpis pasujący do nagłówka przeglądarki albo `null`.
     *
     * Dopasowanie po fragmencie, bez rozróżniania wielkości liter. Bot podaje
     * swoją nazwę wewnątrz dłuższego napisu, razem z wersją i adresem strony
     * producenta. Dłuższe tokeny sprawdzamy pierwsze, bo `Applebot-Extended`
     * zawiera w sobie `Applebot` i bez tego wygrywałby ten drugi.
     *
     * @return array<string,mixed>|null
     */
    public function match(string $userAgent): ?array
    {
        if (trim($userAgent) === '') {
            return null;
        }

        $wpisy = $this->all();

        usort($wpisy, static fn (array $a, array $b): int => strlen($b['token']) <=> strlen($a['token']));

        foreach ($wpisy as $wpis) {
            if ($wpis['token'] !== '' && stripos($userAgent, $wpis['token']) !== false) {
                return $wpis;
            }
        }

        return null;
    }

    /**
     * Kategoria nadana wpisowi przez sprzedawcę.
     */
    public function setCategory(string $klucz, string $kategoria): bool
    {
        if (!in_array($kategoria, Schema::CATEGORIES, true)) {
            return false;
        }

        $ok = (bool) Db::getInstance()->update(
            Schema::TABLE_BOT,
            [
                'category' => pSQL($kategoria),
                // Znacznik, którego pilnuje `sync()`. Bez niego odświeżenie spisu
                // wróciłoby wpis do naszej kategorii.
                'category_own' => 1,
                'date_upd' => date('Y-m-d H:i:s'),
            ],
            '`bot_key` = "' . pSQL($klucz) . '"'
        );

        self::forget();

        return $ok;
    }

    /**
     * Decyzja sprzedawcy o jednym wpisie.
     *
     * Pusta `policy` znaczy „wróć do naszej rekomendacji" i jest stanem osobnym
     * od obu decyzji.
     */
    public function setPolicy(string $klucz, string $polityka, ?bool $egzekwuj = null): bool
    {
        $dozwolone = ['', Schema::POLICY_ALLOW, Schema::POLICY_BLOCK];

        if (!in_array($polityka, $dozwolone, true)) {
            return false;
        }

        $dane = ['policy' => pSQL($polityka), 'date_upd' => date('Y-m-d H:i:s')];

        if ($egzekwuj !== null) {
            $dane['enforce'] = (int) $egzekwuj;
        }

        $ok = (bool) Db::getInstance()->update(
            Schema::TABLE_BOT,
            $dane,
            '`bot_key` = "' . pSQL($klucz) . '"'
        );

        self::forget();

        return $ok;
    }

    /**
     * Dokłada wpis własny.
     *
     * Dla botów, których nie ma jeszcze na żadnej liście, także naszej.
     *
     * Token jest jedynym polem, które musi być podane: po nim rozpoznajemy bota
     * i bez niego wpis nie znaczyłby nic.
     *
     * @param array{token:string,label?:string,vendor?:string,category?:string,description?:string,policy?:string,respects?:string} $dane
     *
     * @return string klucz nowego wpisu albo pusty napis, gdy się nie udało
     */
    public function add(array $dane): string
    {
        $token = trim($dane['token'] ?? '');

        // Dwa znaki to za mało, żeby cokolwiek rozpoznać, a token „a" pasowałby
        // do każdego nagłówka i zablokował sklep dla wszystkich.
        if (mb_strlen($token) < 3) {
            return '';
        }

        $etykieta = trim($dane['label'] ?? '') ?: $token;
        $kategoria = (string) ($dane['category'] ?? 'other');
        $polityka = (string) ($dane['policy'] ?? Schema::POLICY_BLOCK);
        $teraz = date('Y-m-d H:i:s');

        if (!in_array($kategoria, Schema::CATEGORIES, true)) {
            $kategoria = 'other';
        }

        if (!in_array($polityka, [Schema::POLICY_ALLOW, Schema::POLICY_BLOCK], true)) {
            $polityka = Schema::POLICY_BLOCK;
        }

        $klucz = $this->wolnyKlucz($token);

        $ok = Db::getInstance()->insert(Schema::TABLE_BOT, [
            'bot_key' => pSQL($klucz),
            'token' => pSQL($token),
            'label' => pSQL(mb_substr($etykieta, 0, 128)),
            'vendor' => pSQL(mb_substr(trim($dane['vendor'] ?? ''), 0, 128)),
            'category' => pSQL($kategoria),
            'description' => pSQL(mb_substr(trim($dane['description'] ?? ''), 0, 500)),
            'docs_url' => '',
            'verify_host' => '',
            // Wpis własny nie ma za sobą dokumentacji producenta, więc o tym, czy bot
            // honoruje `robots.txt`, nic nie wiemy. „unclear" sprawia też, że
            // egzekwowanie go nie obejmuje.
            'respects' => pSQL(($dane['respects'] ?? '') === 'no' ? 'no' : 'unclear'),
            'default_policy' => pSQL($polityka),
            'policy' => '',
            'enforce' => 0,
            'source' => Schema::SOURCE_CUSTOM,
            'active' => 1,
            'date_add' => $teraz,
            'date_upd' => $teraz,
        ]);

        self::forget();

        return $ok ? $klucz : '';
    }

    /**
     * Kasuje wpis własny.
     *
     * Wpisów `builtin` i `manifest` nie kasujemy, bo wróciłyby przy najbliższym
     * odświeżeniu spisu. Te wyłącza się decyzją „wpuść", nie usunięciem.
     */
    public function remove(string $klucz): bool
    {
        $ok = (bool) Db::getInstance()->delete(
            Schema::TABLE_BOT,
            '`bot_key` = "' . pSQL($klucz) . '" AND `source` = "' . Schema::SOURCE_CUSTOM . '"'
        );

        self::forget();

        return $ok && Db::getInstance()->Affected_Rows() > 0;
    }

    /**
     * Nazwa produktu wyłuskana z nagłówka przeglądarki.
     *
     * Nagłówki botów mają postać `Nazwa/wersja`, często schowaną w środku napisu
     * udającego przeglądarkę. Bierzemy ostatnią taką parę spoza zestawu nazw,
     * które nosi każda przeglądarka, bo to zwykle ona jest właściwą nazwą bota.
     *
     * To podpowiedź do formularza, a nie rozpoznanie. Ostatnie słowo ma człowiek,
     * bo pomyłka w tokenie blokuje ruch, którego nikt nie chciał zablokować.
     */
    public static function guessToken(string $userAgent): string
    {
        $pomijamy = ['mozilla', 'applewebkit', 'khtml', 'gecko', 'chrome', 'safari', 'version', 'like'];
        $znalezione = '';

        if (preg_match_all('~([A-Za-z][A-Za-z0-9._-]{2,})/[0-9]~', $userAgent, $trafienia)) {
            foreach ($trafienia[1] as $kandydat) {
                if (!in_array(mb_strtolower($kandydat), $pomijamy, true)) {
                    $znalezione = $kandydat;
                }
            }
        }

        return $znalezione;
    }

    /**
     * Klucz, którego jeszcze nie ma. Przy zbiegu nazw dokładamy liczbę, zamiast
     * odmawiać.
     */
    private function wolnyKlucz(string $token): string
    {
        $podstawa = mb_strtolower((string) preg_replace('~[^a-zA-Z0-9]+~', '-', $token));
        $podstawa = trim($podstawa, '-') ?: 'bot';
        $podstawa = mb_substr($podstawa, 0, 56);

        $zajete = $this->klucze();
        $klucz = $podstawa;
        $i = 2;

        while (in_array($klucz, $zajete, true)) {
            $klucz = $podstawa . '-' . $i++;
        }

        return $klucz;
    }

    /**
     * Uzgodnienie rejestru ze spisem.
     *
     * Reguła jest jedna i pilnuje jej ta metoda: **odświeżamy opis, nigdy decyzję**.
     * `policy`, `enforce` i `active` należą do sprzedawcy i spis ich nie dotyka.
     *
     * `default_policy` odświeżamy, bo to nasza rekomendacja, nie jego wybór. Skutek
     * ma tylko tam, gdzie sprzedawca nic nie postanowił.
     *
     * Wpisów własnych nie ruszamy w ogóle. Sprzedawca dodał je, bo spis ich nie miał.
     *
     * @param array<string,array<string,mixed>> $spis
     *
     * @return array{added:int,updated:int,dropped:int}
     */
    public function sync(array $spis): array
    {
        $istniejace = [];

        foreach ($this->all() as $wpis) {
            $istniejace[$wpis['key']] = $wpis;
        }

        $db = Db::getInstance();
        $teraz = date('Y-m-d H:i:s');
        $dodane = 0;
        $zmienione = 0;

        foreach ($spis as $klucz => $dane) {
            $klucz = (string) $klucz;
            $token = trim((string) ($dane['token'] ?? $klucz));

            if (mb_strlen($token) < 3) {
                continue;
            }

            $wlasnaKategoria = ($istniejace[$klucz]['category_own'] ?? false) === true;

            // Przepisujemy **tylko pola, które w przysłanym wpisie są**. Brak pola
            // znaczy „spis nic o tym nie mówi", a nie „wyczyść to, co wiemy".
            // Inaczej jeden wpis bez `respects` kasowałby wiedzę o tym, czy bot
            // honoruje `robots.txt`, a od niej zależy egzekwowanie.
            $opis = ['token' => pSQL($token), 'date_upd' => $teraz];

            if (isset($dane['label'])) {
                $opis['label'] = pSQL(mb_substr((string) $dane['label'], 0, 128));
            }

            if (isset($dane['vendor'])) {
                $opis['vendor'] = pSQL(mb_substr((string) $dane['vendor'], 0, 128));
            }

            if (isset($dane['category']) && in_array((string) $dane['category'], Schema::CATEGORIES, true)) {
                $opis['category'] = pSQL((string) $dane['category']);
            }

            if (isset($dane['description'])) {
                $opis['description'] = pSQL(mb_substr((string) $dane['description'], 0, 500));
            }

            if (isset($dane['docs_url'])) {
                $opis['docs_url'] = pSQL(mb_substr((string) $dane['docs_url'], 0, 255));
            }

            if (isset($dane['verify_host'])) {
                $opis['verify_host'] = pSQL(mb_substr((string) $dane['verify_host'], 0, 128));
            }

            if (in_array((string) ($dane['respects'] ?? ''), ['yes', 'no', 'unclear'], true)) {
                $opis['respects'] = pSQL((string) $dane['respects']);
            }

            if (in_array((string) ($dane['default_policy'] ?? ''), [Schema::POLICY_ALLOW, Schema::POLICY_BLOCK], true)) {
                $opis['default_policy'] = pSQL((string) $dane['default_policy']);
            }

            if (!isset($istniejace[$klucz])) {
                // Przy nowym wpisie pola, których spis nie podał, muszą mieć wartość.
                // Tabela ma dla nich wartości domyślne, ale etykieta pusta zamiast
                // nazwy bota wyglądałaby na uszkodzony wiersz.
                if ($db->insert(Schema::TABLE_BOT, $opis + [
                    'label' => pSQL($token),
                    'category' => 'other',
                    'respects' => 'unclear',
                    'default_policy' => Schema::POLICY_ALLOW,
                    'bot_key' => pSQL($klucz),
                    'policy' => '',
                    'enforce' => 0,
                    'source' => Schema::SOURCE_MANIFEST,
                    'active' => 1,
                    'date_add' => $teraz,
                ])) {
                    ++$dodane;
                }

                continue;
            }

            if ($istniejace[$klucz]['source'] === Schema::SOURCE_CUSTOM) {
                continue;
            }

            // Kategoria przypisana ręcznie jest wyborem sprzedawcy, nie naszym
            // opisem, i wchodzi na tę samą listę chronioną co decyzja.
            if ($wlasnaKategoria) {
                unset($opis['category']);
            }

            $db->update(Schema::TABLE_BOT, $opis, '`bot_key` = "' . pSQL($klucz) . '"');

            if ($db->Affected_Rows() > 0) {
                ++$zmienione;
            }
        }

        $zniknieci = $this->oznaczNieobecnych(array_keys($spis), $istniejace);

        self::forget();

        return ['added' => $dodane, 'updated' => $zmienione, 'dropped' => $zniknieci];
    }

    /**
     * Odnotowuje wpisy, których na liście źródłowej już nie ma.
     *
     * Nie kasujemy ich i nie wyłączamy. Zniknięcie z listy bywa cudzym
     * porządkowaniem (wpis uznany za duplikat albo za zbyt ogólny), a nie
     * znakiem, że bot przestał chodzić. Kasowanie zdejmowałoby wtedy ochronę.
     * Linia `Disallow` dla bota, którego naprawdę nie ma, nic nie kosztuje.
     *
     * **Warunek.** „Nie ma go na liście" wnioskujemy z tego, czego w pliku nie
     * zobaczyliśmy, a plik potrafi przyjść obcięty albo w zmienionym kształcie.
     * Oznaczamy więc dopiero wtedy, gdy lista wygląda na kompletną. Inaczej jedno
     * kulawe pobranie oznaczyłoby cały rejestr jako nieistniejący.
     *
     * @param array<int,string>                 $zListy
     * @param array<string,array<string,mixed>> $istniejace
     */
    private function oznaczNieobecnych(array $zListy, array $istniejace): int
    {
        // Wpisy własne nigdy na tej liście nie były, więc ich nieobecność niczego
        // nie znaczy. Liczymy i oznaczamy wyłącznie te, które stamtąd przyszły.
        $zListyKiedys = array_filter(
            $istniejace,
            static fn (array $b): bool => $b['source'] !== Schema::SOURCE_CUSTOM
        );

        if ($zListyKiedys === [] || count($zListy) * 2 < count($zListyKiedys)) {
            return 0;
        }

        $db = Db::getInstance();
        $obecne = array_map(static fn (string $k): string => '"' . pSQL($k) . '"', $zListy);
        $lista = implode(',', $obecne);

        // Wpis, który wrócił, przestaje być oznaczony. Bez tego znacznik
        // zostawałby na zawsze.
        $db->execute(
            'UPDATE `' . _DB_PREFIX_ . Schema::TABLE_BOT . '`
                SET `off_list_since` = NULL
              WHERE `off_list_since` IS NOT NULL AND `bot_key` IN (' . $lista . ')'
        );

        $db->execute(
            'UPDATE `' . _DB_PREFIX_ . Schema::TABLE_BOT . '`
                SET `off_list_since` = "' . date('Y-m-d') . '"
              WHERE `off_list_since` IS NULL
                AND `source` <> "' . Schema::SOURCE_CUSTOM . '"
                AND `bot_key` NOT IN (' . $lista . ')'
        );

        return (int) $db->Affected_Rows();
    }

    /**
     * Zakłada zestaw startowy. Pomija wpisy, które już są, więc instalacja ponowna
     * nie kasuje decyzji podjętych wcześniej.
     */
    public function seed(): bool
    {
        $istniejace = $this->klucze();
        $teraz = date('Y-m-d H:i:s');

        foreach (DefaultBots::all() as $bot) {
            // Klucz powtórzony **w samym zestawie** też pomijamy, nie tylko taki,
            // który jest już w bazie. Bez tego pomyłka w zestawie kończy się nie
            // brakiem jednego wpisu, lecz przerwaną instalacją i rejestrem
            // uciętym w połowie, bez śladu przyczyny w panelu.
            if (in_array($bot['key'], $istniejace, true)) {
                continue;
            }

            $istniejace[] = $bot['key'];

            $ok = Db::getInstance()->insert(Schema::TABLE_BOT, [
                'bot_key' => pSQL($bot['key']),
                'token' => pSQL($bot['token']),
                'label' => pSQL($bot['label']),
                'vendor' => pSQL($bot['vendor']),
                'category' => pSQL($bot['category']),
                'description' => pSQL($bot['description']),
                'docs_url' => pSQL($bot['docs_url']),
                'verify_host' => pSQL($bot['verify_host']),
                'respects' => pSQL($bot['respects']),
                'default_policy' => pSQL($bot['default_policy']),
                'policy' => '',
                'enforce' => 0,
                'source' => Schema::SOURCE_BUILTIN,
                'active' => 1,
                'date_add' => $teraz,
                'date_upd' => $teraz,
            ]);

            if (!$ok) {
                return false;
            }
        }

        self::forget();

        return true;
    }

    /**
     * Skrót stanu, po którym poznajemy, czy `robots.txt` jest aktualny.
     *
     * Liczony z tego, co do pliku trafia, nie z całego rejestru. Zmiana opisu
     * bota nie ma powodu unieważniać pliku.
     */
    public function stamp(): string
    {
        $czesci = [];

        foreach ($this->blocked() as $bot) {
            $czesci[] = $bot['token'];
        }

        sort($czesci);

        return substr(hash('sha256', implode("\n", $czesci)), 0, 8);
    }

    /**
     * @return array<int,string>
     */
    private function klucze(): array
    {
        $wiersze = Db::getInstance()->executeS(
            'SELECT `bot_key` FROM `' . _DB_PREFIX_ . Schema::TABLE_BOT . '`'
        ) ?: [];

        return array_map('strval', array_column($wiersze, 'bot_key'));
    }

    /**
     * Wiersz bazy w postać, jakiej oczekuje reszta modułu.
     *
     * `effective_policy` liczymy tutaj, w jednym miejscu. Inaczej każdy ekran
     * i każdy zapis do pliku musiałby pamiętać, że pusta `policy` znaczy
     * „rekomendacja".
     *
     * @param array<string,mixed> $wiersz
     *
     * @return array<string,mixed>
     */
    private function ubierz(array $wiersz): array
    {
        $wybor = (string) $wiersz['policy'];
        $rekomendacja = (string) $wiersz['default_policy'];

        return [
            'key' => (string) $wiersz['bot_key'],
            'token' => (string) $wiersz['token'],
            'label' => (string) $wiersz['label'],
            'vendor' => (string) $wiersz['vendor'],
            'category' => (string) $wiersz['category'],
            'description' => (string) $wiersz['description'],
            'docs_url' => (string) $wiersz['docs_url'],
            'verify_host' => (string) $wiersz['verify_host'],
            'respects' => (string) $wiersz['respects'],
            'default_policy' => $rekomendacja,
            'policy' => $wybor,
            'effective_policy' => $wybor !== '' ? $wybor : $rekomendacja,
            'own_choice' => $wybor !== '' && $wybor !== $rekomendacja,
            'category_own' => (bool) ($wiersz['category_own'] ?? 0),
            'off_list_since' => (string) ($wiersz['off_list_since'] ?? ''),
            'enforce' => $this->egzekwowanie($wiersz),
            'enforce_set' => (bool) $wiersz['enforce'],
            'source' => (string) $wiersz['source'],
            'active' => (bool) $wiersz['active'],
        ];
    }

    /**
     * Czy zakaz wobec tego wpisu będzie **naprawdę** egzekwowany odrzucaniem żądań.
     *
     * Trzy przesłanki, bo egzekwowanie jest kosztowne i ryzykowne, a przy większości
     * robotów zbędne:
     *
     * - włącznik ogólny, domyślnie wyłączony. Moduł po instalacji deklaruje, nie odcina
     * - bot **nie honoruje** `robots.txt`. Przy tym, który go szanuje, odrzucanie żądań
     *   niczego nie dokłada, a każda pomyłka w regule kosztuje
     * - albo sprzedawca wymusił egzekwowanie na tym wpisie mimo wszystko
     *
     * Kolumna `enforce` jest więc wyjątkiem od reguły, a nie samą regułą. Dzięki temu
     * włącznik ogólny działa od razu po włączeniu, bez przechodzenia po wpisach.
     *
     * @param array<string,mixed> $wiersz
     */
    private function egzekwowanie(array $wiersz): bool
    {
        if (!Options::bool(Options::ENFORCE_ON, false)) {
            return false;
        }

        return (bool) $wiersz['enforce'] || (string) $wiersz['respects'] === 'no';
    }
}
