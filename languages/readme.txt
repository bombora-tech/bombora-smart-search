=== Jak funguje vícejazyčnost ===

Plugin dodává hotové překlady frontendu pro tyto jazyky:
- ru_RU (ruština)
- de_DE (němčina)
- sk_SK (slovenština)
- en_US (angličtina)
- fr_FR (francouzština)

Jak to funguje: WPML za návštěvníka podle vybraného jazyka automaticky
přepíná WordPress "locale" (např. na ruské verzi webu nastaví locale na
ru_RU). Plugin v tu chvíli sám najde a načte odpovídající soubor
zg-smart-search-{locale}.mo z této složky a použije jeho texty - žádné
další nastavení není potřeba, funguje to hned po nahrání pluginu.

Čeština je zdrojový/výchozí jazyk (texty přímo v kódu), takže pro ni
žádný .mo soubor není potřeba - použije se automaticky jako fallback,
pokud pro daný jazyk žádný .mo soubor neexistuje.

== Jak přidat další jazyk (např. polštinu) ==

Možnost A - nejrychlejší, přes WPML:
1. WPML -> Theme and plugin localization -> najít "Smart Search Advanced" a
   nechat WPML naskenovat řetězce (použije soubor zg-smart-search.pot
   z této složky).
2. WPML -> String Translation -> přeložit jednotlivé řetězce pro nový
   jazyk. WPML si texty pak sám přepíná podle jazyka stránky.

Možnost B - vlastní .mo/.po soubor:
1. Otevřete zg-smart-search.pot (šablona se všemi texty) např. v
   aplikaci Poedit.
2. Přeložte řetězce a uložte jako zg-smart-search-{locale}.po -
   {locale} je standardní WordPress locale kódu jazyka, např. pl_PL
   pro polštinu, it_IT pro italštinu, es_ES pro španělštinu (přesný
   kód locale daného jazyka najdete v nastavení WPML u příslušného
   jazyka).
3. Poedit při uložení automaticky vygeneruje i odpovídající .mo
   soubor - oba (.po i .mo) nahrajte do této složky /languages.
4. Hotovo, plugin nový jazyk automaticky použije.

== Text štítku "Bestseller" ==
Text štítku bestseller se nastavuje v administraci (WooCommerce ->
Smart Search Advanced -> Nastavení) a jde přeložit pro každý jazyk zvlášť přes
WPML -> String Translation (hledejte kontext "Smart Search Advanced",
řetězec "bestseller_badge_text"). Stejně tak placeholder textového
pole, pokud ho měníte přes atribut shortcode [zg_smart_search
placeholder="..."].
