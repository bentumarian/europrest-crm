<?php
/*
 * Compatibilitate cu link-urile vechi catre calendar_week.php.
 * Vizualizarea Saptamana este randerata acum direct in calendar.php?view=week.
 *
 * Folosim include in loc de redirect HTTP pentru:
 *  1) a evita orice bucla daca .htaccess vechi este inca activ pe server
 *     (vechea regula trimitea calendar.php?view=week -> calendar_week.php);
 *  2) a pastra URL-ul curent in bara (bookmark-urile vechi continua sa
 *     functioneze fara redirect vizibil).
 */

$_GET['view'] = 'week';
$_REQUEST['view'] = 'week';
require __DIR__ . '/calendar.php';
