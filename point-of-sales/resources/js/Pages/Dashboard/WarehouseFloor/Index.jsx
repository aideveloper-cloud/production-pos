import DashboardLayout from "@/Layouts/DashboardLayout";
import { Head, router, useForm, usePage } from "@inertiajs/react";
import { useMemo, useState } from "react";
import { useTranslation } from "react-i18next";
import { IconScale, IconPlus, IconMinus } from "@tabler/icons-react";

const KG_PRESETS = [0.1, 0.25, 0.5, 1, 5, 10];

export default function WarehouseFloorIndex({
    warehouses = [],
    currentWarehouseId,
    units = [],
    products = [],
    isAdmin = false,
    presetsKg = KG_PRESETS,
    operatorName = "",
}) {
    const { t } = useTranslation();
    const flash = usePage().props.flash;
    const [mode, setMode] = useState("cut"); // cut | receive
    const [selectedUnitId, setSelectedUnitId] = useState(null);
    const [filter, setFilter] = useState("");

    const selectedUnit = useMemo(
        () => units.find((u) => u.id === selectedUnitId) || null,
        [units, selectedUnitId]
    );

    const filteredUnits = useMemo(() => {
        const q = filter.trim().toLowerCase();
        if (!q) return units;
        return units.filter(
            (u) =>
                u.unit_code?.toLowerCase().includes(q) ||
                u.sku?.toLowerCase().includes(q) ||
                u.product_title?.toLowerCase().includes(q)
        );
    }, [units, filter]);

    const cutForm = useForm({
        physical_unit_id: null,
        qty: "",
        uom: "KG",
        operator_name: operatorName,
        notes: "",
        warehouse_id: currentWarehouseId,
    });

    const receiveForm = useForm({
        warehouse_id: currentWarehouseId,
        product_id: "",
        qty: "",
        uom: "KG",
        unit_code: "",
        create_unit: true,
        lot_code: "",
        operator_name: operatorName,
        notes: "",
    });

    function switchWarehouse(id) {
        router.get(
            route("warehouse-floor.index"),
            { warehouse_id: id },
            { preserveState: false }
        );
    }

    function selectUnit(unit) {
        setSelectedUnitId(unit.id);
        setMode("cut");
        cutForm.setData({
            physical_unit_id: unit.id,
            qty: "",
            uom: unit.weight_uom || unit.base_uom || "KG",
            operator_name: cutForm.data.operator_name || operatorName,
            notes: "",
            warehouse_id: currentWarehouseId,
        });
    }

    function submitCut(e) {
        e.preventDefault();
        cutForm.post(route("warehouse-floor.cut"), {
            preserveScroll: true,
            onSuccess: () => {
                cutForm.setData("qty", "");
                setSelectedUnitId(null);
            },
        });
    }

    function submitReceive(e) {
        e.preventDefault();
        receiveForm.post(route("warehouse-floor.receive"), {
            preserveScroll: true,
            onSuccess: () => {
                receiveForm.setData({
                    ...receiveForm.data,
                    qty: "",
                    unit_code: "",
                    lot_code: "",
                    notes: "",
                });
            },
        });
    }

    return (
        <DashboardLayout>
            <Head title={t("warehouse.floor.title")} />

            <div className="space-y-4">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="flex items-center gap-2 text-2xl font-semibold text-slate-900">
                            <IconScale size={26} /> {t("warehouse.floor.title")}
                        </h1>
                        <p className="text-sm text-slate-500">{t("warehouse.floor.subtitle")}</p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {warehouses.map((wh) => (
                            <button
                                key={wh.id}
                                type="button"
                                onClick={() => switchWarehouse(wh.id)}
                                disabled={!isAdmin && warehouses.length === 1}
                                className={`rounded-xl px-3 py-2 text-sm font-medium ${
                                    wh.id === currentWarehouseId
                                        ? "bg-slate-900 text-white"
                                        : "bg-white text-slate-700 ring-1 ring-slate-200"
                                }`}
                            >
                                {wh.name.replace(/^โกดัง\s*/, "")}
                            </button>
                        ))}
                    </div>
                </div>

                {flash?.success && (
                    <div className="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                        {flash.success}
                    </div>
                )}

                <div className="flex gap-2">
                    <button
                        type="button"
                        onClick={() => setMode("cut")}
                        className={`inline-flex items-center gap-1 rounded-xl px-4 py-2 text-sm font-medium ${
                            mode === "cut"
                                ? "bg-rose-600 text-white"
                                : "bg-white ring-1 ring-slate-200"
                        }`}
                    >
                        <IconMinus size={16} /> {t("warehouse.floor.cut")}
                    </button>
                    <button
                        type="button"
                        onClick={() => {
                            setMode("receive");
                            setSelectedUnitId(null);
                            receiveForm.setData("warehouse_id", currentWarehouseId);
                        }}
                        className={`inline-flex items-center gap-1 rounded-xl px-4 py-2 text-sm font-medium ${
                            mode === "receive"
                                ? "bg-emerald-600 text-white"
                                : "bg-white ring-1 ring-slate-200"
                        }`}
                    >
                        <IconPlus size={16} /> {t("warehouse.floor.receive")}
                    </button>
                </div>

                <div className="grid gap-4 lg:grid-cols-5">
                    <div className="lg:col-span-3 rounded-2xl border border-slate-200 bg-white shadow-sm">
                        <div className="border-b border-slate-100 p-4">
                            <input
                                value={filter}
                                onChange={(e) => setFilter(e.target.value)}
                                placeholder={t("warehouse.floor.searchPlaceholder")}
                                className="w-full rounded-xl border-slate-200 text-base"
                            />
                        </div>
                        <ul className="max-h-[70vh] divide-y divide-slate-100 overflow-y-auto">
                            {filteredUnits.length === 0 && (
                                <li className="px-4 py-10 text-center text-slate-400">
                                    {t("warehouse.floor.emptyUnits")}
                                </li>
                            )}
                            {filteredUnits.map((unit) => (
                                <li key={unit.id}>
                                    <button
                                        type="button"
                                        onClick={() => selectUnit(unit)}
                                        className={`flex w-full items-center justify-between px-4 py-3 text-left hover:bg-slate-50 ${
                                            selectedUnitId === unit.id ? "bg-slate-100" : ""
                                        }`}
                                    >
                                        <div>
                                            <p className="font-semibold text-slate-900">
                                                {unit.unit_code}
                                            </p>
                                            <p className="text-sm text-slate-500">
                                                {unit.sku} — {unit.product_title}
                                            </p>
                                        </div>
                                        <div className="text-right">
                                            <p className="text-lg font-semibold tabular-nums">
                                                {unit.actual_weight ?? "—"}
                                            </p>
                                            <p className="text-xs text-slate-400">
                                                {unit.weight_uom || "KG"}
                                            </p>
                                        </div>
                                    </button>
                                </li>
                            ))}
                        </ul>
                    </div>

                    <div className="lg:col-span-2 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        {mode === "cut" ? (
                            <form onSubmit={submitCut} className="space-y-4">
                                <h2 className="text-lg font-semibold">{t("warehouse.floor.cut")}</h2>
                                {!selectedUnit ? (
                                    <p className="text-sm text-slate-500">
                                        {t("warehouse.floor.selectUnit")}
                                    </p>
                                ) : (
                                    <>
                                        <div className="rounded-xl bg-slate-50 p-3 text-sm">
                                            <p className="font-medium">{selectedUnit.unit_code}</p>
                                            <p className="text-slate-500">
                                                {t("warehouse.floor.remaining")}:{" "}
                                                {selectedUnit.actual_weight}{" "}
                                                {selectedUnit.weight_uom}
                                            </p>
                                        </div>
                                        <label className="block text-sm">
                                            <span className="mb-1 block text-slate-600">
                                                {t("warehouse.floor.amount")}
                                            </span>
                                            <input
                                                type="number"
                                                step="any"
                                                min="0"
                                                required
                                                value={cutForm.data.qty}
                                                onChange={(e) =>
                                                    cutForm.setData("qty", e.target.value)
                                                }
                                                className="w-full rounded-xl border-slate-200 text-xl"
                                            />
                                        </label>
                                        <div className="flex flex-wrap gap-2">
                                            {presetsKg.map((p) => (
                                                <button
                                                    key={p}
                                                    type="button"
                                                    onClick={() => cutForm.setData("qty", String(p))}
                                                    className="rounded-lg bg-slate-100 px-3 py-1.5 text-sm font-medium"
                                                >
                                                    {p}
                                                </button>
                                            ))}
                                        </div>
                                        <label className="block text-sm">
                                            <span className="mb-1 block text-slate-600">
                                                {t("warehouse.pos.operatorName")}
                                            </span>
                                            <input
                                                required
                                                minLength={2}
                                                value={cutForm.data.operator_name}
                                                onChange={(e) =>
                                                    cutForm.setData("operator_name", e.target.value)
                                                }
                                                placeholder={t("warehouse.pos.operatorPlaceholder")}
                                                className="w-full rounded-xl border-slate-200"
                                            />
                                        </label>
                                        {cutForm.errors.qty && (
                                            <p className="text-sm text-rose-600">
                                                {cutForm.errors.qty}
                                            </p>
                                        )}
                                        {cutForm.errors.operator_name && (
                                            <p className="text-sm text-rose-600">
                                                {cutForm.errors.operator_name}
                                            </p>
                                        )}
                                        <button
                                            type="submit"
                                            disabled={cutForm.processing}
                                            className="w-full rounded-xl bg-rose-600 py-3 text-sm font-semibold text-white hover:bg-rose-500 disabled:opacity-50"
                                        >
                                            {t("warehouse.floor.confirmCut")}
                                        </button>
                                    </>
                                )}
                            </form>
                        ) : (
                            <form onSubmit={submitReceive} className="space-y-4">
                                <h2 className="text-lg font-semibold">
                                    {t("warehouse.floor.receive")}
                                </h2>
                                <label className="block text-sm">
                                    <span className="mb-1 block text-slate-600">
                                        {t("warehouse.floor.product")}
                                    </span>
                                    <select
                                        required
                                        value={receiveForm.data.product_id}
                                        onChange={(e) =>
                                            receiveForm.setData("product_id", e.target.value)
                                        }
                                        className="w-full rounded-xl border-slate-200"
                                    >
                                        <option value="">{t("warehouse.floor.selectSku")}</option>
                                        {products.map((p) => (
                                            <option key={p.id} value={p.id}>
                                                {p.sku} — {p.title}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                                <label className="block text-sm">
                                    <span className="mb-1 block text-slate-600">
                                        {t("warehouse.floor.qtyWeight")}
                                    </span>
                                    <input
                                        type="number"
                                        step="any"
                                        min="0"
                                        required
                                        value={receiveForm.data.qty}
                                        onChange={(e) =>
                                            receiveForm.setData("qty", e.target.value)
                                        }
                                        className="w-full rounded-xl border-slate-200 text-xl"
                                    />
                                </label>
                                <div className="flex flex-wrap gap-2">
                                    {presetsKg.map((p) => (
                                        <button
                                            key={p}
                                            type="button"
                                            onClick={() =>
                                                receiveForm.setData("qty", String(p))
                                            }
                                            className="rounded-lg bg-slate-100 px-3 py-1.5 text-sm font-medium"
                                        >
                                            {p}
                                        </button>
                                    ))}
                                </div>
                                <label className="block text-sm">
                                    <span className="mb-1 block text-slate-600">
                                        {t("warehouse.floor.unitCodeOptional")}
                                    </span>
                                    <input
                                        value={receiveForm.data.unit_code}
                                        onChange={(e) =>
                                            receiveForm.setData("unit_code", e.target.value)
                                        }
                                        placeholder="SP-000123"
                                        className="w-full rounded-xl border-slate-200"
                                    />
                                </label>
                                <label className="block text-sm">
                                    <span className="mb-1 block text-slate-600">
                                        {t("warehouse.floor.lotOptional")}
                                    </span>
                                    <input
                                        value={receiveForm.data.lot_code}
                                        onChange={(e) =>
                                            receiveForm.setData("lot_code", e.target.value)
                                        }
                                        className="w-full rounded-xl border-slate-200"
                                    />
                                </label>
                                <label className="block text-sm">
                                    <span className="mb-1 block text-slate-600">
                                        {t("warehouse.pos.operatorName")}
                                    </span>
                                    <input
                                        required
                                        minLength={2}
                                        value={receiveForm.data.operator_name}
                                        onChange={(e) =>
                                            receiveForm.setData("operator_name", e.target.value)
                                        }
                                        placeholder={t("warehouse.pos.operatorPlaceholder")}
                                        className="w-full rounded-xl border-slate-200"
                                    />
                                </label>
                                {receiveForm.errors.qty && (
                                    <p className="text-sm text-rose-600">{receiveForm.errors.qty}</p>
                                )}
                                {receiveForm.errors.operator_name && (
                                    <p className="text-sm text-rose-600">
                                        {receiveForm.errors.operator_name}
                                    </p>
                                )}
                                <button
                                    type="submit"
                                    disabled={receiveForm.processing}
                                    className="w-full rounded-xl bg-emerald-600 py-3 text-sm font-semibold text-white hover:bg-emerald-500 disabled:opacity-50"
                                >
                                    {t("warehouse.floor.confirmReceive")}
                                </button>
                            </form>
                        )}
                    </div>
                </div>
            </div>
        </DashboardLayout>
    );
}
