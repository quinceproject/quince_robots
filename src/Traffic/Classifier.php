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

use Quince\Robots\Registry\BotRegistry;
use Quince\Robots\Storage\Schema;

/**
 * Kto puka: rozpoznanie żądania i werdykt dla reszty linii Quince.
 *
 * Werdykt ma **trzy** stany, nie dwa, i to rozróżnienie jest tu najważniejsze:
 *
 * - `crawler` — robot z rejestru, potwierdzony odwrotnym DNS-em. Wolno mu pokazać
 *   sklep w komplecie i nie zapisujemy po nim śladu w cudzych dziennikach
 * - `suspected` — nagłówek pasuje do wpisu, ale potwierdzenia nie ma
 * - `human` — nagłówek nie pasuje do niczego w rejestrze
 *
 * Co z tego wynika, zależy od pytania, które odbiorca sobie zadaje, i te dwa
 * pytania mają różne odpowiedzi:
 *
 * **„Czy pokazać mu sklep bez ograniczeń?"** — tylko przy `crawler`. Gdyby
 * wystarczało „wygląda na bota", scraper podszywający się pod Googlebota
 * dostawałby odblokowaną stronę na samo słowo.
 *
 * **„Czy zapisać jego działanie jako decyzję człowieka?"** — nie, przy `crawler`
 * **i** przy `suspected`. Tu potwierdzenie nie jest potrzebne: coś, co przedstawia
 * się jako GPTBot, niczego nie postanawia, niezależnie od tego, czy naprawdę nim
 * jest. Dziennik zgód, do którego trafiają takie wpisy, przestaje być dowodem.
 *
 * Zatrzymywanie ruchu nie należy do tej klasy ani do żadnego innego modułu linii.
 * Ona odpowiada wyłącznie na pytanie „kto to jest"; co z tego wynika, rozstrzyga
 * każdy moduł u siebie.
 */
final class Classifier
{
    public function __construct(
        private readonly BotRegistry $registry,
        private readonly Verifier $verifier
    ) {
    }

    /**
     * @return array{verdict:string,bot:string,category:string,verified:bool,policy:string,token:string,enforce:bool}
     */
    public function classify(string $userAgent, string $ip): array
    {
        $czlowiek = [
            'verdict' => 'human',
            'bot' => '',
            'category' => '',
            'verified' => false,
            'policy' => Schema::POLICY_ALLOW,
            'token' => '',
            'enforce' => false,
        ];

        $wpis = $this->registry->match($userAgent);

        if ($wpis === null || !$wpis['active']) {
            return $czlowiek;
        }

        // Do DNS-u sięgamy wyłącznie wtedy, gdy odpowiedź może cokolwiek zmienić,
        // czyli przy wpisie, który ma zostać wyegzekwowany. W pozostałych
        // przypadkach dwa zapytania sieciowe na żądanie kupiłyby nam wiedzę,
        // z której nikt nie skorzysta.
        //
        // `verified` pozostaje więc faktem, a nie oceną: fałsz znaczy „nie mamy
        // potwierdzenia", nie „to oszust". Odbiorca, którego interesuje wyłącznie
        // to, czy ma do czynienia z automatem, patrzy na `verdict`.
        $doSprawdzenia = $wpis['verify_host'] !== ''
            && $wpis['enforce']
            && $wpis['effective_policy'] === Schema::POLICY_BLOCK;

        $potwierdzony = $doSprawdzenia && $this->verifier->verify($ip, $wpis['verify_host']);

        return [
            // Bez potwierdzenia zostajemy przy „podejrzany" — nazwa w nagłówku
            // jest deklaracją, nie dowodem.
            'verdict' => $potwierdzony ? 'crawler' : 'suspected',
            'bot' => $wpis['key'],
            'category' => $wpis['category'],
            'verified' => $potwierdzony,
            'policy' => $wpis['effective_policy'],
            'token' => $wpis['token'],
            'enforce' => $wpis['enforce'],
        ];
    }

    /**
     * Czy to żądanie należy odrzucić.
     *
     * Trzy warunki naraz, i każdy z nich jest potrzebny:
     *
     * - decyzja sprzedawcy to blokada,
     * - wpis ma włączone egzekwowanie — bo przy bocie honorującym `robots.txt`
     *   odrzucanie żądań jest zbędne, a przy pomyłce w regule kosztowne,
     * - robot jest **potwierdzony**. To brzmi odwrotnie do intuicji, ale bez tego
     *   wystarczyłoby podać się za zablokowanego bota, żeby wywołać naszą blokadę
     *   — albo za dozwolonego, żeby ją ominąć. Odrzucamy więc tylko tych, o których
     *   wiemy, kim są. Reszta jest sprawą warstwy od ruchu, nie naszą.
     *
     * @param array{verdict:string,policy:string,enforce:bool,verified:bool} $werdykt
     */
    public function shouldBlock(array $werdykt): bool
    {
        return $werdykt['policy'] === Schema::POLICY_BLOCK
            && $werdykt['enforce']
            && $werdykt['verified'];
    }
}
