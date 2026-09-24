import DashboardLayout from "@/Layouts/DashboardLayout";
import { Head, Link, usePage } from "@inertiajs/react";
import { useTranslation } from "react-i18next";
import {
    IconBuildingWarehouse,
    IconScale,
    IconArrowsExchange,
    IconPackage,
} from "@tabler/icons-react";
import ExportMenu from "@/Components/Dashboard/ExportMenu";
import hasAnyPermission from "@/Utils/Permission";

export default function WarehouseOverview({
    warehouses = [],
    recentMovements = [],
    isAdmin = false,
    floorUrl,
}) {
    const { t } = useTranslation();
    const flash = usePage().props.flash;

    return (
        <DashboardLayout>
            <Head title={t("warehouse.overview.title")} />

            <div className="space-y-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold text-slate-900">
                            {t("warehouse.overview.title")}
                        </h1>
                        <p className="text-sm text-slate-500">
                            {isAdmin
                                ? t("warehouse.overview.subtitleAdmin")
                                : t("warehouse.overview.subtitleUser")}
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                    {hasAnyPermission(["products-export"]) && (
                        <>
                            <ExportMenu routeName="export.warehouse.balances" label={t("exportMenu.balances")} />
                            <ExportMenu routeName="export.warehouse.materials" label={t("exportMenu.materials")} />
                        </>
                    )}
                    <Link
                        href={floorUrl}
                        className="inline-flex items-center gap-2 rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-medium text-white hover:bg-slate-800"
                    >
                        <IconScale size={18} />
                        {t("warehouse.overview.openFloor")}
                    </Link>
                    </div>
                </div>

                {flash?.success && (
                    <div className="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                        {flash.success}
                    </div>
                )}

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    {warehouses.map((wh) => (
                        <div
                            key={wh.id}
                            className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"
                        >
                            <div className="mb-3 flex items-center gap-2 text-slate-700">
                                <IconBuildingWarehouse size={20} strokeWidth={1.5} />
                                <div>
                                    <p className="text-sm font-semibold">{wh.name}</p>
                                    <p className="text-xs text-slate-400">{wh.code}</p>
                                </div>
                            </div>
                            <dl className="space-y-2 text-sm">
                                <div className="flex justify-between">
                                    <dt className="text-slate-500">
                                        {t("warehouse.overview.activeUnits")}
                                    </dt>
                                    <dd className="font-medium">{wh.active_units}</dd>
                                </div>
                                <div className="flex justify-between">
                                    <dt className="text-slate-500">{t("warehouse.overview.skus")}</dt>
                                    <dd className="font-medium">{wh.sku_count}</dd>
                                </div>
                                <div className="flex justify-between">
                                    <dt className="text-slate-500">
                                        {t("warehouse.overview.totalWeight")}
                                    </dt>
                                    <dd className="font-medium">{wh.total_actual_weight} kg</dd>
                                </div>
                                <div className="flex justify-between">
                                    <dt className="text-slate-500">
                                        {t("warehouse.overview.ledgerQty")}
                                    </dt>
                                    <dd className="font-medium">{wh.ledger_on_hand_qty}</dd>
                                </div>
                                <div className="flex justify-between">
                                    <dt className="text-slate-500">
                                        {t("warehouse.overview.todayMoves")}
                                    </dt>
                                    <dd className="font-medium">{wh.today_movements}</dd>
                                </div>
                            </dl>
                            <Link
                                href={`${floorUrl}?warehouse_id=${wh.id}`}
                                className="mt-4 inline-flex text-sm font-medium text-slate-900 underline-offset-2 hover:underline"
                            >
                                {t("warehouse.overview.openFloorLink")} →
                            </Link>
                        </div>
                    ))}
                </div>

                <div className="rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <div className="flex items-center gap-2 border-b border-slate-100 px-5 py-4">
                        <IconArrowsExchange size={18} />
                        <h2 className="font-semibold text-slate-900">
                            {t("warehouse.overview.recentMovements")}
                        </h2>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-left text-sm">
                            <thead className="bg-slate-50 text-slate-500">
                                <tr>
                                    <th className="px-4 py-3 font-medium">
                                        {t("warehouse.overview.when")}
                                    </th>
                                    <th className="px-4 py-3 font-medium">
                                        {t("warehouse.overview.type")}
                                    </th>
                                    <th className="px-4 py-3 font-medium">
                                        {t("warehouse.overview.sku")}
                                    </th>
                                    <th className="px-4 py-3 font-medium">
                                        {t("warehouse.overview.warehouse")}
                                    </th>
                                    <th className="px-4 py-3 font-medium">
                                        {t("warehouse.overview.unit")}
                                    </th>
                                    <th className="px-4 py-3 font-medium">
                                        {t("warehouse.overview.qty")}
                                    </th>
                                    <th className="px-4 py-3 font-medium">
                                        {t("warehouse.overview.weight")}
                                    </th>
                                    <th className="px-4 py-3 font-medium">
                                        {t("warehouse.overview.operator")}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {recentMovements.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={8}
                                            className="px-4 py-8 text-center text-slate-400"
                                        >
                                            <IconPackage className="mx-auto mb-2 opacity-40" />
                                            {t("warehouse.overview.emptyMovements")}
                                        </td>
                                    </tr>
                                )}
                                {recentMovements.map((row) => (
                                    <tr key={row.id} className="border-t border-slate-100">
                                        <td className="px-4 py-3 text-slate-500">
                                            {row.posted_at
                                                ? new Date(row.posted_at).toLocaleString("th-TH")
                                                : "—"}
                                        </td>
                                        <td className="px-4 py-3 font-medium">{row.movement_type}</td>
                                        <td className="px-4 py-3">
                                            <div>{row.sku}</div>
                                            <div className="text-xs text-slate-400">
                                                {row.product_title}
                                            </div>
                                        </td>
                                        <td className="px-4 py-3">{row.warehouse_name}</td>
                                        <td className="px-4 py-3">{row.unit_code || "—"}</td>
                                        <td className="px-4 py-3">
                                            {row.qty} {row.uom}
                                        </td>
                                        <td className="px-4 py-3">
                                            {row.weight_kg != null
                                                ? `${row.weight_kg} กก.`
                                                : "—"}
                                        </td>
                                        <td className="px-4 py-3 font-medium text-slate-800">
                                            {row.operator_name || "—"}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </DashboardLayout>
    );
}
