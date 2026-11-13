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

use Quince\Robots\Storage\Options;

/**
 * Spis robotów Quince: skąd bierzemy nowe wpisy.
 *
 * Listy nie utrzymujemy sami. Źródłem jest publiczny, utrzymywany zbiór
 * `ai-robots-txt/ai.robots.txt` (MIT), a nasza organizacja go lustruje, przekłada
 * na schemat modułu i dokłada trzy pola, których tamten nie ma: kategorię, domenę
 * do weryfikacji i naszą rekomendację. To jedyne miejsce, gdzie trzymamy własny osąd.
 *
 * **Sklep nie odpytuje GitHuba.** Sześćdziesiąt żądań na godzinę na adres IP
 * rozchodzi się na hostingu współdzielonym w kilka minut, a setki sklepów byłyby
 * uzależnione od cudzego repozytorium. Pobieramy jeden plik z naszej domeny,
 * ten sam, z którego moduły czytają o swoich wydaniach.
 *
 * Oczekiwany kształt:
 *
 * ```json
 * {
 *   "robots": {
 *     "updated": "2026-09-01",
 *     "bots": {
 *       "gptbot": {
 *         "token": "GPTBot", "label": "GPTBot", "vendor": "OpenAI",
 *         "category": "training", "description": "…", "docs_url": "…",
 *         "verify_host": "", "respects": "yes", "default_policy": "block"
 *       }
 *     }
 *   }
 * }
 * ```
 *
 * Nieznane pola pomijamy: plik obsługuje też wydania modułów i motyw, a każdy
 * z nich czyta z niego co innego.
 */
final class Catalogue
{
    /**
     * Adres spisu. Wpisany w moduł, nie w ustawienia: pole, w które sprzedawca
     * wpisuje dowolny adres, byłoby furtką. Kto ma dostęp do panelu, wskazuje
     * własny plik i moduł bierze wpisy stamtąd.
     */
    public const ADRES = 'https://quinceproject.github.io/manifest.json';

    /** Jak długo zapamiętana odpowiedź jest aktualna. */
    private const WAZNY_GODZIN = 24;

    private const TIMEOUT = 6;

    /**
     * Pobiera spis i uzgadnia go z rejestrem.
     *
     * @return array{ok:bool,added:int,updated:int,error:string,checked:string}
     */
    public static function refresh(BotRegistry $rejestr, bool $wymus = true): array
    {
        $spis = self::spis($wymus);

        if ($spis['error'] !== '') {
            return ['ok' => false, 'added' => 0, 'updated' => 0, 'error' => $spis['error'], 'checked' => $spis['checked']];
        }

        $wynik = $rejestr->sync($spis['bots']);

        return [
            'ok' => true,
            'added' => $wynik['added'],
            'updated' => $wynik['updated'],
            'error' => '',
            'checked' => $spis['checked'],
        ];
    }

    /** Kiedy ostatnio udało się pobrać spis. Pusty napis, gdy jeszcze nigdy. */
    public static function lastChecked(): string
    {
        $dane = json_decode(Options::text(Options::INDEX_CACHE), true);

        return is_array($dane) ? (string) ($dane['checked'] ?? '') : '';
    }

    /**
     * Spis z pamięci albo z sieci.
     *
     * @return array{bots:array<string,array<string,mixed>>,checked:string,error:string}
     */
    private static function spis(bool $wymus): array
    {
        $zapisane = self::zPamieci();

        if (!$wymus && $zapisane !== null) {
            return [
                'bots' => (array) ($zapisane['bots'] ?? []),
                'checked' => (string) ($zapisane['checked'] ?? ''),
                'error' => '',
            ];
        }

        $odpowiedz = self::pobierz(self::ADRES);

        if ($odpowiedz['error'] !== '') {
            return [
                'bots' => (array) ($zapisane['bots'] ?? []),
                'checked' => (string) ($zapisane['checked'] ?? ''),
                'error' => $odpowiedz['error'],
            ];
        }

        $dane = json_decode($odpowiedz['body'], true);
        $boty = $dane['robots']['bots'] ?? null;

        if (!is_array($dane) || !is_array($boty)) {
            return ['bots' => [], 'checked' => '', 'error' => 'The index does not carry a robot list in the expected format.'];
        }

        $wynik = ['bots' => $boty, 'checked' => date('Y-m-d H:i:s')];
        Options::save(Options::INDEX_CACHE, (string) json_encode($wynik), true);

        return $wynik + ['error' => ''];
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function zPamieci(): ?array
    {
        $dane = json_decode(Options::text(Options::INDEX_CACHE), true);

        if (!is_array($dane) || !isset($dane['checked'])) {
            return null;
        }

        return strtotime((string) $dane['checked']) > strtotime('-' . self::WAZNY_GODZIN . ' hours') ? $dane : null;
    }

    /**
     * @return array{body:string,error:string}
     */
    private static function pobierz(string $adres): array
    {
        if (!function_exists('curl_init')) {
            return ['body' => '', 'error' => 'The cURL extension is not available on this server.'];
        }

        $uchwyt = curl_init($adres);

        curl_setopt_array($uchwyt, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT,
            CURLOPT_HTTPHEADER => ['User-Agent: quince_robots'],
        ]);

        $tresc = curl_exec($uchwyt);
        $kod = (int) curl_getinfo($uchwyt, CURLINFO_RESPONSE_CODE);
        $blad = curl_error($uchwyt);
        curl_close($uchwyt);

        if ($tresc === false) {
            return ['body' => '', 'error' => $blad !== '' ? $blad : 'Could not reach the index.'];
        }

        // Spis powstaje przy pierwszym wydaniu. Zanim to nastąpi, jego brak jest
        // stanem normalnym, a nie awarią, i tak trzeba go pokazać sprzedawcy.
        if ($kod === 404) {
            return ['body' => '', 'error' => 'not-published'];
        }

        if ($kod < 200 || $kod >= 300) {
            return ['body' => '', 'error' => 'The index answered ' . $kod . '.'];
        }

        return ['body' => (string) $tresc, 'error' => ''];
    }
}
