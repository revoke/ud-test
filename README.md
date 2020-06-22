# Návrh řešení ukládání oprávnění
Stačí spustit `composer update` a pak PHP soubor `index.php` přes CLI nebo browser.

Běží na PHP 7.2+

K otestování jsem využil Nette Tester - pokud je vše dle předpokladů zobrazí se *OK* a seznam uživatelů s přidělenými oprávněními. 

Test vyžaduje připojení k MySQL databázi, konfigurace připojení je v `config.neon`. Vytvoření tabulek a naplnění daty je pro tento účel v konstruktoru metody ACL. 

Navržené řešení ukládá data jako součet zvolených hodnot, což má své výhody (není zapotřebí další tabulky, menší objem uložených dat, hůře dotazovatelné, ...) i nevýhody (horší čitelnost, omezený počet kombinací, chybí časové razítko změn, ...).
