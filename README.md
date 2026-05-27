# Toggl Tracker

Prosta strona PHP łącząca się z API Toggl Track i wyświetlająca podsumowanie
bieżącego miesiąca.

## Funkcje

- Tabela zadań z bieżącego miesiąca posortowana po czasie (malejąco).
- Grupowanie po `description` wpisu czasu.
- Wiersz sumy w stopce tabeli.
- Pasek wykorzystania miesięcznego limitu (domyślnie 40 h = 100%).
- Pasek zmienia kolor na czerwony i pokazuje wartości powyżej 100%
  w przypadku przekroczenia limitu.

## Konfiguracja

1. Skopiuj `config.example.php` do `config.php`.
2. Wpisz swój token Toggl w polu `toggl_api_token`.
3. (Opcjonalnie) zmień `monthly_limit_hours`.

Plik `config.php` jest ignorowany przez git.

## Uruchomienie

Wymagany PHP 8.0+ z rozszerzeniem cURL.

```bash
php -S localhost:8000
```

Następnie otwórz `http://localhost:8000` w przeglądarce.

## API Toggl

Skrypt korzysta z endpointu:

```
GET https://api.track.toggl.com/api/v9/me/time_entries?start_date=...&end_date=...
```

Autoryzacja: HTTP Basic, `{api_token}:api_token`.
