<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Robots\Storage;

use Configuration;

/**
 * Ustawienia modułu.
 *
 * **Wszystkie są globalne dla instalacji**, i czytamy je oraz zapisujemy zakresem
 * globalnym, nie kontekstem bieżącego sklepu. Wynika to z §9 specyfikacji:
 * `robots.txt` jest jeden na instalację, w katalogu głównym, więc rozdzielanie
 * tych ustawień po sklepach obiecywałoby wybór, którego plik i tak nie odda.
 *
 * Dwie pułapki PrestaShopa, na które ta klasa jest odpowiedzią:
 *
 * `Configuration::updateValue()` zapisuje **do bieżącego sklepu**, a nie globalnie.
 * Wartość zapisana z panelu jednego sklepu byłaby wtedy niewidoczna z drugiego,
 * choć plik, którym sterują, jest wspólny.
 *
 * `Configuration::hasKey()` **nie schodzi z zakresu globalnego do sklepu**. Wartość
 * zapisana przez `updateValue()` istnieje i `get()` ją zwraca, ale `hasKey()` mówi,
 * że jej nie ma. Odczyt oparty na `hasKey()` zwracałby więc wartość domyślną nawet
 * wtedy, gdy sprzedawca coś ustawił. Dlatego pytamy o wartość, nie o istnienie
 * klucza.
 */
final class Options
{
    /** Czy w ogóle dopisujemy własny blok do `robots.txt`. */
    public const ROBOTS_ON = 'QUINCE_ROBOTS_ON';

    /** Czy odrzucamy żądania botów zablokowanych, czy tylko prosimy w pliku. */
    public const ENFORCE_ON = 'QUINCE_ROBOTS_ENFORCE_ON';

    /** Czy zliczamy odwiedziny automatów. */
    public const LOG_ON = 'QUINCE_ROBOTS_LOG_ON';

    /**
     * Ile miesięcy trzymamy licznik odwiedzin.
     *
     * Termin bierze się z rozsądku, nie z przepisu: licznik nie przechowuje adresów
     * IP, więc nie niesie danych osobowych i żadna ustawa nie każe go kasować.
     * Kasujemy, bo dane sprzed roku nie odpowiadają już na żadne pytanie.
     */
    public const RETENTION_MONTHS = 'QUINCE_ROBOTS_RETENTION_MONTHS';

    /** Kiedy sprzątaliśmy ostatnio. Bez tego sprzątanie ruszałoby przy każdym wejściu do panelu. */
    public const LAST_PURGE = 'QUINCE_ROBOTS_LAST_PURGE';

    /** Ograniczanie częstości: włącznik, próg na minutę, czas odstawienia. */
    public const RATE_ON = 'QUINCE_ROBOTS_RATE_ON';
    public const RATE_LIMIT = 'QUINCE_ROBOTS_RATE_LIMIT';
    public const RATE_COOLDOWN = 'QUINCE_ROBOTS_RATE_COOLDOWN';

    /** Zapamiętana odpowiedź spisu Quince i czas jej pobrania. */
    public const INDEX_CACHE = 'QUINCE_ROBOTS_INDEX_CACHE';

    /** Skrót ustawień wpisany do `robots.txt`, po którym poznajemy, że plik jest aktualny. */
    public const FILE_STAMP = 'QUINCE_ROBOTS_FILE_STAMP';

    /**
     * Wartości zakładane przy instalacji.
     *
     * Egzekwowanie i ograniczanie częstości są **wyłączone**. Moduł po instalacji
     * ma deklarować, nie odcinać: źle dobrany próg odcina prawdziwych klientów,
     * a sprzedawca dowiaduje się o tym z reklamacji.
     *
     * @var array<string,int|string>
     */
    private const DOMYSLNE = [
        self::ROBOTS_ON => 1,
        self::ENFORCE_ON => 0,
        self::LOG_ON => 1,
        self::RETENTION_MONTHS => 3,
        self::LAST_PURGE => '',
        self::RATE_ON => 0,
        self::RATE_LIMIT => 120,
        self::RATE_COOLDOWN => 5,
        self::INDEX_CACHE => '',
        self::FILE_STAMP => '',
    ];

    /** Zakłada brakujące wartości. Istniejących nie rusza. */
    public static function installDefaults(): void
    {
        foreach (self::DOMYSLNE as $klucz => $wartosc) {
            if (!self::istnieje($klucz)) {
                Configuration::updateGlobalValue($klucz, $wartosc);
            }
        }
    }

    public static function removeAll(): void
    {
        foreach (array_keys(self::DOMYSLNE) as $klucz) {
            Configuration::deleteByName($klucz);
        }
    }

    public static function bool(string $klucz, bool $domyslna = false): bool
    {
        $wartosc = self::surowa($klucz);

        return $wartosc === null ? $domyslna : (bool) $wartosc;
    }

    public static function int(string $klucz, int $domyslna = 0): int
    {
        $wartosc = self::surowa($klucz);

        return $wartosc === null || $wartosc === '' ? $domyslna : (int) $wartosc;
    }

    public static function text(string $klucz, string $domyslna = ''): string
    {
        $wartosc = self::surowa($klucz);

        return $wartosc === null || $wartosc === '' ? $domyslna : (string) $wartosc;
    }

    /**
     * Zapis. `$html` jest konieczny przy wartościach niosących JSON: bez niego
     * PrestaShop przycina treść na pierwszym znaczniku.
     */
    public static function save(string $klucz, mixed $wartosc, bool $html = false): void
    {
        Configuration::updateGlobalValue($klucz, $wartosc, $html);
    }

    /**
     * Wartość albo `null`, gdy klucza nie ma nigdzie.
     *
     * Najpierw zakres globalny, potem zwykły odczyt. Drugi krok jest dla wartości
     * zapisanych wcześniejszą wersją modułu w zakresie sklepu. Bez niego
     * aktualizacja modułu wyglądałaby jak skasowanie ustawień.
     */
    private static function surowa(string $klucz): int|string|null
    {
        $globalna = Configuration::getGlobalValue($klucz);

        if ($globalna !== false && $globalna !== null) {
            return $globalna;
        }

        $zwykla = Configuration::get($klucz);

        return $zwykla !== false && $zwykla !== null ? $zwykla : null;
    }

    private static function istnieje(string $klucz): bool
    {
        return self::surowa($klucz) !== null;
    }
}
