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
use Quince\Robots\Storage\Options;
use Tools;

/**
 * Stan pliku `robots.txt` i jego odbudowa.
 *
 * Rdzeń generuje ten plik w trzech miejscach, ale hooki odpalają się tylko
 * w jednym — `Tools::generateRobotsFile($executeHook)` dostaje `true` wyłącznie
 * z `RobotsTextFileGenerator`, czyli z przycisku w panelu SEO. Wywołanie
 * z `AdminShopUrlController` przy zmianie adresu sklepu idzie **bez** hooków,
 * więc nasz blok znika po cichu i nikt się o tym nie dowiaduje.
 *
 * Ta klasa jest odpowiedzią na tamtą lukę: przy wejściu na ekran modułu sprawdzamy
 * plik tanim odczytem i mówimy wprost, że blok wyparował, zamiast czekać, aż ktoś
 * zauważy, że boty znów chodzą.
 */
final class RobotsFile
{
    public function __construct(private readonly BotRegistry $registry)
    {
    }

    /**
     * @return array{exists:bool,path:string,size:int,changed:?string,writable:bool,has_block:bool,stamp:string,current:bool,expected:string,needed:bool}
     */
    public function status(): array
    {
        $sciezka = $this->sciezka();
        $oczekiwany = $this->registry->stamp();
        $potrzebny = $this->registry->blocked() !== [];

        if (!is_file($sciezka)) {
            return [
                'exists' => false,
                'path' => $sciezka,
                'size' => 0,
                'changed' => null,
                'writable' => is_writable(dirname($sciezka)),
                'has_block' => false,
                'stamp' => '',
                'current' => !$potrzebny,
                'expected' => $oczekiwany,
                'needed' => $potrzebny,
            ];
        }

        $tresc = (string) file_get_contents($sciezka);
        $skrot = $this->skrotZPliku($tresc);
        $maBlok = $skrot !== null;

        return [
            'exists' => true,
            'path' => $sciezka,
            'size' => (int) filesize($sciezka),
            'changed' => date('Y-m-d H:i', (int) filemtime($sciezka)),
            'writable' => is_writable($sciezka),
            'has_block' => $maBlok,
            'stamp' => (string) $skrot,
            // Plik jest aktualny, gdy blok jest i niesie ten sam skrót co rejestr,
            // albo gdy bloku nie ma i nie ma czego blokować.
            'current' => $potrzebny ? $skrot === $oczekiwany : !$maBlok,
            'expected' => $oczekiwany,
            'needed' => $potrzebny,
        ];
    }

    /**
     * Każe rdzeniowi przepisać plik, razem z naszym hookiem.
     *
     * Nie piszemy do `robots.txt` sami i nie będziemy. Dwa procesy piszące do
     * jednego pliku kończą się plikiem uciętym w połowie, a poza tym cała reszta
     * jego treści — zakazy katalogów, mapa witryny, sekcje per domena — należy
     * do rdzenia i tylko on wie, jak ją złożyć.
     */
    public function rebuild(): bool
    {
        $ok = Tools::generateRobotsFile(true);

        if ($ok) {
            Options::save(Options::FILE_STAMP, $this->registry->stamp());
        }

        return $ok;
    }

    /**
     * Skrót ze znacznika otwierającego albo `null`, gdy bloku nie ma.
     *
     * Czytamy sam znacznik, a nie porównujemy całą treść: plik zmienia się przy
     * każdej zmianie adresu sklepu czy mapy witryny, a nas obchodzi wyłącznie to,
     * czy nasza część jest tą, którą chcieliśmy tam mieć.
     */
    private function skrotZPliku(string $tresc): ?string
    {
        $wzor = '/' . preg_quote(RobotsBlock::START, '/') . '\s+v\d+\s+([0-9a-f]{8})\s+===/';

        return preg_match($wzor, $tresc, $m) === 1 ? $m[1] : null;
    }

    private function sciezka(): string
    {
        return rtrim(_PS_ROOT_DIR_, '/\\') . DIRECTORY_SEPARATOR . 'robots.txt';
    }
}
