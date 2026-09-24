import React from "react";
import { useTranslation } from "react-i18next";
import { IconChevronDown, IconDownload, IconFileSpreadsheet, IconFileTypeCsv } from "@tabler/icons-react";

/**
 * Download button with an Excel / CSV choice. Plain links (not Inertia visits) so the browser
 * saves the file; `params` are passed as query string (e.g. the current page filters).
 */
export default function ExportMenu({ routeName, params = {}, label }) {
    const { t } = useTranslation();
    const clean = Object.fromEntries(Object.entries(params).filter(([, v]) => v !== null && v !== undefined && v !== ""));
    const href = (format) => route(routeName, { ...clean, ...(format === "csv" ? { format: "csv" } : {}) });

    return (
        <details className="group relative">
            <summary className="inline-flex cursor-pointer list-none items-center gap-2 rounded-xl border border-slate-200 px-3 py-2 text-sm font-medium text-slate-600 transition-colors hover:bg-slate-100 dark:border-slate-700 dark:text-slate-400 dark:hover:bg-slate-800 [&::-webkit-details-marker]:hidden">
                <IconDownload size={18} />
                {label || t("exportMenu.button")}
                <IconChevronDown size={14} className="transition-transform group-open:rotate-180" />
            </summary>
            <div className="absolute right-0 z-30 mt-1 w-44 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-lg dark:border-slate-700 dark:bg-slate-900">
                <a href={href("xlsx")} className="flex items-center gap-2 px-3 py-2 text-sm text-slate-700 hover:bg-slate-50 dark:text-slate-200 dark:hover:bg-slate-800">
                    <IconFileSpreadsheet size={16} className="text-emerald-600" />
                    {t("exportMenu.excel")}
                </a>
                <a href={href("csv")} className="flex items-center gap-2 px-3 py-2 text-sm text-slate-700 hover:bg-slate-50 dark:text-slate-200 dark:hover:bg-slate-800">
                    <IconFileTypeCsv size={16} className="text-sky-600" />
                    {t("exportMenu.csv")}
                </a>
            </div>
        </details>
    );
}
