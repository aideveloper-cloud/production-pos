import DashboardLayout from "@/Layouts/DashboardLayout";
import UnitLabelPrintModal from "@/Components/Barcode/UnitLabelPrintModal";
import { Head, router } from "@inertiajs/react";
import { useMemo, useState } from "react";
import { useTranslation } from "react-i18next";
import { IconBarcode, IconPrinter, IconSearch } from "@tabler/icons-react";

export default function LabelsIndex({
    warehouses = [],
    currentWarehouseId = null,
    search = "",
    units = [],
}) {
    const { t } = useTranslation();
    const [q, setQ] = useState(search || "");
    const [selectedIds, setSelectedIds] = useState([]);
    const [printOpen, setPrintOpen] = useState(false);

    const selectedUnits = useMemo(
        () => units.filter((u) => selectedIds.includes(u.id)),
        [units, selectedIds]
    );

    function applyFilters(next = {}) {
        router.get(
            route("labels.index"),
            {
                warehouse_id: next.warehouse_id ?? currentWarehouseId ?? undefined,
                q: next.q ?? q,
            },
            { preserveState: true, replace: true }
        );
    }

    function toggle(id) {
        setSelectedIds((prev) =>
            prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]
        );
    }

    function toggleAll() {
        if (selectedIds.length === units.length) {
            setSelectedIds([]);
        } else {
            setSelectedIds(units.map((u) => u.id));
        }
    }

    return (
        <DashboardLayout>
            <Head title={t("warehouse.labels.title", { defaultValue: "ป้ายหน่วย (Label)" })} />

            <div className="space-y-4">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="flex items-center gap-2 text-2xl font-semibold text-slate-900">
                            <IconBarcode size={26} />
                            {t("warehouse.labels.title", { defaultValue: "ป้ายหน่วย (Label)" })}
                        </h1>
                        <p className="text-sm text-slate-500">
                            {t("warehouse.labels.subtitle", {
                                defaultValue: "สร้างและพิมพ์ป้ายบาร์โค้ดสำหรับหน่วยบนพื้นคลัง",
                            })}
                        </p>
                    </div>
                    <button
                        type="button"
                        disabled={selectedUnits.length === 0}
                        onClick={() => setPrintOpen(true)}
                        className="inline-flex items-center gap-2 rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-40"
                    >
                        <IconPrinter size={16} />
                        {t("warehouse.labels.printSelected", {
                            defaultValue: "พิมพ์ป้ายที่เลือก",
                        })}{" "}
                        ({selectedUnits.length})
                    </button>
                </div>

                <div className="flex flex-wrap gap-2 rounded-2xl border border-slate-200 bg-white p-3">
                    <select
                        value={currentWarehouseId || ""}
                        onChange={(e) =>
                            applyFilters({
                                warehouse_id: e.target.value ? Number(e.target.value) : null,
                            })
                        }
                        className="rounded-xl border-slate-200 text-sm"
                    >
                        <option value="">
                            {t("warehouse.labels.allWarehouses", { defaultValue: "ทุกคลัง" })}
                        </option>
                        {warehouses.map((wh) => (
                            <option key={wh.id} value={wh.id}>
                                {wh.name}
                            </option>
                        ))}
                    </select>
                    <div className="relative min-w-[16rem] flex-1">
                        <IconSearch
                            size={16}
                            className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400"
                        />
                        <input
                            value={q}
                            onChange={(e) => setQ(e.target.value)}
                            onKeyDown={(e) => {
                                if (e.key === "Enter") applyFilters({ q });
                            }}
                            placeholder={t("warehouse.labels.search", {
                                defaultValue: "ค้นหารหัสหน่วย / SKU…",
                            })}
                            className="w-full rounded-xl border-slate-200 py-2 pl-9 text-sm"
                        />
                    </div>
                    <button
                        type="button"
                        onClick={() => applyFilters({ q })}
                        className="rounded-xl bg-slate-100 px-4 py-2 text-sm font-medium"
                    >
                        ค้นหา
                    </button>
                </div>

                <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white">
                    <table className="min-w-full text-left text-sm">
                        <thead className="bg-slate-50 text-slate-500">
                            <tr>
                                <th className="px-4 py-3">
                                    <input
                                        type="checkbox"
                                        checked={
                                            units.length > 0 && selectedIds.length === units.length
                                        }
                                        onChange={toggleAll}
                                    />
                                </th>
                                <th className="px-4 py-3 font-medium">รหัสหน่วย</th>
                                <th className="px-4 py-3 font-medium">สินค้า</th>
                                <th className="px-4 py-3 font-medium">คลัง</th>
                                <th className="px-4 py-3 font-medium">น้ำหนัก</th>
                                <th className="px-4 py-3 font-medium">Spoolman</th>
                            </tr>
                        </thead>
                        <tbody>
                            {units.length === 0 && (
                                <tr>
                                    <td colSpan={6} className="px-4 py-10 text-center text-slate-400">
                                        {t("warehouse.labels.empty", {
                                            defaultValue: "ไม่พบหน่วย — รับเข้าที่ POS ก่อน",
                                        })}
                                    </td>
                                </tr>
                            )}
                            {units.map((unit) => (
                                <tr key={unit.id} className="border-t border-slate-100">
                                    <td className="px-4 py-3">
                                        <input
                                            type="checkbox"
                                            checked={selectedIds.includes(unit.id)}
                                            onChange={() => toggle(unit.id)}
                                        />
                                    </td>
                                    <td className="px-4 py-3 font-semibold">{unit.unit_code}</td>
                                    <td className="px-4 py-3">
                                        <div>{unit.sku}</div>
                                        <div className="text-xs text-slate-400">
                                            {unit.product_title}
                                        </div>
                                    </td>
                                    <td className="px-4 py-3">{unit.warehouse_name}</td>
                                    <td className="px-4 py-3 tabular-nums">
                                        {unit.actual_weight} {unit.weight_uom}
                                    </td>
                                    <td className="px-4 py-3 text-slate-500">
                                        {unit.spoolman_spool_id || "—"}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

            <UnitLabelPrintModal
                isOpen={printOpen}
                onClose={() => setPrintOpen(false)}
                units={selectedUnits}
                title={t("warehouse.labels.printTitle", { defaultValue: "พิมพ์ป้ายหน่วย" })}
            />
        </DashboardLayout>
    );
}
