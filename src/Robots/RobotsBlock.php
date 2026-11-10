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

use Quince\Robots\Registry\BotRegistry;
use Quince\Robots\Storage\Schema;

/**
 * Blok, który moduł dopisuje do `robots.txt`.
 *
 * Jedna reguła rządzi całą tą klasą i jest łatwa do przeoczenia:
 *
 * > Robot wybiera **jedną** grupę `User-agent` — najlepiej pasującą do siebie —
 * > i **ignoruje wszystkie pozostałe**, w tym `User-agent: *` (RFC 9309 §2.2.1).
 *
 * Stąd wniosek odwrotny do intuicji: **dozwolonemu robotowi nie piszemy nic**.
 * Wypisanie mu `User-agent: X` z `Allow: /` odcięłoby go od grupy ogólnej,
 * a wraz z nią od wszystkich zakazów rdzenia — koszyka, kasy, konta klienta,
 * `/modules/`, `/var/`. Zamiast „wpuść go" powstałoby „wpuść go wszędzie,
 * także tam, gdzie nikogo nie wpuszczamy".
 *
 * Grupę generujemy więc wyłącznie przy blokadzie, gdzie przejęcie całej grupy
 * jest nieszkodliwe: i tak zakazujemy wszystkiego.
 */
final class RobotsBlock
{
    public const START = '# === QUINCE-ROBOTS-START';
    public const END = '# === QUINCE-ROBOTS-END ===';

    /** Wersja formatu bloku. Zmiana pozwoli kiedyś rozpoznać blok starego kształtu. */
    private const WERSJA = 'v1';

    public function __construct(private readonly BotRegistry $registry)
    {
    }

    /**
     * Treść bloku razem ze znacznikami. Pusty napis, gdy nie ma czego blokować —
     * wtedy nie zostawiamy w pliku samych nagłówków bez zawartości.
     */
    public function render(): string
    {
        $zablokowane = $this->registry->blocked();

        if ($zablokowane === []) {
            return '';
        }

        $wiersze = [
            '',
            self::START . ' ' . self::WERSJA . ' ' . $this->registry->stamp() . ' ===',
            '# Managed by the Quince Robots module. Changes made here are overwritten.',
        ];

        foreach ($this->pogrupowane($zablokowane) as $kategoria => $boty) {
            $wiersze[] = '';
            $wiersze[] = '# ' . $this->naglowek($kategoria);

            foreach ($boty as $bot) {
                $wiersze[] = 'User-agent: ' . $bot['token'];
                $wiersze[] = 'Disallow: /';
                $wiersze[] = '';
            }

            // Ostatni pusty wiersz kategorii zbiera się z pustym wierszem następnej.
            array_pop($wiersze);
        }

        $wiersze[] = '';
        $wiersze[] = self::END;
        $wiersze[] = '';

        return implode(PHP_EOL, $wiersze);
    }

    /**
     * Grupowanie kategoriami służy człowiekowi, nie robotom. Plik `robots.txt`
     * bywa czytany przez sprzedawcę i przez audytora — „# AI training bots"
     * nad listą powie im więcej niż dwadzieścia grup pod rząd.
     *
     * @param array<int,array<string,mixed>> $boty
     *
     * @return array<string,array<int,array<string,mixed>>>
     */
    private function pogrupowane(array $boty): array
    {
        $out = [];

        foreach (Schema::CATEGORIES as $kategoria) {
            $wKategorii = array_values(array_filter(
                $boty,
                static fn (array $b): bool => $b['category'] === $kategoria
            ));

            if ($wKategorii !== []) {
                $out[$kategoria] = $wKategorii;
            }
        }

        return $out;
    }

    private function naglowek(string $kategoria): string
    {
        $naglowki = [
            'answer' => 'Answer engines: blocked by the shop',
            'training' => 'AI training bots: blocked by the shop',
            'seo' => 'SEO tools: blocked by the shop',
            'monitoring' => 'Monitoring: blocked by the shop',
            'other' => 'Other bots: blocked by the shop',
        ];

        return $naglowki[$kategoria] ?? 'Blocked by the shop';
    }
}
