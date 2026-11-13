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
 * Licznik odwiedzin automatów, dzień po dniu.
 *
 * Nie zapisujemy żądań, tylko to, że dany bot był dziś tyle a tyle razy.
 * Wiersz na żądanie dawałby na średnim sklepie miliony wierszy w kwartał.
 *
 * Adresów IP nie przechowujemy, więc ta tabela nie niesie danych osobowych.
 */
final class TrafficLog
{
    /** Ile wierszy kasujemy w jednym przebiegu, żeby nie blokować tabeli. */
    private const PORCJA = 5000;

    /**
     * Dokłada jedno wejście do licznika.
     *
     * `ON DUPLICATE KEY UPDATE` zamiast odczytu i zapisu: przy dwóch żądaniach
     * w tej samej chwili odczyt-modyfikacja-zapis gubi jedno z nich, a baza
     * potrafi zwiększyć licznik atomowo.
     */
    public function record(
        int $idShop,
        string $botKey,
        string $userAgent,
        bool $verified = false,
        bool $blocked = false
    ): bool {
        if (!Options::bool(Options::LOG_ON, true)) {
            return false;
        }

        // Bot z rejestru rozróżniamy nazwą, więc wersja nagłówka jest bez znaczenia.
        // Automat nierozpoznany nie ma nazwy i rozróżnia go wyłącznie nagłówek.
        $skrot = $botKey === '' ? md5($userAgent) : '';
        $teraz = date('Y-m-d H:i:s');

        return (bool) Db::getInstance()->execute(
            'INSERT INTO `' . _DB_PREFIX_ . Schema::TABLE_DAY . '`
                (`id_shop`, `day`, `bot_key`, `ua_hash`, `user_agent`, `hits`, `blocked`, `verified`, `first_seen`, `last_seen`)
             VALUES (' . $idShop . ', "' . date('Y-m-d') . '", "' . pSQL($botKey) . '", "' . $skrot . '",
                     "' . pSQL(mb_substr($userAgent, 0, 255)) . '", 1, ' . (int) $blocked . ', ' . (int) $verified . ',
                     "' . $teraz . '", "' . $teraz . '")
             ON DUPLICATE KEY UPDATE
                `hits` = `hits` + 1,
                `blocked` = `blocked` + ' . (int) $blocked . ',
                `verified` = GREATEST(`verified`, ' . (int) $verified . '),
                `last_seen` = "' . $teraz . '"'
        );
    }

    /**
     * Ruch rozpoznanych robotów, od najczęstszego.
     *
     * @param array<int,int> $idShops
     *
     * @return array<int,array{bot_key:string,hits:int,blocked:int,verified:bool,days:int,first:string,last:string}>
     */
    public function byBot(array $idShops, int $dni = 30): array
    {
        $wiersze = Db::getInstance()->executeS(
            'SELECT `bot_key`,
                    SUM(`hits`) AS `hits`,
                    SUM(`blocked`) AS `blocked`,
                    MAX(`verified`) AS `verified`,
                    COUNT(*) AS `days`,
                    MIN(`first_seen`) AS `first`,
                    MAX(`last_seen`) AS `last`
               FROM `' . _DB_PREFIX_ . Schema::TABLE_DAY . '`
              WHERE `bot_key` <> "" AND `id_shop` IN (' . $this->sklepy($idShops) . ')
                AND `day` >= "' . date('Y-m-d', strtotime('-' . max(1, $dni) . ' days')) . '"
              GROUP BY `bot_key`
              ORDER BY `hits` DESC'
        ) ?: [];

        return array_map(static fn (array $w): array => [
            'bot_key' => (string) $w['bot_key'],
            'hits' => (int) $w['hits'],
            'blocked' => (int) $w['blocked'],
            'verified' => (bool) $w['verified'],
            'days' => (int) $w['days'],
            'first' => (string) $w['first'],
            'last' => (string) $w['last'],
        ], $wiersze);
    }

    /**
     * Automaty, których rejestr nie zna.
     *
     * To jest właściwy powód, dla którego ten licznik istnieje: nowy bot pojawia
     * się w sieci wcześniej, niż trafia na jakąkolwiek listę. Tutaj widać go
     * pierwszego dnia i można go dodać ręcznie, nie czekając na spis.
     *
     * @param array<int,int> $idShops
     *
     * @return array<int,array{user_agent:string,hits:int,days:int,last:string}>
     */
    public function unknown(array $idShops, int $dni = 30, int $limit = 50): array
    {
        $wiersze = Db::getInstance()->executeS(
            'SELECT `user_agent`, SUM(`hits`) AS `hits`, COUNT(*) AS `days`, MAX(`last_seen`) AS `last`
               FROM `' . _DB_PREFIX_ . Schema::TABLE_DAY . '`
              WHERE `bot_key` = "" AND `user_agent` <> "" AND `id_shop` IN (' . $this->sklepy($idShops) . ')
                AND `day` >= "' . date('Y-m-d', strtotime('-' . max(1, $dni) . ' days')) . '"
              GROUP BY `ua_hash`, `user_agent`
              ORDER BY `hits` DESC
              LIMIT ' . max(1, $limit)
        ) ?: [];

        return array_map(static fn (array $w): array => [
            'user_agent' => (string) $w['user_agent'],
            'hits' => (int) $w['hits'],
            'days' => (int) $w['days'],
            'last' => (string) $w['last'],
        ], $wiersze);
    }

    /** Ile dni historii czeka na skasowanie. Do pokazania obok terminu w ustawieniach. */
    public function pending(): int
    {
        return (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . Schema::TABLE_DAY . '` WHERE `day` < "' . $this->granica() . '"'
        );
    }

    /** Kasuje dni po terminie. Zwraca liczbę usuniętych wierszy. */
    public function purge(): int
    {
        $db = Db::getInstance();

        $db->execute(
            'DELETE FROM `' . _DB_PREFIX_ . Schema::TABLE_DAY . '`
              WHERE `day` < "' . $this->granica() . '"
              LIMIT ' . self::PORCJA
        );

        return (int) $db->Affected_Rows();
    }

    private function granica(): string
    {
        $miesiace = max(1, Options::int(Options::RETENTION_MONTHS, 3));

        return pSQL(date('Y-m-d', strtotime('-' . $miesiace . ' months')));
    }

    /**
     * Lista identyfikatorów do klauzuli `IN`. Dla pustej listy zwraca `0`,
     * ponieważ `IN ()` jest błędem składni MySQL.
     *
     * @param array<int,int> $idShops
     */
    private function sklepy(array $idShops): string
    {
        $lista = array_map('intval', $idShops);

        return $lista === [] ? '0' : implode(',', $lista);
    }
}
