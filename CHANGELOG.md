# Changelog – Bombora Smart Search

## [2.0.1] – 2026-11-23
- Bezpečnější souběh se starou složkou: když je stará verze ještě aktivní, nová kopie se nenačte (žádná kolize funkcí/tříd, běží stará verze, dokud převod nedokončí Bombora Manager).

## [2.0.0] – 2026-11-23
- Přejmenováno na **Bombora Smart Search**; složka pluginu `bombora-smart-search` (dříve `zg-smart-search`), hlavní soubor `bombora-smart-search.php`. Přechod provede Bombora Manager 1.1+ (nastavení, statistiky i index zůstávají – jsou v databázi pod stejnými klíči).
- Menu přesunuto pod **Bombora** (pokud je nainstalovaný Manager), adresa `admin.php?page=zgss-settings` beze změny.
- Pojistka proti současnému načtení dvou kopií pluginu.
- Překlady frontendu doplněny o `nl_NL`, `ru_RU`, `en_GB` (máme CS, SK, DE, FR, NL, EN, RU). Text domain `zg-smart-search` beze změny, takže vlastní překlady (Loco/WPML) platí dál.

## [1.9.3] – 2026-11-23
- Přidány překlady frontendu: slovenština (`sk_SK`), angličtina (`en_US`, degeorgie.fr) a francouzština (`fr_FR`). Na zgruzinska.sk a degeorgie.fr se ve vyhledávání dosud zobrazovaly české texty.

## [1.9.2] – 2026-11-23
- Německý překlad doplněn o „Předobjednávka“ → „Vorbestellung“ (stav skladu ve výsledcích).

## [1.9.1] – 2026-11-23
- Přidán německý překlad `languages/zg-smart-search-de_DE.l10n.php` (z allesgeorgische.de) – při aktualizaci přes Manager se už nesmaže.

## [1.9.0] – 2026-11-23
- První verze v repozitáři bombora-tech/bombora-smart-search (zdroj: produkční instalace uitgeorgie.nl).
- Přidána hlavička Update URI – aktualizace spravuje Bombora Manager přes GitHub Releases (ověření SHA-256).
