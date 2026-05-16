<?php
// Wrapper sigur pentru calendar.php pe Zi/Luna.
// Nu schimba logica de programare; doar uniformizeaza vizual headerul cu calendar_week.php.

ob_start();
require __DIR__ . '/calendar.php';
$html = ob_get_clean();

$css = <<<'CSS'
<style>
/* Header calendar uniformizat cu pagina de saptamana pe tehnicieni */
body.calendar-page .calendar-topbar {
    margin: 0 0 14px !important;
    padding: 0 !important;
    background: transparent !important;
    border: 0 !important;
    box-shadow: none !important;
}
body.calendar-page .calendar-toolbar {
    display: flex !important;
    gap: 10px !important;
    align-items: center !important;
    justify-content: space-between !important;
    flex-wrap: wrap !important;
    margin: 0 !important;
    padding: 12px !important;
    background: #F5F7FB !important;
    border: 1px solid var(--border) !important;
    border-radius: 18px !important;
    box-shadow: none !important;
}
body.calendar-page .calendar-line,
body.calendar-page .calendar-date-line,
body.calendar-page .calendar-filter-line,
body.calendar-page .calendar-action-line {
    display: flex !important;
    gap: 10px !important;
    align-items: center !important;
    flex-wrap: wrap !important;
    margin: 0 !important;
}
body.calendar-page .calendar-date-form,
body.calendar-page #filterForm {
    display: flex !important;
    gap: 10px !important;
    align-items: center !important;
    margin: 0 !important;
}
body.calendar-page .calendar-toolbar .btn,
body.calendar-page .calendar-toolbar .select,
body.calendar-page .calendar-toolbar .date-input {
    min-height: 40px !important;
    border-radius: 12px !important;
}
body.calendar-page .calendar-toolbar .date-input {
    min-width: 165px !important;
    text-align: center !important;
    font-weight: 800 !important;
}
@media(max-width:860px){
    body.calendar-page .calendar-toolbar { padding: 8px !important; }
    body.calendar-page .calendar-line,
    body.calendar-page .calendar-date-line,
    body.calendar-page .calendar-filter-line,
    body.calendar-page .calendar-action-line { width: 100% !important; justify-content: center !important; }
    body.calendar-page .calendar-toolbar .select,
    body.calendar-page .calendar-toolbar .date-input { min-width: 0 !important; width: auto !important; }
}
</style>
CSS;

$html = str_replace('</head>', $css . "\n</head>", $html);
$html = str_replace('calendar_week.php?date=', 'calendar_week.php?date=', $html);

echo $html;
