# Specyfikacja: narzędzie „Mapa witryny” (MerTools for Gridbox 1.6.0)

Status: projekt do akceptacji, bez kodu. Data: 2026-10-23.
Źródła: raport SEO `dev/projekty/seo/www.merserwis.pl-audit/` (ACTION-PLAN pkt 2 i 31, findings/sitemap.md, technical.md C2, M3) oraz źródło Gridboxa (`dev/gb-subcats/.../com_gridbox/src/Helper/SitemapHelper.php`, `SeoHelper.php`, `View/Page|Blog/HtmlView.php`).

## 1. Cel

Mapa witryny, która zawiera wyłącznie adresy warte indeksowania, ma wiarygodne `lastmod` i jest podzielona na sekcje, żeby Search Console pokazywała indeksację osobno dla oferty, bloga i stron. Plik Gridboxa tego nie potrafi, a nie wolno zmieniać plików Gridboxa.

Co naprawia z raportu:

| Raport | Dziś (audyt 2026-10-22) | Po wdrożeniu |
|---|---|---|
| C2: adresy blokowane przez robots.txt | 130 (`/oferta/tag/*`, `/blog/tag/*`) | 0 |
| C2: strony `noindex` w mapie | 15 (14 stron i kategoria `/blog/regulamin`) | 0 |
| C2: `lastmod` | wszystkie 4655 z ostatnich 4 dni | prawdziwa data zmiany treści albo brak |
| pkt 31: `changefreq`, `priority` | w każdym adresie, jednolite | nie generowane |
| pkt 31: indeks map per sekcja | brak, jeden plik | indeks + mapa na aplikację Gridboxa |
| M3: wygasłe promocje w mapie | 67 adresów `/promocje/*`, 17 z 23 próbek „[ZAKOŃCZONE]” | reguła wykluczenia po tytule lub ścieżce |

Poza zakresem tego narzędzia (osobne zadania): soft-404 (opcja *Redirect missing pages*, ACTION-PLAN pkt 1), robots.txt (plik statyczny, narzędzie go tylko czyta), reguła WAF blokująca UA z „Discovery” na `/sitemap.xml` (L5 w raporcie).

## 2. Co wynika ze źródła Gridboxa

Ustalone przez czytanie kodu, nie na produkcji.

1. `SitemapHelper::create()` zapisuje statyczny `JPATH_ROOT/sitemap.xml`. Woła go `GridboxHelper::checkSitemap()` po zapisach, tylko gdy `enable_sitemap = 1` w tabeli `#__gridbox_website`. Wyłączenie tego ustawienia zatrzymuje nadpisywanie pliku.
2. Serwer podaje plik z dysku, zanim zapytanie dotrze do PHP. Dopóki plik istnieje, żaden generator w Joomli go nie zastąpi. Plik trzeba usunąć.
3. Co Gridbox wpisuje: pozycje menu typu `component` (publiczne, opublikowane), strony typu `single`, a dla każdej aplikacji: stronę główną aplikacji (gdy nie ma pozycji menu), kategorie, tagi, autorów i strony. Strona, kategoria albo tag z własną pozycją menu wchodzą przez menu, nie drugi raz.
4. Warunki stron: opublikowana, `created <= teraz`, `page_access = 1`, nie w koszu, `end_publishing` pusty albo w przyszłości, nie produkt subskrypcyjny.
5. Flagi włączenia: `sitemap_include` na elemencie, jeśli `sitemap_override = 1`; w przeciwnym razie globalna wartość z `#__gridbox_seo_defaults` (osobna dla page, category, tag, author). Dlatego wyłączenie globalne tagów usuwa je z mapy już dziś.
6. `lastmod` w kodzie Gridboxa: strona bierze własne `saved_time`; kategoria, tag i autor dostają `saved_time` aplikacji; kategoria wskazana w menu dostaje `NOW()`. Format `saved_time` to `Y-m-d-H-i-s`.
7. Robots strony: parametr `robots` pozycji menu (tylko gdy menu wskazuje tę stronę i parametr nie jest pusty) wygrywa z polem `robots` strony, a gdy oba są puste, działa globalne `robots` z konfiguracji Joomli (`View/Page/HtmlView`). Kategoria i strona główna aplikacji tak samo (`View/Blog/HtmlView`); tag i autor biorą tylko własne pole. Ta kolejność jest regułą dla filtra `noindex`.
8. Gridbox nie escapuje adresów w XML (`<loc>` dostaje surowy `$url`). Adres z `&` dałby niepoprawny XML.

9. `saved_time` strony nie oznacza zmiany treści. Każdy zapis w edytorze (`EditorModel::gridboxSave`) woła `GridboxHelper::saveTheme`, a ta usuwa wszystkie pliki z `components/com_gridbox/assets/css/storage/`. Szablon `templates/gridbox/index.php` przy każdym wyświetleniu strony woła `checkPageCss`, które przy braku `style-{id}.css` odtwarza plik i wpisuje `saved_time = teraz` (aplikacji analogicznie `checkAppCss`). Po zapisie jednej strony w edytorze każda strona serwisu dostaje nową datę przy pierwszej wizycie gościa albo robota. Potwierdzone na produkcji, patrz sekcja 13, pkt 2.

## 3. Zachowanie z zewnątrz

Adresy:

| Adres | Zawartość |
|---|---|
| `/sitemap.xml` | indeks (`<sitemapindex>`), ten sam adres co dziś, więc robots.txt i Search Console bez zmian |
| `/sitemap-pages.xml` | pozycje menu spoza Gridboxa oraz strony typu `single` |
| `/sitemap-{typ}-{id}.xml` | jedna aplikacja Gridboxa, np. `sitemap-products-3.xml`, `sitemap-blog-5.xml` |
| `/sitemap-{typ}-{id}-p2.xml` | kolejne części, gdy aplikacja przekracza limit części (domyślnie 10 000, twardo 50 000) |

Identyfikator zamiast nazwy, bo jest stabilny po zmianie tytułu aplikacji.

Odpowiedź: `200`, `Content-Type: application/xml; charset=utf-8`, `ETag` i `Last-Modified` z czasu budowy, `Cache-Control: public, max-age=3600`, obsługa `If-None-Match` i `If-Modified-Since` (`304`), `HEAD` bez treści. Kompresję robi serwer. Nieznana nazwa `sitemap-*.xml` dostaje prawdziwe `404`, nie przekierowanie na stronę główną.

Format: `<url>` z `<loc>` i, gdy znane, `<lastmod>`. Żadnego `changefreq` ani `priority`. Indeks ma `<lastmod>` równe najnowszemu znanemu `lastmod` mapy. `lastmod` w pełnej precyzji UTC (`2026-10-22T14:03:11Z`). Adresy escapowane jako XML. Ten sam host i schemat co `sitemap_domain` Gridboxa, ta sama zasada ukośnika końcowego (`sitemap_slash`), więc adresy są identyczne z dzisiejszymi.

## 4. Skąd biorą się adresy i jak są filtrowane

Zakres zbierania jest taki jak u Gridboxa (sekcja 2, pkt 3 i 4), z jedną różnicą: włączone są tylko źródła zaznaczone w ustawieniach.

Filtry, w tej kolejności. Każdy wykluczony adres dostaje kod powodu, zliczany w panelu.

1. `status`: warunki publikacji z pkt 4 powyżej (zawsze).
2. `flag`: `sitemap_include = 0` na elemencie albo globalnie dla typu (ustawienie *Respect Gridbox's include flags*, domyślnie włączone).
3. `noindex`: efektywne `robots` elementu zawiera `noindex` lub `none` (kolejność z sekcji 2, pkt 7). Ustawienie domyślnie włączone.
4. `robots-txt`: adres zablokowany w `JPATH_ROOT/robots.txt` dla `User-agent: *` i `Googlebot`. Parser: grupy agentów, `Allow`/`Disallow`, najdłuższe dopasowanie wygrywa, `Allow` wygrywa remis, wzorce `*` i `$`. Reguły zapamiętane razem z `mtime` pliku. Ustawienie domyślnie włączone.
5. `type`: typ wyłączony ustawieniem (tagi, autorzy, strony główne aplikacji, kategorie, pozycje menu). Tagi i autorzy domyślnie wyłączeni, bo raport pokazuje je jako cienkie listy z samym tytułem i bez opisu.
6. `app`: aplikacja zaznaczona jako wykluczona.
7. `path`: adres pasuje do wzoru z listy (jeden na linię, `*` jako wildcard, np. `/sandbox`, `/blog/regulamin/*`).
8. `title`: tytuł zawiera tekst z listy (np. `[ZAKOŃCZONE]` dla M3).
9. `query`: adres z query string (`?page=N`, `?srsltid=`); nigdy nie trafia do mapy.
10. `dup`: ten sam adres już w mapie (pierwszy wygrywa).

Czego narzędzie nie widzi: `noindex` dodawany przez szablon, wtyczkę albo nagłówek `X-Robots-Tag`. Dla takich stron służy filtr `path`. Na produkcji takich stron dziś nie ma: wszystkie 15 adresów `noindex` ma go w polu `robots` Gridboxa (sekcja 13, pkt 1).

## 5. `lastmod`

Zasada: `lastmod` oznacza zmianę treści, którą widzi użytkownik i robot. Zapis z importu, zmiana ceny albo ponowny zapis bez zmian nie liczą się.

Stan trzymany w tabeli `#__mertools_sitemap` (`item_type`, `item_id`, `hash`, `lastmod`). `saved_time` Gridboxa nie jest w nim używany: Gridbox podbija go po każdym zapisie w edytorze wszystkim odwiedzanym stronom (sekcja 2, pkt 9), więc nie odróżnia stron zmienionych od niezmienionych.

Strona i produkt, przy każdej budowie (`Sitemap::nextState`):

1. Hash treści liczony w bazie jednym zapytaniem dla wszystkich stron (`MD5(CONCAT_WS(...))` z `title`, `params`, `intro_text`, `meta_title`, `meta_description`, `page_alias`, `page_category`, `robots`, `intro_image` oraz hasha pól strony z `#__gridbox_page_fields`, czyli parametrów produktu). Na kopii produkcji 0,27 s dla 4641 stron. Pola są konieczne: między kopiami z 9.10 i 23.10 hash bez pól zmienił się na 92 stronach, z polami na 105, a 15 edycji samych pól w historii MerTools bez pól byłoby niewidocznych. `saved_time` zmienił się w tym czasie na 4293 stronach.
2. Hash ten sam co zapisany: `lastmod` bez zmian. To neutralizuje masowe zapisy, które podbijają `saved_time` bez zmiany treści.
3. Hash inny: `lastmod` = czas wykrycia.
4. Dla produktów ustawienie *Count price and stock changes* (domyślnie wyłączone) dokłada do hasha cenę, cenę promocyjną, stan, warianty i opcje (`#__gridbox_store_product_data`). Wyłączone: zmiany cen z importu nie ruszają `lastmod`.
5. Pierwszy zapis stanu (adres bez wpisu): `lastmod` z najnowszego wiersza historii zmian (`#__mertools_history`) o zmianie treści, jeśli istnieje; w przeciwnym razie brak. Ustawienie *When the date is unknown*: pominąć `<lastmod>` (domyślnie) albo użyć daty publikacji (`created`).

Kategoria, tag, autor, strona główna aplikacji: najnowsze znane `lastmod` wśród ich stron (kategoria razem z podkategoriami), liczone w pamięci z już zebranych danych. Gdy żadna strona nie ma znanej daty: brak. Usunięcie strony z kategorii nie podbija jej daty (świadome uproszczenie).

Pozycje menu spoza Gridboxa: brak `lastmod`. Pozycja menu wskazująca stronę Gridboxa: `lastmod` strony.

Kontrola wiarygodności w panelu: gdy w mapie z co najmniej 100 adresami ponad 90% dat mieści się w ostatnich 7 dniach, panel pokazuje ostrzeżenie „daty wyglądają na masowo zmienione”. To tylko diagnostyka, niczego nie ukrywa.

## 6. Przejęcie od Gridboxa

Przełącznik narzędzia sam nie rusza Gridboxa. Przejęcie to osobny, jawny krok w panelu (przycisk *Take over from Gridbox*), bo usuwa plik.

Kroki przycisku:

1. Kopia `JPATH_ROOT/sitemap.xml` do `JPATH_ROOT/tmp/mertools-sitemap-gridbox.xml` (katalog `/tmp/` jest w `Disallow` robots.txt).
2. `enable_sitemap = 0` w wierszu `#__gridbox_website`.
3. Usunięcie statycznego `sitemap.xml`. Gdy się nie da (uprawnienia), panel mówi, co zrobić ręcznie, i niczego nie cofa.
4. Zbudowanie map i sprawdzenie, że `/sitemap.xml` odpowiada już z PHP.

Przycisk *Undo* przywraca flagę i plik z kopii. Panel pokazuje stan: flaga Gridboxa, obecność pliku statycznego, kto odpowiada pod `/sitemap.xml`. Gdy narzędzie jest włączone, a plik statyczny istnieje (ktoś włączył sitemapę w Gridboxie z powrotem), panel pokazuje ostrzeżenie (sprawdza plik przy każdym otwarciu). Wiadomość w panelu administracyjnym tylko na stronie ustawień MerTools, nie na każdej.

Porównanie: gdy istnieje kopia pliku Gridboxa, przycisk *Compare* podaje adresy z dawnej mapy, których nie ma w nowej, każdy z kodem powodu. Brak adresu bez powodu znaczy błąd narzędzia.

robots.txt: linia `Sitemap: https://www.merserwis.pl/sitemap.xml` zostaje bez zmian. Narzędzie tylko sprawdza jej obecność i pokazuje w panelu.

## 7. Cache i wydajność

- Mapy budowane do plików w katalogu cache plugina (miejsce jak strony zapamiętane przez PageCache) i podawane z dysku. Odpowiedź z cache: cel poniżej 10 ms, bez zapytań do bazy poza sprawdzeniem ważności.
- Budowa po odpowiedzi, z blokadą (`flock` na `build.lock`) przeciw wielokrotnej budowie naraz. Żądanie, które zastaje przeterminowany plik, dostaje stary i uruchamia budowę zaraz po wysłaniu odpowiedzi, w tym samym żądaniu (odpowiedź kończy się `close()`, więc `onAfterRespond` nie nastąpi): przez `fastcgi_finish_request`, a bez niego po wysłaniu całej treści z `Content-Length`. Odpowiedź bez treści (304, HEAD) bez `fastcgi_finish_request` wyszłaby dopiero po budowie, więc wtedy najpierw buduje, a potem porównuje z nowymi plikami (żeby 304 nie potwierdziło starej mapy). Pierwsza budowa, gdy plików jeszcze nie ma, jest synchroniczna; równoległe żądania czekają na nią do 20 s, potem 503 z `Retry-After`. Pliki zapisywane pod tymczasową nazwą i przemianowywane.
- Unieważnianie: każdy POST zalogowanego użytkownika do `com_gridbox` albo `com_menus` dotyka pliku `changed` (tanie i szerokie, bo samo unieważnienie nic nie buduje), zmiana ustawień `sitemap_*` (klucz w `build.json`), zmiana `robots.txt` (`mtime`) i upływ TTL (domyślnie 360 minut).
- Zapytania zbiorcze: menu, strony, kategorie, tagi ładowane po jednym zapytaniu na typ. Same adresy budowane funkcjami Gridboxa (`getGridboxPageLinks`, `getGridboxCategoryLinks`, `getGridboxTagLinks` i `Route::_`), bez własnego kodu linków. Pomiar na kopii produkcji (sekcja 13, pkt 5): 4655 adresów w ok. 3,3 s, szczyt pamięci 26 MB, ok. 19,8 tys. zapytań po kluczu głównym (router liczy ścieżkę kategorii na adres), zbiór adresów identyczny z dzisiejszą mapą. To mieści się w budżecie i daje zgodność z routerem bez osobnego dowodu.
- Adresy wymagają routera strony, więc budowa idzie w kontekście aplikacji `site` (żądanie mapy na froncie). *Rebuild now* z panelu nie buduje w administracji, tylko unieważnia pliki i wymusza budowę przy najbliższym żądaniu mapy (albo woła ją żądaniem do `/sitemap.xml`).
- Budżet budowy: poniżej 60 s dla 5 tys. adresów, zużycie pamięci poniżej 128 MB (zbiory ładowane po aplikacji).

## 8. Ustawienia

Zakładka *Sitemap* (po *404 pages*), każde pole z objaśnieniem „?” jak pozostałe.

| Ustawienie | Domyślnie | Uwagi |
|---|---|---|
| Narzędzie włączone | wyłączone | po włączeniu `/sitemap-*.xml` odpowiadają od razu (podgląd); `/sitemap.xml` odpowie z PHP dopiero po usunięciu pliku Gridboxa (sekcja 6) |
| Podział | na aplikację | albo jeden plik (do 50 000) |
| Limit części | 10 000 | 1000–50 000 |
| Pozycje menu, strony, kategorie, strony główne aplikacji | włączone | |
| Tagi, autorzy | wyłączone | |
| Respect Gridbox's include flags | włączone | sekcja 4, filtr `flag` |
| Skip noindex pages | włączone | filtr `noindex` |
| Skip addresses blocked by robots.txt | włączone | filtr `robots-txt` |
| Excluded apps | brak | wybór z listy aplikacji |
| Excluded paths | puste | wzory, jeden na linię |
| Excluded titles | puste | fragmenty tytułu, jeden na linię |
| Count price and stock changes | wyłączone | sekcja 5, pkt 4 |
| When the date is unknown | pominąć `<lastmod>` | albo data publikacji |
| Refresh time | 360 min | 5–1440 |

Domyślnie wyłączone narzędzie po aktualizacji nic nie zmienia: bez zgody właściciela nie ma żadnej zmiany działania serwisu.

## 9. Panel (pole `Mtsitemap`)

1. Stan: flaga Gridboxa, plik statyczny, kto odpowiada pod `/sitemap.xml`, ostatnia budowa (czas, długość), liczba adresów.
2. Tabela map: adres, liczba adresów, najnowszy `lastmod`, rozmiar. Ostrzeżenie o masowych datach.
3. Wykluczenia: liczba adresów na kod powodu (sekcja 4) z rozwijaną listą (wyszukiwanie).
4. Pole „Dlaczego tego adresu nie ma w mapie?”: wpisujesz adres albo fragment, dostajesz powód albo potwierdzenie, że jest, w której mapie.
5. Przyciski: *Rebuild now*, *Take over from Gridbox* / *Undo*, *Compare with Gridbox's sitemap*.

## 10. Architektura w kodzie

Zgodnie ze strukturą 1.3.1: klasa wtyczki tylko nasłuchuje zdarzeń, praca w `src/Feature`, logika czysta w `src/Tool`.

- `src/Tool/Sitemap.php`: logika bez zależności od Joomli, testowalna. Parser robots.txt i dopasowanie, ocena `noindex`, wzory ścieżek, decyzja o `lastmod`, składanie XML (escapowanie), podział na części, kody powodów.
- `src/Tool/SitemapSource.php`: zapytania zbiorcze do tabel Gridboxa (jedno na typ) i adresy z funkcji linków Gridboxa przez `Route::link('site', …)` (jak `MissingPages`). Czy to wystarcza do budowy z panelu administracyjnego, sprawdzić w kroku 6; sprawdzone tylko w kontekście strony. Elementy nieopublikowane zostają w wyniku z `live = false`, żeby panel mógł je policzyć jako `status`.
- `src/Feature/Sitemaps.php` (liczba mnoga jak `Feeds`): obsługa `onAfterInitialise` (dopasowanie `^/sitemap(-[a-z0-9-]+)?\.xml$`, GET i HEAD, odpowiedź przed routingiem, więc ani router Joomli, ani `MissingPages` nie wchodzą w grę), budowa po odpowiedzi, unieważnianie (`onAfterRoute`), godzinne sprawdzenie pliku statycznego (krok 6). Pliki w `<cache>/mertools_sitemap/` (`sitemap*.xml`, `build.json`, `build.lock`, `changed`), usuwane przy odinstalowaniu.
- `src/Field/MtsitemapField.php` plus skrypt panelu w `media/`. Żądania panelu przez istniejący mechanizm `handleAdministratorAction`.
- `script.php`: tabela `#__mertools_sitemap`, dopisana do listy tabel zostawianych po odinstalowaniu.
- Teksty: `language/en-GB/plg_system_mertools.ini` i `pl-PL`.
- `mertools.xml`: zakładka i pola. README: nowa sekcja narzędzia. CHANGELOG, `update.xml`, wersja 1.6.0 (nowe narzędzie, jak 1.3.0), paczka przez `build.sh`.

Bezpieczeństwo: odpowiedź jest publiczna, więc nie zawiera nic poza adresami i datami opublikowanych stron. Brak parametrów wejściowych poza ścieżką dopasowaną wzorcem. Akcje panelu (przejęcie, cofnięcie, przebudowa) wymagają uprawnień administratora i tokenu jak pozostałe akcje panelu.

## 11. Testy i kryteria akceptacji

Testy jednostkowe (`tests/SitemapTest.php`, wzorzec jak `FeedTest`):

1. Parser robots.txt: `Disallow: /*tag/`, `Allow` kontra `Disallow`, `$`, wiele grup, pusty `Disallow`, brak pliku.
2. `noindex`: kolejność menu, element, globalne; `none`; wielkość liter; `noindex, follow`.
3. Wzory ścieżek i tytułów.
4. `lastmod`: strona bez zmiany hasha nie dostaje nowej daty mimo nowego `saved_time`; zmiana treści dostaje; pierwszy zapis z historii; pierwszy zapis bez historii (pominięte, a przy ustawieniu: `created`).
5. XML: escapowanie `&`, brak `changefreq` i `priority`, podział na części, poprawny indeks, `HEAD`.
6. Zgodność z Gridboxem: na tym samym zestawie danych zbiór adresów (bez filtrów) równy zbiorowi z `SitemapHelper`. Wymaga Joomli z Gridboxem, więc to nie test jednostkowy, tylko `tools/sitemap_check.php` (uruchamiany w instalacji Joomli, opcjonalnie z innym `configuration.php`); ten sam skrypt liczy kryteria 2, 3 i 7.

Kryteria na kopii produkcji (liczby z audytu 2026-10-22):

1. Indeks i mapy przechodzą walidację względem schematu sitemaps.org.
2. Suma adresów to 4655 minus 130 tagów minus 15 adresów `noindex` minus wykluczenia po regułach, czyli 4510 przed regułą dla promocji.
3. 0 adresów zgodnych z `Disallow` z robots.txt, 0 stron `noindex` (sprawdzone ponownie skryptem audytu).
4. Daty `lastmod` nie mieszczą się w jednym 4-dniowym oknie, a adresy bez znanej daty nie mają znacznika.
5. Brak `changefreq` i `priority`.
6. `304` przy powtórzonym żądaniu, `404` dla `/sitemap-nie-ma.xml`.
7. Porównanie z kopią mapy Gridboxa: każdy brakujący adres ma kod powodu.
8. Cache: odpowiedź poniżej 10 ms, budowa poniżej 60 s.

## 12. Poza zakresem pierwszej wersji

- Mapy obrazów, wideo, newsów.
- `hreflang` (`xhtml:link`); serwis jest jednojęzyczny, pole `language` Gridboxa na razie ignorowane.
- Sprawdzanie na żywo (pobranie każdego adresu i odczyt `meta robots`, `X-Robots-Tag`, kanoniczny). To rozwiązałoby przypadek `noindex` ustawianego poza Gridboxem, ale to osobny, kosztowny mechanizm, a na produkcji takiego przypadku nie ma (sekcja 13, pkt 1).
- Pingowanie wyszukiwarek (Google wycofał ping), IndexNow (ACTION-PLAN pkt 30).
- Edycja robots.txt.
- Joomla Content (artykuły): Gridbox ich nie wpisuje, serwis ich nie używa.

## 13. Ustalenia z produkcji (2026-10-23)

Źródła: zrzut bazy produkcji z 2026-10-23 10:30 (phpMyAdmin, MariaDB 10.11), wgrany lokalnie do `gbx-db` jako `mrs_2310`; żywa `https://www.merserwis.pl/sitemap.xml` pobrana o 10:35 (4655 adresów); źródło Gridboxa 2.20.4 (produkcja: 2.20.4.1).

1. **Skąd `noindex`.** Ze pola `robots` Gridboxa, nie z szablonu ani nagłówka. W bazie 14 stron z `noindex` (`sandbox`, `youtube`, `potwierdzenie`, `potwierdzenie-dh`, `potwierdzenie-naprawa`, `status-zlecenia-serwisowego` i 8 stron kategorii `/blog/regulamin`) oraz kategoria `regulamin` (id 197) z `noindex, follow`. Wszystkie 15 są w dzisiejszej mapie. Pozycje menu nie mają `noindex`, globalne `robots` Joomli jest puste. Na żywo wszystkie 15 adresów ma `<meta name="robots">` z wartością z bazy, żaden nie ma `X-Robots-Tag`, a próbka 11 zwykłych stron i kategorii nie ma znacznika robots. Filtr `noindex` wystarczy, reguły `path` dla tych stron niepotrzebne. Filtr musi czytać też `robots` kategorii.
2. **`saved_time`.** Sprawcą jest Gridbox (sekcja 2, pkt 9), nie import ani cron. Na 4641 stron 1509 ma datę z 23.10, 2357 z 22.10, 381 z 21.10; zapisy idą przez całą dobę, po 100–250 na godzinę, w rytmie odwiedzin. Redaktorzy zapisują w edytorze kilkadziesiąt razy dziennie (`#__mertools_history`: 47–86 zapisów dziennie od 19.10), a każdy zapis kasuje CSS wszystkich stron. Ostatni zapis przed zrzutem: 08:15 UTC; `saved_time` aplikacji Oferta i Blog: 10:16 czasu lokalnego. Hash treści (sekcja 5) jest konieczny i wystarcza; szukanie sprawcy poza Gridboxem jest zbędne. Uboczny skutek, poza zakresem: CSS stron dostaje w adresie `?{saved_time}`, więc przeglądarki i Cloudflare pobierają go od nowa po każdym zapisie w edytorze, a pierwsza wizyta na każdej stronie kompiluje jej CSS.
3. **Podział 4655 adresów:** 4259 stron (daty z ich `saved_time`, wszystkie z 20–23.10), 259 kategorii i 130 tagów (data aplikacji, wszystkie 23.10), 7 pozycji menu. Autorów i stron głównych aplikacji bez menu brak. Tabela `#__gridbox_seo_defaults` jest pusta, więc o włączeniu decydują flagi elementów i domyślne Gridboxa.
4. **Kto podaje `/sitemap.xml`.** Plik statyczny, podawany przed PHP: GET obsługuje nginx (`ETag: W/"6adb1b15-101616"` = czas modyfikacji i rozmiar pliku, `304` na `If-None-Match`), HEAD idzie do Apache (inny format `ETag`). Cloudflare go nie buforuje (`cf-cache-status: DYNAMIC`) i nie zmienia; `?x=1` też dostaje plik. Nieistniejące `/sitemap-nie-ma.xml` i `/sitemap-pages.xml` docierają do Joomli (dziś `301` na stronę główną), więc po usunięciu pliku `/sitemap.xml` także trafi do PHP. Przejęcie z sekcji 6 bez zmian; reguł serwera ani Cloudflare nie trzeba dotykać. Gridbox przebudowuje plik żądaniem `editor.checkSitemap`, które wysyła w tle z panelu administracyjnego (`StoreHelper::getBackgroundRequests`); ostatnia przebudowa przed pomiarem: 10:30:13.
5. **Koszt linków Gridboxa.** Skrypt CLI w `gbx-web` (Joomla 6, Gridbox 2.20.4.0) na bazie `mrs_2310` odtworzył `SitemapHelper::create()` bez zapisu pliku: 4655 adresów, zbiór identyczny z żywą mapą (4655 wspólnych, 0 różnic), ok. 3,3 s, szczyt pamięci 26 MB, ok. 19,8 tys. zapytań (11 459 po kategoriach, 4259 po stronach, 3901 po `seo_defaults`). Decyzja: wołać funkcje Gridboxa (sekcja 7).

## 14. Kolejność prac

1. ~~Pytania 1–5 z sekcji 13~~ (zrobione 2026-10-23).
2. ~~`Tool/Sitemap.php` z testami (logika bez bazy)~~ (zrobione 2026-10-23: `tests/SitemapTest.php`, 87 sprawdzeń, w tym walidacja XML schematami sitemaps.org z `tests/fixtures`).
3. ~~Zbieranie adresów i porównanie z mapą Gridboxa~~ (zrobione 2026-10-23: `Tool/SitemapSource.php` i `tools/sitemap_check.php`). Wynik na kopii `mrs_2310` z `robots.txt` produkcji: w zakresie Gridboxa 4655 adresów, identycznie z mapą Gridboxa zbudowaną w pamięci i z żywą mapą; przy ustawieniach domyślnych 4510 w mapie (`sitemap-pages.xml` 107, `sitemap-products-2.xml` 4054, `sitemap-blog-4.xml` 349), wykluczone: `status` 380 (375 nieopublikowanych stron, 5 kategorii), `noindex` 15, `robots-txt` 130 (tagi); 0 adresów w mapie zablokowanych przez robots.txt lub z `noindex`; każdy ze 145 brakujących adresów Gridboxa ma powód.
4. ~~`lastmod` ze stanem w tabeli~~ (zrobione 2026-10-23: `SitemapSource::dates`, tabela `#__mertools_sitemap` zakładana przy pierwszym użyciu, zostaje po odinstalowaniu). Na kopii produkcji (`tools/sitemap_check.php --dates`): pierwsza budowa daje datę 141 z 4510 adresów (z historii MerTools, która sięga 14.10), reszta bez `<lastmod>`, bez ostrzeżenia o masowych datach; druga budowa bez zmian; nowy `saved_time` i `fonts` na 50 stronach (jak po zapisie w edytorze) nie zmienia dat; zmiana tytułu strony daje jej, jej kategorii, kategorii nadrzędnej i `/oferta` czas wykrycia; zmiana pola produktu też. Kopia przywrócona po teście.
5. ~~Odpowiedź HTTP, cache, budowa po odpowiedzi~~ (zrobione 2026-10-23: `Feature/Sitemaps.php`, podpięte w `MerTools.php`). Sprawdzone w tymczasowym kontenerze z kopią Joomli na bazie `mrs_2310` i `robots.txt` produkcji (Apache, mod_php, bez `fastcgi_finish_request`): wyłączone narzędzie nic nie zmienia (`/sitemap-pages.xml` dalej 301); pierwsza budowa 5,3 s (z założeniem tabeli dat), kolejne 3–5 s; indeks i 3 mapy przechodzą schematy sitemaps.org, bez `changefreq` i `priority`; 304 na `If-None-Match` i `If-Modified-Since`; HEAD z `Content-Length` bez treści; `/sitemap-nie-ma.xml` 404 z `X-Robots-Tag: noindex`; odpowiedź z pliku: mediana ok. 10 ms z całym startem Joomli (kryterium 8: na granicy, start Joomli przed `onAfterInitialise` jest poza zasięgiem wtyczki); zmiana treści, `robots.txt`: odpowiedź od razu (ok. 15 ms), nowa budowa po niej; przeterminowana mapa przy żądaniu warunkowym: 304, gdy treść ta sama, 200 z nowym indeksem, gdy inna; trzy równoległe żądania przy pustym cache: jedna budowa, wszystkie 200.
6. ~~Panel i przejęcie~~ (zrobione 2026-10-23: zakładka *Mapa witryny* w `mertools.xml` po *404 pages*, pole `MtsitemapField` i `media/js/mertools-sitemap.js`, akcje `sm_status`, `sm_rebuild`, `sm_takeover`, `sm_undo`, `sm_compare`, `sm_why` w `Sitemaps::handleAction`, teksty en-GB i pl-PL). Sprawdzone w kontenerze testowym z tymczasowym kontem administratora (usuniętym po teście): budowa z panelu daje pliki identyczne (ETag) z budową od strony witryny, więc *Rebuild now* buduje od razu; przejęcie: kopia w `tmp/`, flaga Gridboxa 0, plik usunięty, `/sitemap.xml` odpowiada indeksem MerTools; *Compare*: 4655 adresów Gridboxa, 145 brakujących, 0 bez powodu, 0 nowych; *Undo* przywraca flagę i plik; panel w przeglądarce (Chromium headless) bez błędów konsoli. Sprawdzenie `/sitemap.xml` po przejęciu (żądanie HTTP z serwera do siebie) w kontenerze daje „nie da się sprawdzić” (port z zewnątrz), na produkcji do potwierdzenia. Godzinne sprawdzenie pliku statycznego pominięte: panel sprawdza plik przy każdym otwarciu i tylko tam pokazuje ostrzeżenie (spec, sekcja 6), więc osobny zegar nic by nie dodał.
7. ~~README, CHANGELOG, wersja 1.6.0, paczka~~ (zrobione 2026-10-23: `pkg_mertools-1.6.0.zip`, SHA-256 `40db10f8…a557` w `update.xml` (po poprawce nazwy „Platforma pamięci podręcznej” paczka zbudowana ponownie); aktualizacja 1.5.0 → 1.6.0 instalatorem Joomli w kontenerze testowym: wersje 1.6.0, ustawienia zachowane, pliki identyczne z repo, mapy działają). Paczki nie budować ponownie przed wydaniem: zip ma znaczniki czasu, więc nowa budowa zmieni sumę w `update.xml`.
8. Wdrożenie: najpierw na kopii, potem na produkcji z przyciskiem *Take over*; po tygodniu ponowny audyt (ACTION-PLAN pkt 35).
