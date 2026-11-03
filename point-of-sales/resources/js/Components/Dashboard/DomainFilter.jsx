const TONES = {
    RM: "bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300",
    FG: "bg-sky-100 text-sky-800 dark:bg-sky-500/15 dark:text-sky-300",
};

/** Small RM / FG tag next to a product. Renders nothing for products without a domain. */
export function DomainBadge({ domain, className = "" }) {
    if (!TONES[domain]) return null;

    return (
        <span className={`inline-flex rounded px-1.5 py-0.5 text-[10px] font-bold leading-none ${TONES[domain]} ${className}`}>
            {domain}
        </span>
    );
}

const OPTIONS = [
    ["", "ทั้งหมด"],
    ["RM", "RM วัตถุดิบ"],
    ["FG", "FG สำเร็จรูป"],
];

/** Segmented "all / RM / FG" switch used by the admin list pages; `value` is "" for both. */
export default function DomainFilter({ value = "", onChange, className = "" }) {
    return (
        <div className={`inline-flex rounded-xl bg-slate-100 p-1 dark:bg-slate-800 ${className}`} role="group" aria-label="RM / FG">
            {OPTIONS.map(([option, label]) => (
                <button
                    key={option || "all"}
                    type="button"
                    onClick={() => onChange(option)}
                    className={`rounded-lg px-3 py-1.5 text-sm font-semibold transition-colors ${
                        (value || "") === option
                            ? "bg-white text-slate-900 shadow-sm dark:bg-slate-700 dark:text-white"
                            : "text-slate-500 hover:text-slate-700 dark:text-slate-400"
                    }`}
                >
                    {label}
                </button>
            ))}
        </div>
    );
}
