=== Heterodyna Plugin Exporter ===
Contributors: heterodynateamsoftware
Tags: export, plugin, zip, backup, developer
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

Eksportuje kod zainstalowanej wtyczki WordPress do pliku ZIP.

== Instalacja ==

1. Wgraj ZIP przez Wtyczki -> Dodaj nową -> Wyślij wtyczkę.
2. Aktywuj Heterodyna Plugin Exporter.
3. Otwórz Narzędzia -> Heterodyna Plugin Exporter.
4. Przy wybranej wtyczce kliknij Pobierz ZIP.

== Bezpieczeństwo ==

Eksport wymaga uprawnienia manage_options i poprawnego nonce WordPress.
Wtyczka sprawdza, czy eksportowana ścieżka znajduje się wewnątrz wp-content/plugins.
Nie podąża za linkami symbolicznymi i nie modyfikuje eksportowanego kodu.

== Changelog ==

= 1.0.0 =
* Pierwsze wydanie.
* Eksport pełnego katalogu zainstalowanej wtyczki.
* Obsługa wtyczek jednoplikowych.
