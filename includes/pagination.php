<?php
/**
 * Reusable Mobile-Responsive Pagination Component
 * Holy Cross College (Autonomous) Examination System
 * Soft Corners & Pill Controls
 */

if (!function_exists('render_pagination')) {
    /**
     * Renders a responsive pagination control
     *
     * @param int $currentPage Current page (1-based)
     * @param int $totalPages Total number of pages
     * @param int $totalItems Total items count
     * @param int $perPage Items per page
     * @param array $queryParams Existing query parameters to preserve
     * @param string $pageParam Query string param name for page number (default: 'p')
     * @param string $fragment URL anchor fragment (e.g. '#data-table')
     * @return string HTML markup
     */
    function render_pagination(
        int $currentPage,
        int $totalPages,
        int $totalItems,
        int $perPage = 20,
        array $queryParams = [],
        string $pageParam = 'p',
        string $fragment = ''
    ): string {
        if ($totalPages <= 1 && $totalItems <= $perPage) {
            if ($totalItems === 0) return '';
            $from = $totalItems > 0 ? 1 : 0;
            $to = $totalItems;
            return '
            <div class="flex flex-col sm:flex-row items-center justify-between gap-3 px-4 py-3 bg-white/90 border-t border-slate-200/80 rounded-b-2xl text-xs text-slate-500 font-medium">
                <div>Showing <span class="font-bold text-slate-800">' . $from . '</span> to <span class="font-bold text-slate-800">' . $to . '</span> of <span class="font-bold text-slate-800">' . number_format($totalItems) . '</span> entries</div>
                <div class="text-[11px] text-slate-400 font-mono">All results displayed</div>
            </div>';
        }

        $currentPage = max(1, min($currentPage, max(1, $totalPages)));
        $from = ($currentPage - 1) * $perPage + 1;
        $to = min($totalItems, $currentPage * $perPage);

        // Helper to build URL
        $buildUrl = function(int $page) use ($queryParams, $pageParam, $fragment): string {
            $params = $queryParams;
            $params[$pageParam] = $page;
            $qs = http_build_query($params);
            $baseUrl = strtok($_SERVER['REQUEST_URI'] ?? '', '?');
            return htmlspecialchars($baseUrl . ($qs ? '?' . $qs : '') . $fragment);
        };

        // Window calculation
        $links = [];
        $range = 2; // Number of pages to show before and after current
        $start = max(1, $currentPage - $range);
        $end = min($totalPages, $currentPage + $range);

        if ($start > 1) {
            $links[] = 1;
            if ($start > 2) {
                $links[] = '...';
            }
        }

        for ($i = $start; $i <= $end; $i++) {
            $links[] = $i;
        }

        if ($end < $totalPages) {
            if ($end < $totalPages - 1) {
                $links[] = '...';
            }
            $links[] = $totalPages;
        }

        $prevDisabled = ($currentPage <= 1);
        $nextDisabled = ($currentPage >= $totalPages);

        $prevUrl = $prevDisabled ? '#' : $buildUrl($currentPage - 1);
        $nextUrl = $nextDisabled ? '#' : $buildUrl($currentPage + 1);

        $html = '<div class="flex flex-col sm:flex-row items-center justify-between gap-3 px-4 py-3 bg-white/90 border-t border-slate-200/80 rounded-b-2xl text-xs">';
        
        // Count Summary
        $html .= '<div class="text-slate-500 font-medium text-center sm:text-left">';
        $html .= 'Showing <span class="font-bold text-slate-800">' . number_format($from) . '</span> to <span class="font-bold text-slate-800">' . number_format($to) . '</span> of <span class="font-bold text-slate-800">' . number_format($totalItems) . '</span> records';
        $html .= '</div>';

        // Navigation controls
        $html .= '<div class="flex items-center space-x-1.5">';

        // Prev Button
        if ($prevDisabled) {
            $html .= '<span class="inline-flex items-center px-3 py-1.5 rounded-xl border border-slate-200 bg-slate-100 text-slate-300 font-bold text-xs cursor-not-allowed select-none"><svg class="w-3.5 h-3.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>Prev</span>';
        } else {
            $html .= '<a href="' . $prevUrl . '" class="inline-flex items-center px-3 py-1.5 rounded-xl border border-slate-200 bg-white text-slate-700 hover:bg-indigo-50 hover:text-indigo-700 font-bold text-xs transition shadow-sm"><svg class="w-3.5 h-3.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>Prev</a>';
        }

        // Desktop Number Buttons
        $html .= '<div class="hidden sm:flex items-center space-x-1">';
        foreach ($links as $link) {
            if ($link === '...') {
                $html .= '<span class="px-2 py-1 text-slate-400 font-bold">...</span>';
            } elseif ($link == $currentPage) {
                $html .= '<span class="px-3 py-1.5 rounded-xl bg-indigo-600 text-white font-black text-xs shadow-md">' . $link . '</span>';
            } else {
                $html .= '<a href="' . $buildUrl((int)$link) . '" class="px-3 py-1.5 rounded-xl border border-slate-200 bg-white text-slate-700 hover:bg-indigo-50 hover:text-indigo-700 font-bold text-xs transition shadow-sm">' . $link . '</a>';
            }
        }
        $html .= '</div>';

        // Mobile Page Indicator
        $html .= '<span class="sm:hidden px-3 py-1 bg-slate-100 text-slate-700 font-bold text-xs rounded-xl">' . $currentPage . ' / ' . $totalPages . '</span>';

        // Next Button
        if ($nextDisabled) {
            $html .= '<span class="inline-flex items-center px-3 py-1.5 rounded-xl border border-slate-200 bg-slate-100 text-slate-300 font-bold text-xs cursor-not-allowed select-none">Next<svg class="w-3.5 h-3.5 ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg></span>';
        } else {
            $html .= '<a href="' . $nextUrl . '" class="inline-flex items-center px-3 py-1.5 rounded-xl border border-slate-200 bg-white text-slate-700 hover:bg-indigo-50 hover:text-indigo-700 font-bold text-xs transition shadow-sm">Next<svg class="w-3.5 h-3.5 ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg></a>';
        }

        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }
}
