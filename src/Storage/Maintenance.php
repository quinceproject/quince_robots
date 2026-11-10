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

use Quince\Robots\Traffic\RateLimit;
use Quince\Robots\Traffic\TrafficLog;

/**
 * Sprzątanie po module: kasowanie danych, które przestały być potrzebne.
 *
 * PrestaShop nie daje modułom własnego zegara — moduł `cronjobs` bywa
 * zainstalowany, ale zakładanie go nie jest wolno. Zostaje uruchamianie
 * sprzątania przy okazji cudzej pracy, i wybór jest tylko jeden: albo przy
 * żądaniu odwiedzającego, albo przy otwarciu panelu.
 *
 * Wybieramy panel. Odwiedzający nie ma czekać na kasowanie wierszy, których
 * istnienia nie jest świadom, a pracownik i tak czeka na wczytanie strony
 * panelu. Raz na dobę, bo częściej nie ma czego kasować.
 *
 * Konsekwencję trzeba nazwać wprost: **sklep, do którego nikt się nie loguje,
 * nie sprząta.** Jest to do przyjęcia, bo do panelu wchodzi się po zamówienia,
 * a sklep bez zamówień i bez logowań nie ma też ruchu do skasowania.
 */
final class Maintenance
{
    /** Ile godzin czekamy między przebiegami. */
    private const CO_GODZIN = 24;

    /**
     * Uruchamia sprzątanie, jeśli minęła doba od poprzedniego.
     *
     * @return array{ran:bool,days:int,minutes:int}
     */
    public static function run(bool $wymusz = false): array
    {
        if (!$wymusz && !self::czas()) {
            return ['ran' => false, 'days' => 0, 'minutes' => 0];
        }

        // Znacznik ustawiamy **przed** kasowaniem, nie po. Gdyby kasowanie się
        // wywróciło, zapis po fakcie nigdy by nie nastąpił i każde kolejne wejście
        // do panelu próbowałoby tego samego od nowa.
        Options::save(Options::LAST_PURGE, date('Y-m-d H:i:s'));

        return [
            'ran' => true,
            'days' => (new TrafficLog())->purge(),
            'minutes' => (new RateLimit())->purge(),
        ];
    }

    /** Kiedy sprzątaliśmy ostatnio. Pusty napis, gdy jeszcze nigdy. */
    public static function lastRun(): string
    {
        return Options::text(Options::LAST_PURGE);
    }

    private static function czas(): bool
    {
        $ostatnie = Options::text(Options::LAST_PURGE);

        if ($ostatnie === '') {
            return true;
        }

        return strtotime($ostatnie) < strtotime('-' . self::CO_GODZIN . ' hours');
    }
}
