<?php
/*
 * Acest fisier este obsolet. Fusese un wrapper CSS peste calendar.php
 * (folosit doar pe Zi/Luna ca sa uniformizeze headerul cu calendar_week.php),
 * dar dupa unificarea calendarului totul se randereaza direct in calendar.php.
 *
 * Pastram un redirect ca sa nu apara 404 daca cineva l-a salvat ca bookmark.
 * Acest fisier poate fi sters in siguranta dintr-un viitor commit.
 */

$query = $_SERVER['QUERY_STRING'] ?? '';
header('Location: calendar.php' . ($query !== '' ? '?' . $query : ''), true, 301);
exit;
