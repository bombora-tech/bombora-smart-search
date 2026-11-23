=== Smart Search Advanced ===
Vlastní plugin pro Bombora group s.r.o. (zGruzie.cz / allesgeorgische.de / zgruzinska.sk)

== Co plugin dělá ==
1. Live vyhledávání produktů přes AJAX s tolerancí překlepů (fuzzy matching
   - kombinace "starts with", "contains", Levenshteinovy vzdálenosti
   a similar_text procentuální shody). Funguje na název produktu, SKU
   a kategorie.
2. Vizuál podobný přiloženým screenshotům: vlevo "Oblíbená vyhledávání"
   (chipy) a "Kategórie", vpravo mřížka produktů se štítkem slevy,
   skladovou dostupností a cenou, tlačítko "Více výsledků".
3. "Moderní" doporučovací vrstva:
   - Bestsellery (podle total_sales) jako výchozí stav při otevření pole.
   - Related produkty (nativní WooCommerce logika) navázané na první
     nalezenou shodu, zobrazené jako "Mohlo by se vám líbit".
   - Personalizace pro přihlášené zákazníky: doporučení jsou vážená podle
     kategorií, ve kterých zákazník v posledních objednávkách nejčastěji
     nakupoval.
4. Sledování hledaných výrazů (vlastní DB tabulka) -> sekce "Oblíbená
   vyhledávání" se plní reálnými daty z provozu shopu.
5. Plná podpora WPML: WooCommerce Multilingual automaticky filtruje
   produkty podle aktuálního jazyka, index se navíc cachuje odděleně
   pro každý jazyk. UI texty pluginu jsou zaregistrované do WPML String
   Translation (přes wpml-config.xml a icl_register_string).
6. Zvýraznění shodné části názvu ve výsledcích (světle zelené pozadí,
   tmavě zelený text) - tolerantní na diakritiku i drobné překlepy.
7. Rozpoznávání ruské azbuky - dotaz "киндзмараули" najde produkt
   "Kindzmarauli" a naopak, pokud by byl název produktu v ruské
   jazykové verzi uložen azbukou. Funguje automaticky, bez nastavení.
8. V nastavení lze vybrat produkty, které se mají z vyhledávání i
   doporučení úplně vyloučit (našeptávač s vyhledáváním podle názvu).
9. Záložka "Náhrady" - ruční slovník synonym/typo (stejný princip jako
   "Náhrady" v pluginu Magrano). Pravidla mají přednost před automatickým
   fuzzy algoritmem, takže jsou spolehlivější pro konkrétní, opakující se
   dotazy (typické překlepy, cizí výrazy, formátování typu "750" -> "0,75").
10. Záložka "Statistika" - přehled hledaných výrazů (počet hledání, počet
    nalezených produktů, kdy naposledy), se zvýrazněním výrazů bez
    výsledků - to jsou nejlepší tipy na nové "Náhrady" nebo mezery v
    katalogu.

== Rozdíl oproti přesnému kopírování Magrano ==
Magrano navíc sleduje konverze (kolik objednávek/tržeb přišlo z
vyhledávání vs. bez něj) - to by v našem pluginu vyžadovalo párování
vyhledávací session s dokončenou objednávkou (cookie/session tracking
napříč celým nákupním procesem), což je citelně větší kus práce než
zbytek pluginu. Pokud je to pro vás důležité, dá se to dodělat jako
další krok - zatím plugin eviduje jen samotné hledané výrazy a jejich
úspěšnost (počet výsledků), ne návaznost na objednávky.

== Verze 1.7.2 ==
- Pole hledání na tabletu a mobilu na šířku (cca 481-1024px) je teď
  o něco menší (tenčí padding, menší tlačítko lupy, menší písmo) -
  na úzkém mobilu (do 480px) a na laptopu/desktopu beze změny, tam to
  bylo podle zpětné vazby v pořádku.

== Verze 1.7.1 ==
- Pole hledání teď na mobilu a tabletu (do 1024px) vždy zabírá celou
  dostupnou šířku, i bez kliknutí/fokusu - dřív se mohlo zobrazovat
  užší/vystředěné a na celou šířku se rozšířilo až po interakci.
  Pravidlo je schválně s !important, ať přebije případné omezení
  šířky z obalového elementu šablony.

== Verze 1.7.0 ==
- Na mobilu se kategorie teď zobrazují úplně dole, až za výsledky
  vyhledávání (dřív byly nahoře spolu s "Oblíbená vyhledávání").
  Technicky přes CSS grid s pojmenovanými oblastmi - na desktopu jsou
  kategorie a oblíbená hledání pořád spolu v levém sloupci vedle
  výsledků, jen na mobilu se přeskupí do jiného pořadí.
- Oprava: okno výsledků na mobilu se ukotvovalo natvrdo ke spodnímu
  okraji obrazovky bez ohledu na to, kde skutečně leží pole hledání -
  u vyšších obrazovek/kratších headerů tak mohlo pole hledání
  překrýt. Pozice "top" se teď počítá stejně jako na desktopu podle
  skutečné polohy pole, jen šířka/okraje zůstávají přes CSS na celou
  obrazovku (bottom sheet).

== Verze 1.6.0 ==
- Opravena reálná příčina "rozbitých stylů, co se pak po chvilce
  dočtou": pravidlo .zgss-search__dropdown{display:flex} mělo stejnou
  specificitu jako prohlížečovo výchozí pravidlo pro atribut [hidden],
  a jako autorský styl ho přebíjelo - prázdný box dropdownu tak byl
  ve skutečnosti viditelný na stránce pořád, i mimo vyhledávání, jen
  to nebylo vidět, dokud se nenačetlo naše CSS. Přidáno explicitní
  pravidlo, které [hidden] vždy vyhraje.
- Přidána malá kritická CSS přímo v HTML výstupu shortcode pro
  samotné pole hledání (border, tlačítko), ať nevypadá "syrové" ani v
  tom krátkém okně, než se stihne načíst externí styl.
- Mobilní zobrazení výsledků přepracováno na seznam (foto vlevo, text
  vpravo) místo mřížky karet - podobně jako referenční mobilní
  zobrazení. Panel se navíc na mobilu chová jako "bottom sheet" -
  vysune se od spodního okraje obrazovky přes téměř celou výšku, s
  úchytem nahoře a tlačítkem pro zavření v pravém horním rohu.
- Přidáno tlačítko zavření (×) v rohu okna výsledků - funguje na
  desktopu i mobilu.

== Verze 1.5.0 ==
- Oprava: na některých webech se vyhledávací okno zobrazovalo POD
  menu/hlavičkou webu (problém se stacking kontextem - z-index dítěte
  nikdy nepřeskočí strop daný stacking kontextem nadřazeného elementu,
  ať je nastavený na jakkoliv vysoké číslo). Řešení: okno výsledků a
  šipka se teď při otevření přesunou přímo pod <body> ("portál") a
  jejich pozice se dopočítává v JS podle skutečné polohy pole hledání
  - tím se zaručeně dostanou nad úplně všechny ostatní prvky stránky,
  nezávisle na struktuře konkrétního webu/šablony.
- V rámci té opravy přesunuty CSS proměnné (barvy) z .zgss-search do
  :root, ať je okno po přesunu pod <body> pořád správně obarvené.

== Verze 1.4.0 ==
- Plugin přejmenován z "ZG Smart Search" na "Smart Search Advanced"
  (název v administraci, v menu WooCommerce i v WPML String
  Translation). Interní technické názvy (složka, prefixy funkcí/tříd,
  textová doména, názvy databázových tabulek) zůstávají beze změny,
  ať přejmenování nic nerozbije - viz poznámka níže.
- Hover na název kategorie teď používá červené pozadí místo šedého.

Poznámka k přejmenování: pokud jste si už dřív přes WPML -> String
Translation ručně přeložili placeholder pole hledání nebo text štítku
"Bestseller" pod původním kontextem "ZG Smart Search", po této
aktualizaci je potřeba je znovu dohledat a přeložit pod novým
kontextem "Smart Search Advanced" - starý překlad zůstane uložený,
ale plugin ho už nenajde.

== Verze 1.3.2 ==
- Filtr "jen skladem" teď kontroluje i skutečné skladové množství, ne
  jen pole "stav skladu" - to bývá po importu/desynchronizaci chybně
  "skladem" i když je reálná zásoba 0. Produkt se zapnutým sledováním
  skladu a množstvím <= 0 se tak nezobrazí, i kdyby měl stock_status
  nesprávně nastavený na "instock". Platí pro výsledky vyhledávání i
  všechna doporučení (bestsellery, related, personalizace).
- Aktualizace pluginu teď vždy automaticky zahodí starou cache indexu,
  ať se změny v logice projeví hned, ne až po vypršení cache.

== Verze 1.3.1 ==
- Oprava hoveru na fotce produktu: místo přebíjení pozadí kontejneru
  (což u fotek s bíle "napáleným" pozadím v JPG nešlo, bílá je součást
  obrázku) se teď přes fotku position přiloží tenká poloprůhledná
  červená vrstva. Zvýraznění tak vypadá stejně u všech fotek bez
  ohledu na to, jestli mají bílé nebo průhledné pozadí.

== Verze 1.3.0 ==
- Oprava: dropdown se dal jen těžko zavřít - pozdě doručená AJAX
  odpověď (z requestu odeslaného před kliknutím mimo okno) ho dokázala
  znovu otevřít. Zavření teď respektuje poslední akci uživatele.
- Hover na kartu produktu teď podbarví červeně celou kartu (obrázek,
  název, cenu, dostupnost), ne jen fotku.
- Plugin teď vyhledává i doporučuje výhradně produkty, které jsou
  skladem (na úrovni indexu i všech doporučení/bestsellerů).

== Verze 1.2.0 ==
- Štítek "Bestseller" přesunut vpravo nahoře (spolu se slevovým štítkem,
  bez překrývání) a zobrazuje se i u reálných výsledků vyhledávání, ne
  jen v doporučeních.
- Hlavní záhlaví (Oblíbená vyhledávání, Kategórie, Doporučujeme) zpět
  v červené barvě.
- Najetí myší na fotku produktu ji jemně podbarví do červena.
- Nová animovaná nápověda v poli hledání (volitelně v nastavení) -
  když je pole prázdné a nemá fokus, postupně "vypisuje" ukázkové
  výrazy z vlastního seznamu.
- Plugin teď skutečně načítá překlady podle jazyka stránky (chybělo
  volání load_plugin_textdomain). Součástí je 5 hotových jazyků
  (ruština, němčina, slovenština, angličtina, francouzština) - viz
  languages/readme.txt pro návod, jak přidat další.

== Verze 1.1.0 ==
- Odkaz "Více výsledků" a lupa teď vedou na jazykově správnou URL
  (přes WPML permalink filtr) ve tvaru domena/[jazyk/]?s=vyraz.
- Oblíbená vyhledávání i hlavní záhlaví (Oblíbená vyhledávání,
  Kategórie, Doporučujeme) mají zelenou barvu/pozadí.
- Odstraněna plošná červená linka pod polem hledání, nahrazena malou
  šipkou ukazující na vyhledávací pole.
- Okno výsledků je širší a pevně drží 4 produkty na řádek.
- Nový přepínač v nastavení: štítek "Bestseller" (text i práh
  nastavitelné) u nejprodávanějších produktů.
- Zvýraznění shody teď funguje i při psaní azbukou (dotaz se před
  zvýrazněním převede do latinky).

== Instalace ==
1. Nahrát celou složku `zg-smart-search` do `wp-content/plugins/`.
2. Aktivovat v Pluginy -> Instalované pluginy.
3. V administraci WooCommerce -> Smart Search Advanced nastavit počty výsledků,
   citlivost debounce a zapnout/vypnout personalizaci.
4. Vložit shortcode `[zg_smart_search]` tam, kde má být vyhledávací pole
   - v Bricks Builderu nejjednodušeji přes element "Shortcode".
   Volitelný atribut: `[zg_smart_search placeholder="Hledejte produkty…"]`.

== Poznámky k výkonu ==
- Index produktů (název, SKU, kategorie, cena, obrázek, sklad) se
  cachuje přes transient na X hodin (nastavitelné, výchozí 12h) a
  automaticky se přepočítá při uložení/smazání jakéhokoliv produktu
  nebo změně skladu.
- Pro katalogy v řádu tisíců produktů doporučuji zvážit přesun indexu
  z transientu do object cache (Redis/Memcached), pokud je na hostingu
  k dispozici - kód je na to připravený (jedna metoda `build_index()`
  v `class-zgss-index.php`).

== Rozšiřitelnost ==
- Fuzzy skórování je izolované v `class-zgss-fuzzy.php` - prahy editační
  vzdálenosti lze snadno doladit podle reálného chování hledání.
- Doporučovací logika je v `class-zgss-recommendations.php` - dá se
  rozšířit např. o "často nakupováno společně" analýzou order items.
