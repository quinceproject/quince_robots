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

use Db;
use Quince\Robots\Storage\Options;
use Quince\Robots\Storage\Schema;

/**
 * Próg częstości żądań z jednego adresu.
 *
 * Rozpoznawanie po nagłówku łapie tylko te automaty, które się przedstawiają.
 * Ruch, przed którym ten próg ma chronić, z definicji tego nie robi — podszywa
 * się pod przeglądarkę i przechodzi obok rejestru. Zostaje liczenie żądań.
 *
 * Licznik żyje minutami, nie miesiącami: interesuje nas ostatnich kilka minut,
 * a starsze wiersze są śmieciem i lecą przy pierwszej okazji.
 *
 * Domyślnie **wyłączony**. Źle dobrany próg odcina prawdziwych klientów,
 * a sprzedawca dowiaduje się o tym z reklamacji, nie z panelu.
 */
final class RateLimit
{
    /** Ile minut historii trzymamy ponad okres odstawienia. */
    private const ZAPAS_MINUT = 2;

    /**
     * Dokłada żądanie i mówi, czy adres ma być teraz odrzucany.
     *
     * Zwiększenie i odczyt w jednym kroku, bo między nimi nie ma nic do zrobienia,
     * a rozdzielenie ich dawałoby dwa zapytania na każde żądanie sklepu.
     */
    public function hit(string $ip): bool
    {
        if (!Options::bool(Options::RATE_ON, false) || $ip === '') {
            return false;
        }

        $minuta = date('Y-m-d H:i:00');
        $db = Db::getInstance();

        $db->execute(
            'INSERT INTO `' . _DB_PREFIX_ . Schema::TABLE_RATE . '` (`ip`, `minute`, `hits`)
             VALUES ("' . pSQL($ip) . '", "' . $minuta . '", 1)
             ON DUPLICATE KEY UPDATE `hits` = `hits` + 1'
        );

        // Sprzątanie przy jednym na sto żądań. Wołanie go za każdym razem dokładałoby
        // kasowanie do każdej odsłony sklepu, a tabela rośnie o wiersz na adres na
        // minutę — na tyle wolno, że opóźnienie o kilkadziesiąt żądań nic nie zmienia.
        if (random_int(1, 100) === 1) {
            $this->purge();
        }

        return $this->przekroczony($ip);
    }

    /**
     * Czy adres przekroczył próg w oknie odstawienia.
     *
     * Odstawienie jest tu potrzebne, bo licznik zeruje się z każdą minutą: bez niego
     * automat odrzucony w sekundzie 59 wracałby do gry w sekundzie 61 i cały próg
     * sprowadzałby się do chwilowego zadyszenia.
     */
    public function przekroczony(string $ip): bool
    {
        if ($ip === '') {
            return false;
        }

        $prog = max(1, Options::int(Options::RATE_LIMIT, 120));
        $od = date('Y-m-d H:i:00', strtotime('-' . $this->odstawienie() . ' minutes'));

        // Bez własnego `LIMIT` — `getValue()` dokłada swój i dwa obok siebie
        // są błędem składni.
        return (bool) Db::getInstance()->getValue(
            'SELECT 1 FROM `' . _DB_PREFIX_ . Schema::TABLE_RATE . '`
              WHERE `ip` = "' . pSQL($ip) . '" AND `minute` >= "' . $od . '" AND `hits` > ' . $prog
        );
    }

    /** Ile żądań przyszło z tego adresu w bieżącej minucie. */
    public function countFor(string $ip): int
    {
        if ($ip === '') {
            return 0;
        }

        return (int) Db::getInstance()->getValue(
            'SELECT `hits` FROM `' . _DB_PREFIX_ . Schema::TABLE_RATE . '`
              WHERE `ip` = "' . pSQL($ip) . '" AND `minute` = "' . date('Y-m-d H:i:00') . '"'
        );
    }

    /** Ile adresów jest teraz odstawionych. Do pokazania w ustawieniach. */
    public function tripped(): int
    {
        $prog = max(1, Options::int(Options::RATE_LIMIT, 120));
        $od = date('Y-m-d H:i:00', strtotime('-' . $this->odstawienie() . ' minutes'));

        return (int) Db::getInstance()->getValue(
            'SELECT COUNT(DISTINCT `ip`) FROM `' . _DB_PREFIX_ . Schema::TABLE_RATE . '`
              WHERE `minute` >= "' . $od . '" AND `hits` > ' . $prog
        );
    }

    /**
     * Kasuje minuty, które przestały być potrzebne.
     *
     * Granica idzie za okresem odstawienia z zapasem — skasowanie minuty, na którą
     * jeszcze patrzymy, wypuszczałoby odstawiony adres przed czasem.
     */
    public function purge(): int
    {
        $db = Db::getInstance();
        $granica = date('Y-m-d H:i:00', strtotime('-' . ($this->odstawienie() + self::ZAPAS_MINUT) . ' minutes'));

        $db->execute('DELETE FROM `' . _DB_PREFIX_ . Schema::TABLE_RATE . '` WHERE `minute` < "' . $granica . '"');

        return (int) $db->Affected_Rows();
    }

    private function odstawienie(): int
    {
        return max(1, Options::int(Options::RATE_COOLDOWN, 5));
    }
}
