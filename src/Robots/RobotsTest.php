<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Robots\Robots;

use Configuration;
use Quince\Robots\Registry\BotRegistry;
use Tools;

/**
 * Co robot naprawdę widzi, gdy pyta o `robots.txt`.
 *
 * **Pobieramy plik po HTTP, nie z dysku.** Między jednym a drugim potrafi stanąć
 * reguła serwera, przekierowanie albo wtyczka hostingu — a robot czyta to, co
 * odda serwer, nie to, co leży w katalogu. Test, który zagląda na dysk, przechodzi
 * wtedy, gdy sklep jest zepsuty.
 *
 * Drugą częścią jest rozstrzygnięcie po zasadach RFC 9309, i tu jest sedno:
 * robot wybiera **jedną grupę** `User-agent` — najlepiej do siebie pasującą —
 * i pozostałe, razem z `*`, ignoruje w całości. Dlatego wypisanie botowi
 * `Allow: /` nie „dodaje mu zgody", tylko odcina go od zakazów rdzenia; ten test
 * pokazuje ten skutek zamiast opowiadać o nim.
 */
final class RobotsTest
{
    private const TIMEOUT = 6;

    public function __construct(private readonly BotRegistry $registry)
    {
    }

    /**
     * @return array{ok:bool,url:string,status:int,error:string,body:string,rows:array<int,array<string,mixed>>}
     */
    public function run(): array
    {
        $adres = $this->adres();
        $odpowiedz = $this->pobierz($adres);

        if ($odpowiedz['error'] !== '') {
            return [
                'ok' => false,
                'url' => $adres,
                'status' => $odpowiedz['status'],
                'error' => $odpowiedz['error'],
                'body' => '',
                'rows' => [],
            ];
        }

        $grupy = $this->grupy($odpowiedz['body']);
        $wiersze = [];

        foreach ($this->registry->all() as $bot) {
            if (!$bot['active']) {
                continue;
            }

            $grupa = $this->grupaDla((string) $bot['token'], $grupy);
            $wpuszczony = $this->wpuszczony($grupa['rules']);

            $wiersze[] = [
                'key' => $bot['key'],
                'label' => $bot['label'],
                'category' => $bot['category'],
                'token' => $bot['token'],
                'group' => $grupa['agent'],
                'allowed' => $wpuszczony,
                'wanted' => $bot['effective_policy'],
                // Rozjazd między decyzją a plikiem to jedyny powód, dla którego ten
                // ekran istnieje. Wyszukiwarek nie pilnujemy — nimi steruje panel SEO.
                'mismatch' => $bot['category'] !== 'search'
                    && $wpuszczony !== ($bot['effective_policy'] === 'allow'),
            ];
        }

        return [
            'ok' => true,
            'url' => $adres,
            'status' => $odpowiedz['status'],
            'error' => '',
            'body' => $odpowiedz['body'],
            'rows' => $wiersze,
        ];
    }

    /**
     * Adres pliku. Zawsze katalog główny domeny — `robots.txt` nie działa
     * w podkatalogu, więc sklep stojący pod `/sklep` i tak jest opisany plikiem
     * z korzenia.
     */
    private function adres(): string
    {
        // Protokół bierzemy z ustawień sklepu, a nie wymuszamy `https`. Sklep bez
        // SSL-a odpowiedziałby wtedy odmową połączenia i test mówiłby „pliku nie ma"
        // o pliku, który jest.
        $ssl = (bool) Configuration::get('PS_SSL_ENABLED');

        return ($ssl ? 'https://' : 'http://')
            . ($ssl ? Tools::getShopDomainSsl() : Tools::getShopDomain())
            . '/robots.txt';
    }

    /**
     * @return array{body:string,status:int,error:string}
     */
    private function pobierz(string $adres): array
    {
        if (!function_exists('curl_init')) {
            return ['body' => '', 'status' => 0, 'error' => 'The cURL extension is not available on this server.'];
        }

        $uchwyt = curl_init($adres);

        curl_setopt_array($uchwyt, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT,
            // Sklepy na certyfikacie samopodpisanym są zwykłym przypadkiem
            // na środowisku roboczym, a my sprawdzamy treść pliku, nie tożsamość
            // serwera, do którego i tak właśnie należymy.
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_HTTPHEADER => ['User-Agent: quince_robots (self-check)'],
        ]);

        $tresc = curl_exec($uchwyt);
        $kod = (int) curl_getinfo($uchwyt, CURLINFO_RESPONSE_CODE);
        $blad = curl_error($uchwyt);
        curl_close($uchwyt);

        if ($tresc === false) {
            return ['body' => '', 'status' => $kod, 'error' => $blad !== '' ? $blad : 'Could not fetch the file.'];
        }

        if ($kod !== 200) {
            return ['body' => '', 'status' => $kod, 'error' => 'The server answered ' . $kod . '.'];
        }

        return ['body' => (string) $tresc, 'status' => $kod, 'error' => ''];
    }

    /**
     * Rozbiór pliku na grupy.
     *
     * Kolejne linie `User-agent` bez reguł między nimi tworzą **jedną** grupę
     * o kilku nazwach — tak mówi RFC i tak pisze je większość plików.
     *
     * @return array<int,array{agents:array<int,string>,rules:array<int,array{allow:bool,path:string}>}>
     */
    private function grupy(string $tresc): array
    {
        $grupy = [];
        $biezaca = -1;
        $poRegulach = false;

        foreach (preg_split('~\R~', $tresc) ?: [] as $linia) {
            $linia = trim((string) preg_replace('~#.*$~', '', (string) $linia));

            if ($linia === '' || !str_contains($linia, ':')) {
                continue;
            }

            [$pole, $wartosc] = explode(':', $linia, 2);
            $pole = strtolower(trim($pole));
            $wartosc = trim($wartosc);

            if ($pole === 'user-agent') {
                if ($biezaca < 0 || $poRegulach) {
                    $grupy[] = ['agents' => [], 'rules' => []];
                    $biezaca = count($grupy) - 1;
                    $poRegulach = false;
                }

                $grupy[$biezaca]['agents'][] = strtolower($wartosc);

                continue;
            }

            if (($pole === 'allow' || $pole === 'disallow') && $biezaca >= 0) {
                $grupy[$biezaca]['rules'][] = ['allow' => $pole === 'allow', 'path' => $wartosc];
                $poRegulach = true;
            }
        }

        return $grupy;
    }

    /**
     * Grupa, którą wybierze robot o tym tokenie.
     *
     * Wygrywa **najdłuższa** pasująca nazwa, bo tylko tak `Applebot-Extended`
     * dostaje swoją grupę zamiast tej od `Applebot`. Gdy nic nie pasuje, zostaje
     * `*`; gdy nie ma i jej — robot nie ma żadnych zakazów.
     *
     * @param array<int,array{agents:array<int,string>,rules:array<int,array{allow:bool,path:string}>}> $grupy
     *
     * @return array{agent:string,rules:array<int,array{allow:bool,path:string}>}
     */
    private function grupaDla(string $token, array $grupy): array
    {
        $token = strtolower($token);
        $najlepsza = ['agent' => '', 'rules' => []];
        $dlugosc = -1;
        $gwiazdka = null;

        foreach ($grupy as $grupa) {
            foreach ($grupa['agents'] as $nazwa) {
                if ($nazwa === '*') {
                    $gwiazdka ??= ['agent' => '*', 'rules' => $grupa['rules']];

                    continue;
                }

                // Nazwa z pliku pasuje, gdy jest początkiem tokenu robota:
                // `Applebot` z pliku obejmuje `Applebot-Extended`, ale nie odwrotnie.
                if (str_starts_with($token, $nazwa) && strlen($nazwa) > $dlugosc) {
                    $dlugosc = strlen($nazwa);
                    $najlepsza = ['agent' => $nazwa, 'rules' => $grupa['rules']];
                }
            }
        }

        if ($dlugosc >= 0) {
            return $najlepsza;
        }

        return $gwiazdka ?? ['agent' => '', 'rules' => []];
    }

    /**
     * Czy strona główna jest dla tej grupy otwarta.
     *
     * Wygrywa reguła o najdłuższej ścieżce, a przy równej długości — zezwolenie.
     * Pusta wartość przy `Disallow` znaczy „nic nie jest zabronione" i nie liczy
     * się jako reguła.
     *
     * @param array<int,array{allow:bool,path:string}> $reguly
     */
    private function wpuszczony(array $reguly): bool
    {
        $najdluzsza = -1;
        $wynik = true;

        foreach ($reguly as $regula) {
            // Pusta ścieżka nie jest regułą. `Disallow:` bez wartości to właśnie
            // tym sposobem zapisane „niczego nie zabraniam".
            if ($regula['path'] === '' || !$this->obejmuje($regula['path'], '/')) {
                continue;
            }

            $dlugosc = strlen($regula['path']);

            if ($dlugosc > $najdluzsza || ($dlugosc === $najdluzsza && $regula['allow'])) {
                $najdluzsza = $dlugosc;
                $wynik = $regula['allow'];
            }
        }

        return $wynik;
    }

    /**
     * Czy wzorzec z pliku obejmuje tę ścieżkę.
     *
     * Reguła obowiązuje, gdy jest **początkiem** ścieżki; `*` zastępuje dowolny
     * ciąg, a `$` na końcu żąda dokładnego zakończenia. Bez obsługi tych dwóch
     * znaków test mówiłby „wpuszczony" o robocie, którego `Disallow: /*` odcina.
     */
    private function obejmuje(string $wzorzec, string $sciezka): bool
    {
        if (!str_contains($wzorzec, '*') && !str_ends_with($wzorzec, '$')) {
            return str_starts_with($sciezka, $wzorzec);
        }

        $koniec = str_ends_with($wzorzec, '$');
        $czesci = array_map('preg_quote', explode('*', rtrim($wzorzec, '$')), array_fill(0, substr_count($wzorzec, '*') + 1, '~'));

        return (bool) preg_match('~^' . implode('.*', $czesci) . ($koniec ? '$' : '') . '~', $sciezka);
    }
}
