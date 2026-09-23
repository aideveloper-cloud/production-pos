import React, { useState } from "react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { Head, router } from "@inertiajs/react";
import { useTranslation } from "react-i18next";
import Table from "@/Components/Dashboard/Table";
import Pagination from "@/Components/Dashboard/Pagination";
import { IconArrowDownRight, IconArrowUpRight, IconHistory, IconSearch } from "@tabler/icons-react";

const inputClass =
    "h-11 rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm text-slate-800 outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200";

const TYPE_STYLES = {
    RECEIVE: "bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300",
    TRANSFER_IN: "bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300",
    ISSUE: "bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300",
    CONSUMPTION: "bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300",
    TRANSFER_OUT: "bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300",
    COUNT: "bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300",
    ADJUSTMENT: "bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300",
};

const formatQty = (value) =>
    Number(value || 0).toLocaleString(undefined, { maximumFractionDigits: 4 });

export default function Index({ ledgers, totals, filters, warehouses = [], products = [], movementTypes = [] }) {
    const { t, i18n } = useTranslation();
    const [search, setSearch] = useState(filters.search || "");

    const locale = i18n.language === "th" ? "th-TH" : i18n.language === "id" ? "id-ID" : "en-GB";
    const formatDateTime = (value) =>
        value
            ? new Intl.DateTimeFormat(locale, { dateStyle: "medium", timeStyle: "short" }).format(new Date(value))
            : "-";

    const updateFilter = (key, value) => {
        router.get(
            route("stock-ledgers.index"),
            { ...filters, [key]: value },
            { preserveState: true, replace: true }
        );
    };

    const referenceLabel = (ledger) =>
        t(`stockLedger.references.${ledger.reference_type}`, { defaultValue: ledger.reference_type || "-" });

    return (
        <>
            <Head title={t("stockLedger.title")} />

            <div className="mb-6">
                <h1 className="text-2xl font-bold text-slate-900 dark:text-white">{t("stockLedger.title")}</h1>
                <p className="text-sm text-slate-500 dark:text-slate-400">{t("stockLedger.subtitle")}</p>
            </div>

            <div className="mb-4 grid grid-cols-1 gap-3 rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900 md:grid-cols-3 xl:grid-cols-6">
                <select value={filters.warehouse_id || ""} onChange={(e) => updateFilter("warehouse_id", e.target.value)} className={inputClass}>
                    <option value="">{t("stockLedger.filters.allWarehouses")}</option>
                    {warehouses.map((w) => (
                        <option key={w.id} value={w.id}>
                            {w.code} — {w.name}
                        </option>
                    ))}
                </select>

                <select value={filters.product_id || ""} onChange={(e) => updateFilter("product_id", e.target.value)} className={inputClass}>
                    <option value="">{t("stockLedger.filters.allMaterials")}</option>
                    {products.map((p) => (
                        <option key={p.id} value={p.id}>
                            {p.sku} — {p.title}
                        </option>
                    ))}
                </select>

                <select value={filters.movement_type || ""} onChange={(e) => updateFilter("movement_type", e.target.value)} className={inputClass}>
                    <option value="">{t("stockLedger.filters.allTypes")}</option>
                    {movementTypes.map((type) => (
                        <option key={type} value={type}>
                            {t(`stockLedger.types.${type}`, { defaultValue: type })}
                        </option>
                    ))}
                </select>

                <input type="date" value={filters.date_from || ""} onChange={(e) => updateFilter("date_from", e.target.value)} className={inputClass} aria-label={t("stockLedger.filters.dateFrom")} />
                <input type="date" value={filters.date_to || ""} onChange={(e) => updateFilter("date_to", e.target.value)} className={inputClass} aria-label={t("stockLedger.filters.dateTo")} />

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        updateFilter("search", search);
                    }}
                    className="relative"
                >
                    <IconSearch size={16} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
                    <input
                        type="search"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        placeholder={t("stockLedger.filters.search")}
                        className={`${inputClass} w-full pl-9`}
                    />
                </form>
            </div>

            <div className="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
                <SummaryCard label={t("stockLedger.totals.in")} value={formatQty(totals.qty_in)} icon={<IconArrowDownRight size={18} />} tone="text-emerald-600" />
                <SummaryCard label={t("stockLedger.totals.out")} value={formatQty(totals.qty_out)} icon={<IconArrowUpRight size={18} />} tone="text-rose-600" />
                <SummaryCard label={t("stockLedger.totals.rows")} value={totals.rows.toLocaleString()} icon={<IconHistory size={18} />} tone="text-slate-600" />
            </div>

            <Table.Card title={t("stockLedger.tableTitle")}>
                <Table>
                    <Table.Thead>
                        <tr>
                            <Table.Th>{t("stockLedger.columns.time")}</Table.Th>
                            <Table.Th>{t("stockLedger.columns.material")}</Table.Th>
                            <Table.Th>{t("stockLedger.columns.warehouse")}</Table.Th>
                            <Table.Th>{t("stockLedger.columns.type")}</Table.Th>
                            <Table.Th className="!text-right">{t("stockLedger.columns.qty")}</Table.Th>
                            <Table.Th className="!text-right">{t("stockLedger.columns.balance")}</Table.Th>
                            <Table.Th>{t("stockLedger.columns.reference")}</Table.Th>
                            <Table.Th>{t("stockLedger.columns.by")}</Table.Th>
                        </tr>
                    </Table.Thead>
                    <Table.Tbody>
                        {ledgers.data.length > 0 ? (
                            ledgers.data.map((ledger) => {
                                const qty = Number(ledger.qty);
                                return (
                                    <tr key={ledger.id} className="transition-colors hover:bg-slate-50 dark:hover:bg-slate-800/50">
                                        <Table.Td className="whitespace-nowrap text-sm">{formatDateTime(ledger.posted_at)}</Table.Td>
                                        <Table.Td>
                                            <p className="font-medium text-slate-800 dark:text-slate-200">{ledger.product?.sku || "-"}</p>
                                            <p className="text-xs text-slate-500 dark:text-slate-400">{ledger.product?.title}</p>
                                            {ledger.physical_unit && (
                                                <p className="mt-0.5 font-mono text-xs text-slate-400">
                                                    {ledger.physical_unit.unit_code}
                                                    {ledger.physical_unit.lot_code ? ` · ${ledger.physical_unit.lot_code}` : ""}
                                                </p>
                                            )}
                                        </Table.Td>
                                        <Table.Td className="whitespace-nowrap">{ledger.warehouse?.code || "-"}</Table.Td>
                                        <Table.Td>
                                            <span className={`inline-flex whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-semibold ${TYPE_STYLES[ledger.movement_type] || "bg-slate-100 text-slate-700"}`}>
                                                {t(`stockLedger.types.${ledger.movement_type}`, { defaultValue: ledger.movement_type })}
                                            </span>
                                        </Table.Td>
                                        <Table.Td className={`whitespace-nowrap text-right font-semibold tabular-nums ${qty < 0 ? "text-rose-600" : "text-emerald-600"}`}>
                                            {qty > 0 ? "+" : ""}
                                            {formatQty(qty)} <span className="text-xs font-normal text-slate-400">{ledger.uom}</span>
                                        </Table.Td>
                                        <Table.Td className="whitespace-nowrap text-right tabular-nums text-slate-600 dark:text-slate-300">{formatQty(ledger.qty_after)}</Table.Td>
                                        <Table.Td>
                                            <p className="text-sm font-medium text-slate-700 dark:text-slate-300">{referenceLabel(ledger)}</p>
                                            <p className="text-xs text-slate-500 dark:text-slate-400">{ledger.meta?.document_number || ledger.notes || "-"}</p>
                                        </Table.Td>
                                        <Table.Td className="whitespace-nowrap text-sm">{ledger.operator_name || ledger.creator?.name || "-"}</Table.Td>
                                    </tr>
                                );
                            })
                        ) : (
                            <Table.Empty colSpan={8} message={<div className="text-slate-500 dark:text-slate-400">{t("stockLedger.empty")}</div>}>
                                <div className="mx-auto mb-3 flex h-16 w-16 items-center justify-center rounded-full bg-slate-100 dark:bg-slate-800">
                                    <IconHistory size={28} className="text-slate-400" />
                                </div>
                            </Table.Empty>
                        )}
                    </Table.Tbody>
                </Table>
            </Table.Card>

            {ledgers.last_page > 1 && <Pagination links={ledgers.links} />}
        </>
    );
}

function SummaryCard({ label, value, icon, tone }) {
    return (
        <div className="flex items-center justify-between rounded-2xl border border-slate-200 bg-white px-4 py-3 dark:border-slate-800 dark:bg-slate-900">
            <div>
                <p className="text-xs text-slate-500 dark:text-slate-400">{label}</p>
                <p className="text-lg font-bold tabular-nums text-slate-900 dark:text-white">{value}</p>
            </div>
            <span className={tone}>{icon}</span>
        </div>
    );
}

Index.layout = (page) => <DashboardLayout children={page} />;
