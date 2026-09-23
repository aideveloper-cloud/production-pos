import WarehousePosLayout from "@/Layouts/WarehousePosLayout";
import PosKeypad from "@/Components/POS/PosKeypad";
import BarcodeScanner from "@/Components/POS/BarcodeScanner";
import UnitLabelPrintModal from "@/Components/Barcode/UnitLabelPrintModal";
import useBarcodeScanner from "@/Hooks/useBarcodeScanner";
import { findProductInList, findUnitInList, parsePosScanCode } from "@/Utils/posScanCode";
import { Head, router, useForm, usePage } from "@inertiajs/react";
import { useCallback, useEffect, useMemo, useState } from "react";
import { useTranslation } from "react-i18next";
import {
    IconHash,
    IconMinus,
    IconPlus,
    IconScan,
    IconSearch,
    IconX,
} from "@tabler/icons-react";
import toast from "react-hot-toast";

const KG_PRESETS = [1, 2, 5, 10, 20];

export default function PosIndex({
    warehouses = [],
    currentWarehouseId,
    units = [],
    products = [],
    isAdmin = false,
    presetsKg = KG_PRESETS,
    operatorName: initialOperatorName = "",
}) {
    const { t } = useTranslation();
    const flash = usePage().props.flash;
    const [mode, setMode] = useState(() => {
        try {
            return localStorage.getItem("wms-pos-mode") === "receive" ? "receive" : "cut";
        } catch {
            return "cut";
        }
    });
    const [filter, setFilter] = useState("");
    const [selectedUnitId, setSelectedUnitId] = useState(null);
    const [selectedProductId, setSelectedProductId] = useState("");
    const [overlayOpen, setOverlayOpen] = useState(false);
    const [amount, setAmount] = useState("");
    const [operatorName, setOperatorName] = useState(initialOperatorName || "");
    const [cameraOpen, setCameraOpen] = useState(false);
    const [manualOpen, setManualOpen] = useState(false);
    const [manualCode, setManualCode] = useState("");
    const [scanBusy, setScanBusy] = useState(false);
    const [labelUnit, setLabelUnit] = useState(null);

    const currentWarehouse = warehouses.find((w) => w.id === currentWarehouseId);
    const selectedUnit = useMemo(
        () => units.find((u) => u.id === selectedUnitId) || null,
        [units, selectedUnitId]
    );
    const selectedProduct = useMemo(
        () => products.find((p) => String(p.id) === String(selectedProductId)) || null,
        [products, selectedProductId]
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

    const filteredProducts = useMemo(() => {
        const q = filter.trim().toLowerCase();
        if (!q) return products;
        return products.filter(
            (p) => p.sku?.toLowerCase().includes(q) || p.title?.toLowerCase().includes(q)
        );
    }, [products, filter]);

    const cutForm = useForm({
        physical_unit_id: null,
        qty: "",
        uom: "KG",
        operator_name: initialOperatorName || "",
        notes: "",
    });

    const receiveForm = useForm({
        warehouse_id: currentWarehouseId,
        product_id: "",
        qty: "",
        uom: "KG",
        unit_code: "",
        create_unit: true,
        lot_code: "",
        operator_name: initialOperatorName || "",
        notes: "",
    });

    function switchMode(next) {
        setMode(next);
        setOverlayOpen(false);
        setSelectedUnitId(null);
        setSelectedProductId("");
        setAmount("");
        setFilter("");
        try {
            localStorage.setItem("wms-pos-mode", next);
        } catch {
            /* ignore */
        }
    }

    function switchWarehouse(id) {
        router.get(route("pos.index"), { warehouse_id: id }, { preserveState: false });
    }

    function openCut(unit) {
        setMode("cut");
        setSelectedUnitId(unit.id);
        setSelectedProductId("");
        setAmount("");
        cutForm.setData({
            physical_unit_id: unit.id,
            qty: "",
            uom: unit.weight_uom || unit.base_uom || "KG",
            operator_name: operatorName,
            notes: "",
        });
        setOverlayOpen(true);
    }

    function openReceive(product) {
        setMode("receive");
        setSelectedProductId(product.id);
        setSelectedUnitId(null);
        setAmount("");
        receiveForm.setData({
            warehouse_id: currentWarehouseId,
            product_id: product.id,
            qty: "",
            uom: product.base_uom || "KG",
            unit_code: "",
            create_unit: true,
            lot_code: "",
            operator_name: operatorName,
            notes: "",
        });
        setOverlayOpen(true);
    }

    function closeOverlay() {
        setOverlayOpen(false);
        setAmount("");
        setSelectedUnitId(null);
        setSelectedProductId("");
    }

    function setQty(next) {
        setAmount(next);
        if (mode === "cut") {
            cutForm.setData("qty", next);
        } else {
            receiveForm.setData("qty", next);
        }
    }

    function updateOperator(name) {
        setOperatorName(name);
        cutForm.setData("operator_name", name);
        receiveForm.setData("operator_name", name);
    }

    const handleScan = useCallback(
        async (raw) => {
            const code = String(raw || "").trim();
            if (!code || scanBusy) return;

            setCameraOpen(false);
            setManualOpen(false);
            setScanBusy(true);

            const parsed = parsePosScanCode(code);
            const localUnit = findUnitInList(units, parsed);
            if (localUnit && (mode === "cut" || !parsed || parsed.type !== "filament")) {
                openCut(localUnit);
                setScanBusy(false);
                return;
            }

            if (mode === "receive") {
                const localProduct = findProductInList(products, parsed, code);
                if (localProduct) {
                    openReceive(localProduct);
                    setScanBusy(false);
                    return;
                }
            }

            try {
                const csrf =
                    document.querySelector('meta[name="csrf-token"]')?.getAttribute("content") ||
                    "";
                const res = await fetch(route("pos.lookup"), {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/json",
                        Accept: "application/json",
                        "X-CSRF-TOKEN": csrf,
                        "X-Requested-With": "XMLHttpRequest",
                    },
                    credentials: "same-origin",
                    body: JSON.stringify({
                        code,
                        warehouse_id: currentWarehouseId,
                        mode,
                    }),
                });
                const data = await res.json().catch(() => ({}));
                if (res.ok && data.type === "unit" && data.unit) {
                    openCut(data.unit);
                } else if (res.ok && data.type === "product" && data.product) {
                    openReceive(data.product);
                } else {
                    toast.error(
                        data.message ||
                            t("warehouse.pos.scanNotFound", {
                                defaultValue: "ไม่พบรหัสที่สแกน",
                            })
                    );
                }
            } catch {
                toast.error(
                    t("warehouse.pos.scanFailed", { defaultValue: "สแกนไม่สำเร็จ" })
                );
            } finally {
                setScanBusy(false);
            }
        },
        // openCut/openReceive are stable enough for POS session
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [units, products, mode, currentWarehouseId, scanBusy, operatorName, t]
    );

    useBarcodeScanner(
        (code) => {
            if (!overlayOpen && !cameraOpen && !manualOpen) {
                handleScan(code);
            }
        },
        { enabled: !overlayOpen && !cameraOpen && !manualOpen, minLength: 3, maxDelay: 80 }
    );

    useEffect(() => {
        if (flash?.last_unit) {
            setLabelUnit(flash.last_unit);
        }
    }, [flash?.last_unit]);

    function confirm() {
        const name = operatorName.trim();
        if (name.length < 2) {
            toast.error(t("warehouse.pos.operatorRequired", { defaultValue: "กรุณาใส่ชื่อผู้ทำรายการ" }));
            return;
        }

        if (mode === "cut") {
            cutForm.setData({
                ...cutForm.data,
                qty: amount,
                operator_name: name,
            });
            cutForm.post(route("pos.cut"), {
                preserveScroll: true,
                onSuccess: () => closeOverlay(),
            });
            return;
        }

        receiveForm.setData({
            ...receiveForm.data,
            qty: amount,
            operator_name: name,
        });
        receiveForm.post(route("pos.receive"), {
            preserveScroll: true,
            onSuccess: () => closeOverlay(),
        });
    }

    const busy = cutForm.processing || receiveForm.processing;
    const canConfirm =
        !busy &&
        Number(amount) > 0 &&
        operatorName.trim().length >= 2 &&
        (mode === "cut" ? !!selectedUnit : !!selectedProduct);
    const error =
        mode === "cut"
            ? cutForm.errors.qty ||
              cutForm.errors.physical_unit_id ||
              cutForm.errors.operator_name
            : receiveForm.errors.qty || receiveForm.errors.operator_name;

    return (
        <WarehousePosLayout warehouseName={currentWarehouse?.name} showLock>
            <Head title={t("warehouse.pos.brand")} />

            <div className="flex h-full flex-col">
                <div className="flex shrink-0 flex-wrap items-center gap-2 border-b border-slate-200 bg-white px-3 py-2">
                    <div className="flex rounded-2xl bg-slate-100 p-1">
                        <button
                            type="button"
                            onClick={() => switchMode("cut")}
                            className={`inline-flex items-center gap-1 rounded-xl px-4 py-2.5 text-sm font-semibold ${
                                mode === "cut" ? "bg-rose-600 text-white shadow" : "text-slate-600"
                            }`}
                        >
                            <IconMinus size={16} />
                            {t("warehouse.floor.cut")}
                        </button>
                        <button
                            type="button"
                            onClick={() => switchMode("receive")}
                            className={`inline-flex items-center gap-1 rounded-xl px-4 py-2.5 text-sm font-semibold ${
                                mode === "receive" ? "bg-emerald-600 text-white shadow" : "text-slate-600"
                            }`}
                        >
                            <IconPlus size={16} />
                            {t("warehouse.floor.receive")}
                        </button>
                    </div>

                    <button
                        type="button"
                        onClick={() => setCameraOpen(true)}
                        className="inline-flex items-center gap-1 rounded-xl bg-slate-900 px-3 py-2.5 text-sm font-semibold text-white"
                    >
                        <IconScan size={16} />
                        {t("warehouse.pos.scan", { defaultValue: "สแกน" })}
                    </button>
                    <button
                        type="button"
                        onClick={() => {
                            setManualCode("");
                            setManualOpen(true);
                        }}
                        className="inline-flex items-center gap-1 rounded-xl bg-slate-100 px-3 py-2.5 text-sm font-semibold text-slate-700"
                    >
                        <IconHash size={16} />
                        {t("warehouse.pos.manualId", { defaultValue: "รหัส" })}
                    </button>

                    <div className="ml-auto flex flex-wrap gap-1.5">
                        {warehouses.map((wh) => (
                            <button
                                key={wh.id}
                                type="button"
                                onClick={() => switchWarehouse(wh.id)}
                                disabled={!isAdmin && warehouses.length === 1}
                                className={`rounded-xl px-3 py-2 text-xs font-semibold sm:text-sm ${
                                    wh.id === currentWarehouseId
                                        ? "bg-slate-900 text-white"
                                        : "bg-slate-100 text-slate-700"
                                }`}
                            >
                                {wh.name.replace(/^โกดัง\s*/, "")}
                            </button>
                        ))}
                    </div>
                </div>

                {flash?.success && (
                    <div className="flex flex-wrap items-center justify-center gap-3 bg-emerald-50 px-4 py-2 text-center text-sm font-medium text-emerald-800">
                        <span>{flash.success}</span>
                        {flash.last_unit && (
                            <button
                                type="button"
                                onClick={() => setLabelUnit(flash.last_unit)}
                                className="rounded-lg bg-emerald-700 px-3 py-1 text-xs font-semibold text-white"
                            >
                                {t("warehouse.pos.printLabel", { defaultValue: "พิมพ์ป้าย" })}
                            </button>
                        )}
                    </div>
                )}

                <div className="border-b border-slate-200 bg-white px-3 py-2">
                    <div className="relative">
                        <IconSearch
                            size={18}
                            className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400"
                        />
                        <input
                            value={filter}
                            onChange={(e) => setFilter(e.target.value)}
                            placeholder={
                                mode === "cut"
                                    ? t("warehouse.floor.searchPlaceholder")
                                    : t("warehouse.floor.selectSku")
                            }
                            className="w-full rounded-2xl border-slate-200 bg-slate-50 py-3 pl-10 pr-3 text-base"
                            autoComplete="off"
                        />
                    </div>
                </div>

                <ul className="min-h-0 flex-1 divide-y divide-slate-100 overflow-y-auto bg-white">
                    {mode === "cut" && filteredUnits.length === 0 && (
                        <li className="px-4 py-16 text-center text-slate-400">
                            {t("warehouse.floor.emptyUnits")}
                        </li>
                    )}
                    {mode === "cut" &&
                        filteredUnits.map((unit) => (
                            <li key={unit.id}>
                                <button
                                    type="button"
                                    onClick={() => openCut(unit)}
                                    className="flex w-full items-center justify-between gap-3 px-4 py-4 text-left active:bg-slate-50"
                                >
                                    <div className="min-w-0">
                                        <p className="truncate text-lg font-semibold">{unit.unit_code}</p>
                                        <p className="truncate text-sm text-slate-500">
                                            {unit.sku} — {unit.product_title}
                                        </p>
                                    </div>
                                    <div className="shrink-0 text-right">
                                        <p className="text-xl font-bold tabular-nums">
                                            {unit.actual_weight ?? "—"}
                                        </p>
                                        <p className="text-xs text-slate-400">{unit.weight_uom || "KG"}</p>
                                        {unit.weight_kg != null && (
                                            <p className="text-[11px] text-slate-400">
                                                {unit.weight_kg} กก.
                                            </p>
                                        )}
                                    </div>
                                </button>
                            </li>
                        ))}

                    {mode === "receive" &&
                        filteredProducts.map((p) => (
                            <li key={p.id}>
                                <button
                                    type="button"
                                    onClick={() => openReceive(p)}
                                    className="flex w-full items-center justify-between gap-3 px-4 py-4 text-left active:bg-slate-50"
                                >
                                    <div className="min-w-0">
                                        <p className="truncate text-lg font-semibold">{p.sku}</p>
                                        <p className="truncate text-sm text-slate-500">{p.title}</p>
                                    </div>
                                    <p className="text-xs font-medium text-slate-400">{p.base_uom}</p>
                                </button>
                            </li>
                        ))}
                </ul>
            </div>

            {overlayOpen && (
                <div className="fixed inset-0 z-50 flex items-end justify-center bg-black/45 p-0 sm:items-center sm:p-4">
                    <div className="flex max-h-[95dvh] w-full max-w-md flex-col overflow-y-auto rounded-t-3xl bg-white p-5 shadow-2xl sm:rounded-3xl">
                        <div className="mb-3 flex items-start justify-between gap-3">
                            <div>
                                <p className="text-xs font-semibold uppercase tracking-wide text-slate-400">
                                    {mode === "cut" ? t("warehouse.floor.cut") : t("warehouse.floor.receive")}
                                </p>
                                <p className="text-lg font-bold">
                                    {mode === "cut"
                                        ? selectedUnit?.unit_code
                                        : `${selectedProduct?.sku} — ${selectedProduct?.title}`}
                                </p>
                                {mode === "cut" && (
                                    <p className="text-sm text-slate-500">
                                        {t("warehouse.floor.remaining")}: {selectedUnit?.actual_weight}{" "}
                                        {selectedUnit?.weight_uom}
                                        {selectedUnit?.weight_kg != null
                                            ? ` (${selectedUnit.weight_kg} กก.)`
                                            : ""}
                                    </p>
                                )}
                            </div>
                            <button
                                type="button"
                                onClick={closeOverlay}
                                className="flex h-10 w-10 items-center justify-center rounded-full bg-slate-100"
                            >
                                <IconX size={18} />
                            </button>
                        </div>

                        <label className="mb-3 block text-sm">
                            <span className="mb-1 block font-medium text-slate-600">
                                {t("warehouse.pos.operatorName")}
                            </span>
                            <input
                                value={operatorName}
                                onChange={(e) => updateOperator(e.target.value)}
                                placeholder={t("warehouse.pos.operatorPlaceholder")}
                                className="w-full rounded-xl border-slate-200 text-base"
                                autoComplete="name"
                            />
                        </label>

                        <div className="mb-4 flex items-end justify-center gap-2 py-2">
                            <span className="text-5xl font-bold tabular-nums">{amount || "0"}</span>
                            <span className="pb-2 text-lg text-slate-500">
                                {mode === "cut"
                                    ? selectedUnit?.weight_uom || "KG"
                                    : selectedProduct?.base_uom || "KG"}
                            </span>
                        </div>

                        <div className="mb-3 flex flex-wrap justify-center gap-2">
                            {presetsKg.map((p) => (
                                <button
                                    key={p}
                                    type="button"
                                    disabled={busy}
                                    onClick={() => setQty(String(p))}
                                    className="rounded-xl bg-slate-100 px-3 py-2 text-sm font-semibold"
                                >
                                    {p}
                                </button>
                            ))}
                        </div>

                        <PosKeypad value={amount} onChange={setQty} disabled={busy} />

                        {error && <p className="mt-3 text-center text-sm text-rose-600">{error}</p>}

                        <button
                            type="button"
                            disabled={!canConfirm}
                            onClick={confirm}
                            className={`mt-4 w-full rounded-2xl py-4 text-base font-bold text-white disabled:opacity-40 ${
                                mode === "cut" ? "bg-rose-600" : "bg-emerald-600"
                            }`}
                        >
                            {mode === "cut"
                                ? t("warehouse.floor.confirmCut")
                                : t("warehouse.floor.confirmReceive")}
                        </button>
                    </div>
                </div>
            )}

            {cameraOpen && (
                <BarcodeScanner
                    onScan={handleScan}
                    onClose={() => setCameraOpen(false)}
                    title={t("warehouse.pos.scanAim", { defaultValue: "เล็งไปที่บาร์โค้ด / QR" })}
                    closeLabel={t("common.buttons.close", { defaultValue: "ปิด" })}
                />
            )}

            {manualOpen && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
                    <div className="w-full max-w-sm rounded-2xl bg-white p-5 shadow-xl">
                        <h3 className="mb-3 text-lg font-semibold">
                            {t("warehouse.pos.manualId", { defaultValue: "ใส่รหัส" })}
                        </h3>
                        <input
                            autoFocus
                            value={manualCode}
                            onChange={(e) => setManualCode(e.target.value)}
                            onKeyDown={(e) => {
                                if (e.key === "Enter" && manualCode.trim()) {
                                    handleScan(manualCode);
                                }
                            }}
                            placeholder="SP-000123 / SKU / WEB+SPOOLMAN:S-…"
                            className="mb-4 w-full rounded-xl border-slate-200 text-base"
                        />
                        <div className="flex gap-2">
                            <button
                                type="button"
                                onClick={() => setManualOpen(false)}
                                className="flex-1 rounded-xl bg-slate-100 py-3 text-sm font-semibold"
                            >
                                {t("common.buttons.cancel", { defaultValue: "ยกเลิก" })}
                            </button>
                            <button
                                type="button"
                                disabled={!manualCode.trim() || scanBusy}
                                onClick={() => handleScan(manualCode)}
                                className="flex-1 rounded-xl bg-slate-900 py-3 text-sm font-semibold text-white disabled:opacity-40"
                            >
                                OK
                            </button>
                        </div>
                    </div>
                </div>
            )}

            <UnitLabelPrintModal
                isOpen={!!labelUnit}
                onClose={() => setLabelUnit(null)}
                units={labelUnit ? [labelUnit] : []}
                title={t("warehouse.labels.printTitle", { defaultValue: "พิมพ์ป้ายหน่วย" })}
            />
        </WarehousePosLayout>
    );
}
