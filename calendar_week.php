<?php
/*
 * Compatibilitate cu link-urile vechi catre calendar_week.php.
 * Vizualizarea Saptamana este randerata acum direct in calendar.php?view=week.
 * Acest fisier face un redirect 301 pastrand query string-ul existent
 * (date, team_ids[], team, etc.), ca sa nu se strice bookmark-urile.
 */

$query = $_SERVER['QUERY_STRING'] ?? '';
parse_str($query, $params);
$params['view'] = 'week';
unset($params['view_old']);
$target = 'calendar.php?' . http_build_query($params);

header('Location: ' . $target, true, 301);
exit;
