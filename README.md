# Wstęp
Rozwiązanie napisane jako niezależna aplikacja (à la mikro serwis).
Uznałem, że najistotniejszą częścią zadania jest sam mechanizm dopasowywania nazw oficjalnych. 
Dlatego aplikacja nie operuje na użytkownikach, tylko dostarcza rozwiązanie demonstracyjne samego mechanizmu.


# Droga do rozwiązania
Od początku było oczywiste, że mamy tu do czynienia z analizą języka naturalnego 
i nawet nietrywialne porównywanie ciągów znaków (similar_text, levenshtein) nie zda tutaj egzaminu.
Ostatni raz takim problemem zajmowałem się na studiach i pomyślałem, że to tego typu zagadka. 
Moim pierwszym pomysłem była tokenizacja oficjalnych nazw, normalizacja i wygenerowanie puli nieoficjalnych wariantów. 
Domena nazw szkół jest stosunkowo niewielka, więc ewentualne przypadki brzegowe można obsłużyć ręcznie.
Szukając informacji w internecie dosyć szybko zrozumiałem, że próbuję wymyślić od nowa 'fuzzy search' i ogólnie wyważam otwarte drzwi.
Należało skorzystać z Elasticsearch lub innego tego typu rozwiązania.

Dalsze prace polegały na odpowiednim skonfigurowaniu ES. Towarzyszący kod PHP był trywialny, istotna była tylko odpowiednia jego organizacja.

Zacząłem od prostej analizy trigramowej, która dała dobre rezultaty, ale niepełne. Taki indeks nie radził sobie np. z akronimami, skrótami i żargonowymi określeniami szkół.
Uzupełniwszy konfigurację indeksu o te przypadki, nadal miałem problem ze słowami bliskimi znaczeniowo, ale będącymi semantycznie daleko od siebie (np. “technik” -> “techniczne”).

Odkryłem wtedy plugin do ES (analysis-stempel), który dostarcza analizator j. polskiego, uwzględniający odmianę wyrazów i sprowadzających je do wspólnego rdzenia.
To rozwiązało większość przypadków.

Akronimy generuję ręcznie podczas importu danych jako dodatkowe atrybuty dokumentu. ES nie dostarcza mechanizmów na takie okazje.

Ostatecznie na wynik wyszukiwania w ES składa się kilka czynników o różnej wadze: dopasowanie do akronimu, proste dopasowanie do nazwy oficjalnej, dopasowanie do nazwy z uwzględnieniem odmiany w j.polskim, dopasowanie do prefiksu słowa (najmniejsze znaczenie).

Dla załączonego zbioru danych rozwiązanie działa w 100% skutecznie. 
Konieczne są jednak testy na większym zbiorze i stosowne dostrojenie indeksu ES.


## Architektura
Kluczową funkcją aplikacji jest jak najlepsze dopasowywanie szkoły do podanej przez użytkownika frazy.

Aktualnie jest to realizowane przez komendę CLI oraz endpoint HTTP.<br/>
Jednakże takich przypadków użycia może być dowolnie wiele (np. wspomniane w zadaniu przetwarzanie użytkowników), 
dlatego implementacja musi być niezależna i łatwo dostępna dla innych modułów aplikacji.<br/>
Dodatkowo postanowiłem zorganizować kod w taki sposób, można było łatwo zmienić samą implementację (np. zmienić ES na coś innego), bez zmiany istniejących przypadków użycia.<br/>
Pojawił się zatem interfejs dla "dostawcy" wyszukiwania i powiązane z nim obiekty DTO.

Konkretna implementacja "dostawcy" wyszukiwania zawiera się w jednej klasie "ElasticsearchProvider".<br/>
Istotną częścią rozwiązania jest odpowiednie przygotowanie ES, za co odpowiedzialna jest klasa "ElasticsearchManager".<br/>
Powyższe klasy są dostarczane (przez Symfony) tam, gdzie są potrzebne, od razu gotowe do użycia.

### Założenia dodatkowe na potrzeby demonstracji

Nie uwzględniam w wyszukiwaniu miasta i typu szkoły.
Te kryteria służą tylko do zawężenia puli nazw szkół do przeszukania i nie mają istotnego wpływu na prezentację rozwiązania.

Nazwy klas, interfejsów, przestrzeni nazw itd., nie uwzględniają użycia kodu jako modułu (zależności) w innej aplikacji.

Konfiguracja Elasticsearch (nazw indeksów, parametrów itd.) jest zapisana na sztywno.

Szczątkowa obsługa błędów, np. podczas komunikacji z usługami, przetwarzaniem wyników. 
Produkcyjnie błędy powinny być odpowiednio przechwytywane, obsługiwane i logowane. 
Do klienta końcowego powinien docierać tylko rezultat lub komunikat o błędzie (i stosowny kod HTTP w przypadku API HTTP).

## CLI

### app:elastic:init
Inicjuje indeks "official_schools" w Elasticsearch oraz umieszcza w nim dokumenty (z /data/fixtures/schools.txt).<br/>
Można wykonywać wielokrotnie, indeks i dane zostaną wyczyszczone i załadowane ponownie.

### app:search [fraza]
Wywołane bez parametru uruchamia tryb interaktywnego wyszukiwania (dopasowywania) nazw. Zwraca trzy najlepsze wyniki w celach demonstracyjnych. <br/>
Wywołanie z parametrem zwraca wyniki dla frazy i kończy działanie.

## Endpoint HTTP /getOfficialName?phrase=
Prosty endpoint pokazowy. 
- Zwraca najlepsze dopasowanie nazwy szkoły (lub brak) do przesłanej frazy
  - Mógłby zwracać więcej dopasowań i służyć jako źródło danych podpowiedzi dla użytkownika (podczas wpisywania)
- Pokazuje zalety organizacji kodu (separacja odpowiedzialności, DI)
- Testowany jednostkowo z mockupem wyszukiwarki


## Testy
Dwa przykładowe testy różnego typu.
### Jednostkowy (tests/Unit/Controller/OfficialNameControllerTest.php)
Weryfikuje publiczne zachowanie endpointu /getOfficialName, z wykorzystaniem zaślepki dostawcy wyszukiwania (działa bez ES).

### Integracyjny (tests/Integration/SearchServiceTest.php)
Weryfikuje działanie dopasowywania, wykonując rzeczywiste zapytania.<br/>
Sprawdzamy poprawność inicjowania ES (indeks, dane) oraz komunikację z usługą.<br/>
Wymaga działającej aplikacji (wywołane ręcznie app:elastic:init)

Na potrzeby przykładu, korzysta z tych samych danych wejściowych (pliku), którymi inicjowany jest ES.<br/> 
Dzięki temu testujemy zawsze wszystkie przykładowe warianty, czego nie da się osiągnąć manualnie. 
Wydawało mi się, że już wszystko działa, a test ujawnił dwa nieobsłużone przypadki.
 
# Jak uruchomić
Zaczynamy od `git clone`.

Do działania niezbędna jest instancja Elasticsearch z zainstalowanym pluginem "analysis-stempel".<br/>
Na potrzeby zadania przyjąłem, że skorzystamy ze świeżo uruchomionej wersji deweloperskiej, bez kontroli dostępu itd.

Niestety nie ma gotowego obrazu ES z pluginem. 
Przygotowałem konfigurację Dockera, która zbuduje odpowiedni obraz i uruchomi instancję ES.<br/>
**Uwaga!** Obraz ES zajmuje ok. 1 GB.

```
# W repo mamy przygotowany lokalny katalog na dane Elasticsearch zamiast wolumenu. 
# Zamontowany w kontenerze katalog prawdopodobnie będzie inne ID właściciela.
# Żeby za dużo nie kombinować dajemy dostęp wszystkim
 
chmod 777 ./data/es_data

# Budujemy lokalny obraz ES z pluginem. Uruchomi się kontener i wystawi port 9200.
docker compose up --build
```

Mając działający ES, kod PHP uruchamiamy jak standardową aplikację Symfony.
```
composer install
# Konfigurujemy adres Elasticsearch w pliku .env.
php bin/console app:elastic:init

# Testy dopasowania fraz
php bin/console app:search

# Jeśli chcemy testować endpoint HTTP
php -S localhost:8000 -t public
```






