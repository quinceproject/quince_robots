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

use Db;

/**
 * Tabele modułu.
 *
 * Trzy: rejestr robotów, dzienny licznik odwiedzin i krótkotrwały licznik
 * częstości.
 *
 * Rejestr **nie ma kolumny sklepu**. `robots.txt` jest jeden na instalację,
 * w katalogu głównym, więc nie da się zablokować bota w jednym
 * sklepie, a wpuścić w drugim, jeśli oba stoją na tej samej instalacji.
 * Kolumna sklepu obiecywałaby wybór, którego nie ma.
 *
 * Licznik odwiedzin ma sklep, bo tam jest znany z żądania i jest do czego go
 * przypisać.
 */
final class Schema
{
    public const TABLE_BOT = 'quince_robots_bot';
    public const TABLE_DAY = 'quince_robots_day';
    public const TABLE_RATE = 'quince_robots_rate';

    /** Skąd wpis pochodzi. Decyduje o tym, co wolno przy odświeżeniu nadpisać. */
    public const SOURCE_BUILTIN = 'builtin';
    public const SOURCE_MANIFEST = 'manifest';
    public const SOURCE_CUSTOM = 'custom';

    public const POLICY_ALLOW = 'allow';
    public const POLICY_BLOCK = 'block';

    /**
     * Kategorie robotów. Decyzja zwykle zapada wobec całej grupy, nie wobec
     * pojedynczego bota, więc kategoria jest w module pojęciem pierwszej klasy,
     * a nie etykietą opisową.
     */
    public const CATEGORIES = ['search', 'answer', 'training', 'seo', 'monitoring', 'other'];

    /**
     * Kolumny dokładane do tabel, które już istnieją.
     *
     * @var array<string,array<string,string>>
     */
    private const DOLOZONE = [
        self::TABLE_BOT => [
            'category_own' => 'TINYINT(1) UNSIGNED NOT NULL DEFAULT 0',
            'off_list_since' => 'DATE NULL DEFAULT NULL',
        ],
    ];

    public function installTables(): bool
    {
        foreach ($this->definicje() as $zapytanie) {
            if (!Db::getInstance()->execute($zapytanie)) {
                return false;
            }
        }

        return $this->uzupelnijKolumny();
    }

    public function removeTables(): bool
    {
        $prefix = _DB_PREFIX_;

        // `quince_robots_hit` to tabela z poprzedniej postaci dziennika, kasowana, żeby
        // odinstalowanie nie zostawiało po module niczego.
        foreach ([self::TABLE_RATE, self::TABLE_DAY, 'quince_robots_hit', self::TABLE_BOT] as $tabela) {
            if (!Db::getInstance()->execute('DROP TABLE IF EXISTS `' . $prefix . $tabela . '`')) {
                return false;
            }
        }

        return true;
    }

    /**
     * Dokłada brakujące kolumny do tabel, które już istnieją.
     *
     * Bezpieczne do wielokrotnego uruchomienia: pomija to, co zastanie. Dzięki temu
     * instalacja od zera i aktualizacja kończą się tym samym schematem.
     */
    public function uzupelnijKolumny(): bool
    {
        $prefix = _DB_PREFIX_;

        foreach (self::DOLOZONE as $tabela => $kolumny) {
            $istniejace = $this->kolumny($tabela);

            if ($istniejace === []) {
                continue;
            }

            foreach ($kolumny as $nazwa => $definicja) {
                if (in_array($nazwa, $istniejace, true)) {
                    continue;
                }

                if (!Db::getInstance()->execute(
                    'ALTER TABLE `' . $prefix . $tabela . '` ADD COLUMN `' . $nazwa . '` ' . $definicja
                )) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @return array<int,string>
     */
    private function definicje(): array
    {
        $prefix = _DB_PREFIX_;
        $silnik = _MYSQL_ENGINE_;

        return [
            // --- rejestr robotów -------------------------------------------------
            //
            // `bot_key`, nie `key`: `key` jest słowem zastrzeżonym MySQL-a i zapytanie
            // bez odwrotnych apostrofów wywracałoby się w sposób trudny do odczytania.
            //
            // `policy` pusty znaczy „bierz `default_policy`". Rozdzielenie tych dwóch
            // kolumn jest po to, żeby odświeżenie spisu mogło zmienić naszą rekomendację,
            // nie dotykając decyzji sprzedawcy.
            //
            // `off_list_since` to data, od której wpisu nie ma już na liście źródłowej;
            // pusta znaczy, że jest. Wpisu wtedy nie kasujemy ani nie wyłączamy:
            // zniknięcie z listy bywa cudzym porządkowaniem, a nie znakiem, że bot
            // przestał chodzić.
            //
            // `respects` pochodzi z listy źródłowej i mówi, czy bot honoruje `robots.txt`.
            // To jedyna przesłanka, na której sensownie włącza się egzekwowanie po stronie
            // serwera. Przy bocie, który plik szanuje, jest ono zbędne.
            'CREATE TABLE IF NOT EXISTS `' . $prefix . self::TABLE_BOT . '` (
                `id_robots_bot` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                `bot_key` VARCHAR(64) NOT NULL,
                `token` VARCHAR(128) NOT NULL,
                `label` VARCHAR(128) NOT NULL DEFAULT "",
                `vendor` VARCHAR(128) NOT NULL DEFAULT "",
                `category` VARCHAR(16) NOT NULL DEFAULT "other",
                `description` VARCHAR(500) NOT NULL DEFAULT "",
                `docs_url` VARCHAR(255) NOT NULL DEFAULT "",
                `verify_host` VARCHAR(128) NOT NULL DEFAULT "",
                `respects` VARCHAR(8) NOT NULL DEFAULT "unclear",
                `default_policy` VARCHAR(8) NOT NULL DEFAULT "' . self::POLICY_ALLOW . '",
                `policy` VARCHAR(8) NOT NULL DEFAULT "",
                `category_own` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
                `enforce` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
                `source` VARCHAR(16) NOT NULL DEFAULT "' . self::SOURCE_BUILTIN . '",
                `off_list_since` DATE NULL DEFAULT NULL,
                `active` TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
                `date_add` DATETIME NOT NULL,
                `date_upd` DATETIME NOT NULL,
                PRIMARY KEY (`id_robots_bot`),
                UNIQUE KEY `uniq_key` (`bot_key`),
                KEY `idx_category` (`category`)
            ) ENGINE=' . $silnik . ' DEFAULT CHARSET=utf8mb4',

            // --- dzienny licznik odwiedzin ---------------------------------------
            //
            // Jeden wiersz na parę „bot + dzień", a każde wejście to `+1` do licznika.
            // Wiersz na żądanie dawałby na średnim sklepie miliony wierszy w kwartał.
            //
            // Klucz zawiera `ua_hash`, bo nierozpoznane automaty nie mają nazwy
            // w rejestrze i rozróżnia je wyłącznie nagłówek. Przy botach z rejestru
            // `ua_hash` jest pusty: wersja nagłówka nas nie interesuje.
            'CREATE TABLE IF NOT EXISTS `' . $prefix . self::TABLE_DAY . '` (
                `id_robots_day` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_shop` INT(11) UNSIGNED NOT NULL DEFAULT 1,
                `day` DATE NOT NULL,
                `bot_key` VARCHAR(64) NOT NULL DEFAULT "",
                `ua_hash` CHAR(32) NOT NULL DEFAULT "",
                `user_agent` VARCHAR(255) NOT NULL DEFAULT "",
                `hits` INT(11) UNSIGNED NOT NULL DEFAULT 0,
                `blocked` INT(11) UNSIGNED NOT NULL DEFAULT 0,
                `verified` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
                `first_seen` DATETIME NOT NULL,
                `last_seen` DATETIME NOT NULL,
                PRIMARY KEY (`id_robots_day`),
                UNIQUE KEY `uniq_day` (`id_shop`, `day`, `bot_key`, `ua_hash`),
                KEY `idx_day` (`day`),
                KEY `idx_bot` (`bot_key`)
            ) ENGINE=' . $silnik . ' DEFAULT CHARSET=utf8mb4',

            // --- licznik częstości ------------------------------------------------
            //
            // Osobno od licznika dziennego, bo mierzy co innego i żyje inaczej długo:
            // dane z ostatniej minuty, kasowane po kilku. Adres IP w kluczu, bo ruch,
            // przed którym próg ma chronić, z definicji się nie przedstawia.
            'CREATE TABLE IF NOT EXISTS `' . $prefix . self::TABLE_RATE . '` (
                `id_robots_rate` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                `ip` VARCHAR(45) NOT NULL,
                `minute` DATETIME NOT NULL,
                `hits` INT(11) UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`id_robots_rate`),
                UNIQUE KEY `uniq_ip_minute` (`ip`, `minute`),
                KEY `idx_minute` (`minute`)
            ) ENGINE=' . $silnik . ' DEFAULT CHARSET=utf8mb4',
        ];
    }

    /**
     * @return array<int,string>
     */
    private function kolumny(string $tabela): array
    {
        $wiersze = Db::getInstance()->executeS('SHOW COLUMNS FROM `' . _DB_PREFIX_ . $tabela . '`', true, false);

        return is_array($wiersze) ? array_column($wiersze, 'Field') : [];
    }
}
