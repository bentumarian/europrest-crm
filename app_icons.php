<?php

/*
|--------------------------------------------------------------------------
| app_icons.php
|--------------------------------------------------------------------------
| Iconuri SVG line, fără biblioteci externe.
| Apel: echo app_icon_svg('clients');
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/app_helpers.php';

if (!function_exists('app_icon_svg')) {
    function app_icon_svg(string $name): string
    {
        $aliases = [
            'task' => 'tasks',
            'appointment' => 'calendar',
            'appointments' => 'calendar',
            'client' => 'clients',
            'document' => 'documents',
            'contract' => 'contracts',
            'process' => 'processes',
            'report' => 'reports',
            'billing' => 'invoice',
            'invoice_paid' => 'invoice',
            'notification' => 'alert',
            'notifications' => 'alert',
        ];
        $name = $aliases[$name] ?? $name;

        $icons = [
            'dashboard' => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="3" width="8" height="8" rx="2"></rect><rect x="13" y="3" width="8" height="5" rx="2"></rect><rect x="13" y="10" width="8" height="11" rx="2"></rect><rect x="3" y="13" width="8" height="8" rx="2"></rect></svg>',
            'calendar'  => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="4"></rect><path d="M8 2v4"></path><path d="M16 2v4"></path><path d="M3 10h18"></path><path d="M8 14h3"></path><path d="M13 14h3"></path><path d="M8 18h3"></path></svg>',
            'tasks'     => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="3" width="16" height="18" rx="4"></rect><path d="M8 8h8"></path><path d="M8 12h8"></path><path d="M8 16h5"></path><path d="M16.5 15.5l1.2 1.2 2.3-2.7"></path></svg>',
            'clients'   => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 19c0-2.2-1.8-4-4-4s-4 1.8-4 4"></path><circle cx="12" cy="9" r="3"></circle><path d="M4.5 18.5c.4-2.1 1.7-3.8 3.5-4.8"></path><path d="M19.5 18.5c-.4-2.1-1.7-3.8-3.5-4.8"></path></svg>',
            'contracts' => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="3" width="14" height="18" rx="3"></rect><path d="M9 8h6"></path><path d="M9 12h6"></path><path d="M9 16h3"></path><path d="M16 17l1 1 2-2"></path></svg>',
            'documents' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 3h9l3 3v15H6z"></path><path d="M15 3v4h4"></path><path d="M9 10h6"></path><path d="M9 14h6"></path><path d="M9 18h4"></path></svg>',
            'offers'    => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="5" width="16" height="14" rx="3"></rect><path d="M8 9h8"></path><path d="M8 13h5"></path><path d="M16 15.5l2 2 3-4"></path><path d="M7 3v4"></path><path d="M17 3v4"></path></svg>',
            'processes' => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="3" width="14" height="18" rx="3"></rect><path d="M9 7h6"></path><path d="M9 11h6"></path><path d="M9 15h3"></path><path d="M14 17l1.5 1.5L19 15"></path></svg>',
            'series'    => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="5" width="16" height="14" rx="3"></rect><path d="M8 9h8"></path><path d="M8 13h4"></path><path d="M15 13h1.5"></path><path d="M7 3v4"></path><path d="M17 3v4"></path></svg>',
            'services'  => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3l1.4 3.2 3.5.4-2.6 2.3.7 3.4-3-1.8-3 1.8.7-3.4-2.6-2.3 3.5-.4L12 3z"></path><path d="M5 15h14"></path><path d="M7 19h10"></path></svg>',
            'team'      => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="8" r="3"></circle><path d="M3.5 19c.4-3 2.6-5 5.5-5s5.1 2 5.5 5"></path><circle cx="17" cy="10" r="2.5"></circle><path d="M15.5 15c2.5.2 4.4 1.8 5 4"></path></svg>',
            'reports'   => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="3" width="16" height="18" rx="4"></rect><path d="M8 17V11"></path><path d="M12 17V7"></path><path d="M16 17v-4"></path><path d="M7 17h10"></path></svg>',
            'star'      => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3.5l2.6 5.3 5.8.8-4.2 4.1 1 5.8L12 16.8l-5.2 2.7 1-5.8-4.2-4.1 5.8-.8L12 3.5Z"></path></svg>',
            'users'     => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="8" r="3"></circle><path d="M3.5 19c.5-3 2.7-5 5.5-5s5 2 5.5 5"></path><circle cx="17" cy="9" r="2.5"></circle><path d="M15.5 15c2.4.2 4.2 1.8 5 4"></path></svg>',
            'design'    => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3a9 9 0 0 0-9 9c0 4.4 3.6 8 8 8h1.5a2 2 0 0 0 0-4H12a1.5 1.5 0 0 1 0-3h1a8 8 0 0 0 8-8c0-1.1-.9-2-2-2h-7z"></path><circle cx="7.5" cy="10" r=".8"></circle><circle cx="10.5" cy="7.5" r=".8"></circle><circle cx="14" cy="7.5" r=".8"></circle></svg>',
            'company'   => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 21V7a2 2 0 0 1 2-2h7a2 2 0 0 1 2 2v14"></path><path d="M15 10h3a2 2 0 0 1 2 2v9"></path><path d="M8 9h3"></path><path d="M8 13h3"></path><path d="M8 17h3"></path><path d="M3 21h18"></path></svg>',
            'settings'  => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="3"></circle><path d="M12 2v3"></path><path d="M12 19v3"></path><path d="M2 12h3"></path><path d="M19 12h3"></path><path d="M4.9 4.9l2.1 2.1"></path><path d="M17 17l2.1 2.1"></path><path d="M19.1 4.9L17 7"></path><path d="M7 17l-2.1 2.1"></path></svg>',
            'invoice'   => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 3h12v18l-2-1.3-2 1.3-2-1.3-2 1.3-2-1.3L6 21V3Z"></path><path d="M9 8h6"></path><path d="M9 12h6"></path><path d="M9 16h4"></path></svg>',
            'stock'     => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7l8-4 8 4-8 4-8-4Z"></path><path d="M4 7v10l8 4 8-4V7"></path><path d="M12 11v10"></path><path d="M20 12l-8 4-8-4"></path></svg>',

            'plus'      => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14"></path><path d="M5 12h14"></path></svg>',
            'eye'       => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"></path><circle cx="12" cy="12" r="3"></circle></svg>',
            'edit'      => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20h4l10.5-10.5a2.1 2.1 0 0 0-3-3L5 17v3Z"></path><path d="M13.5 7.5l3 3"></path></svg>',
            'mail'      => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="3"></rect><path d="M4 7l8 6 8-6"></path></svg>',
            'phone'     => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.4 19.4 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1.9.3 1.7.6 2.5a2 2 0 0 1-.4 2.1L8 9.6a16 16 0 0 0 6.4 6.4l1.3-1.3a2 2 0 0 1 2.1-.4c.8.3 1.6.5 2.5.6A2 2 0 0 1 22 16.9Z"></path></svg>',
            'search'    => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="M20 20l-3.5-3.5"></path></svg>',
            'more'      => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="5" r="1"></circle><circle cx="12" cy="12" r="1"></circle><circle cx="12" cy="19" r="1"></circle></svg>',
            'check'     => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 6L9 17l-5-5"></path></svg>',
            'alert'     => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4l9 16H3L12 4Z"></path><path d="M12 9v5"></path><path d="M12 17h.01"></path></svg>',
            'clipboard' => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="4" width="14" height="17" rx="3"></rect><path d="M9 4.5A3 3 0 0 1 12 2a3 3 0 0 1 3 2.5"></path><path d="M9 9h6"></path><path d="M9 13h6"></path><path d="M9 17h3"></path></svg>',
            'logout'    => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 4H6.5A2.5 2.5 0 0 0 4 6.5v11A2.5 2.5 0 0 0 6.5 20H10"></path><path d="M14 8l4 4-4 4"></path><path d="M18 12H9"></path></svg>',
            'trash'     => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18"></path><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"></path><path d="M10 11v6"></path><path d="M14 11v6"></path></svg>',
            'send'      => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M22 2L11 13"></path><path d="M22 2l-7 20-4-9-9-4 20-7Z"></path></svg>',
            'undo'      => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 7v6h6"></path><path d="M21 17a9 9 0 0 0-15-6.7L3 13"></path></svg>',
        ];

        $svg = $icons[$name] ?? $icons['dashboard'];

        return '<span class="nav-icon nav-icon-' . app_h($name) . '">' . $svg . '</span>';
    }
}

