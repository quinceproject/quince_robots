<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Robots\Traffic;

/**
 * Potwierdzenie, że robot jest tym, za kogo się podaje.
 *
 * Nagłówek nie jest dowodem: podszycie się pod Googlebota to jedna linia
 * w kliencie HTTP. Bez weryfikacji „zweryfikowany robot" nie znaczyłoby nic,
 * a na tym werdykcie inne moduły opierają decyzje o swoich danych.
 *
 * Procedura jest ta sama, którą zalecają Google i Bing, i ma **trzy** kroki:
 *
 * 1. adres wstecz na nazwę hosta
 * 2. nazwa musi kończyć się domeną producenta
 * 3. ta nazwa musi rozwiązywać się z powrotem na wyjściowy adres
 *
 * Krok trzeci jest tym, o którym się zapomina, a bez niego cała procedura jest
 * pozorna: kto ma własny adres, ustawia sobie wpis wsteczny kończący się na
 * `googlebot.com` i przechodzi sprawdzenie.
 *
 * Zapytania DNS są wolne, więc odpytujemy wyłącznie przy adresach, które pasowały
 * do wpisu w rejestrze **i** mają włączone egzekwowanie. Wynik pamiętamy do końca
 * żądania i nie zapisujemy go w bazie, żeby nie trzymać adresów IP.
 */
final class Verifier
{
    /** @var array<string,bool> wyniki policzone w tym żądaniu */
    private array $wPamieci = [];

    /**
     * Czy adres należy do producenta wskazanego przez wpis.
     *
     * Pusta domena znaczy, że producent nie daje się zweryfikować. Zwracamy
     * `false`, bo „nie wiem" nie jest tym samym co „tak".
     */
    public function verify(string $ip, string $domena): bool
    {
        if ($ip === '' || $domena === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        $klucz = $ip . '|' . $domena;

        return $this->wPamieci[$klucz] ??= $this->sprawdzDns($ip, $domena);
    }

    private function sprawdzDns(string $ip, string $domena): bool
    {
        $nazwa = @gethostbyaddr($ip);

        if ($nazwa === false || $nazwa === $ip) {
            return false;
        }

        // Kropka przed domeną, żeby `notgooglebot.com` nie przeszło jako `googlebot.com`.
        if (!str_ends_with($nazwa, '.' . $domena) && $nazwa !== $domena) {
            return false;
        }

        return $this->wracaDoAdresu($nazwa, $ip);
    }

    /**
     * Czy nazwa rozwiązuje się z powrotem na ten sam adres.
     *
     * Sprawdzamy obie rodziny adresów: robot przychodzący po IPv6 ma wpis AAAA,
     * a `gethostbynamel` zna wyłącznie IPv4 i milczałby o nim.
     */
    private function wracaDoAdresu(string $nazwa, string $ip): bool
    {
        foreach ((array) @gethostbynamel($nazwa) as $wsteczny) {
            if ($wsteczny === $ip) {
                return true;
            }
        }

        foreach ((array) @dns_get_record($nazwa, DNS_AAAA) as $wpis) {
            if (isset($wpis['ipv6']) && strtolower((string) $wpis['ipv6']) === strtolower($ip)) {
                return true;
            }
        }

        return false;
    }
}
