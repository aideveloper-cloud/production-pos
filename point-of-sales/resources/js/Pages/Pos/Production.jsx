import WarehousePosLayout from "@/Layouts/WarehousePosLayout";
import PosKeypad from "@/Components/POS/PosKeypad";
import { Head, router } from "@inertiajs/react";
import { useMemo, useState } from "react";
import {
    IconAlertTriangle,
    IconPlayerPlay,
    IconPlayerStop,
    IconRefresh,
    IconSearch,
    IconX,
} from "@tabler/icons-react";
import toast from "react-hot-toast";

// Production POS: the shop floor works ERPNext Work Orders. Good quantity reported here is
// received straight into this warehouse as finished goods (FG); ERPNext consumes the materials
// from WIP when the stock bridge books it.

function fmt(n) {
    const v = Number(n || 0);
    return Number.isInteger(v) ? v.toLocaleString("th-TH") : v.toLocaleString("th-TH", { maximumFractionDigits: 4 });
}

function newKey() {
    if (window.crypto?.randomUUID) return window.crypto.randomUUID();
    return `${Date.now()}-${Math.random().toString(36).slice(2)}`;
}

async function postJson(url, body) {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute("content") || "";
    const res = await fetch(url, {
        method: "POST",
        headers: {
            "Content-Type": "application/json",
            Accept: "application/json",
            "X-CSRF-TOKEN": csrf,
            "X-Requested-With": "XMLHttpRequest",
        },
        credentials: "same-origin",
        body: JSON.stringify(body),
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) {
        const first = data.errors ? Object.values(data.errors).flat()[0] : null;
        throw new Error(first || data.message || `ผิดพลาด (${res.status})`);
    }
    return data;
}

export default function PosProduction({
    warehouses = [],
    currentWarehouse,
    workOrders = [],
    loadError = null,
    recent = [],
    operatorName: initialOperatorName = "",
    operatorLocked = false,
}) {
    const [filter, setFilter] = useState("");
    const [selected, setSelected] = useState(null);
    const [operatorName, setOperatorName] = useState(initialOperatorName || "");
    const [field, setField] = useState("good");
    const [good, setGood] = useState("");
    const [scrap, setScrap] = useState("");
    const [scrapReason, setScrapReason] = useState("");
    const [busy, setBusy] = useState(false);
    // Kept while one report is being sent, so a retry after a dropped connection is not counted twice.
    const [clientKey, setClientKey] = useState(newKey);

    const shown = useMemo(() => {
        const q = filter.trim().toLowerCase();
        if (!q) return workOrders;
        return workOrders.filter((wo) =>
            [wo.name, wo.production_item, wo.item_name].some((v) => String(v || "").toLowerCase().includes(q))
        );
    }, [workOrders, filter]);

    const wo = selected ? workOrders.find((w) => w.name === selected) : null;

    function reload() {
        router.reload({ only: ["workOrders", "recent", "loadError"], preserveScroll: true });
    }

    function open(name) {
        setSelected(name);
        setField("good");
        setGood("");
        setScrap("");
        setScrapReason("");
        setClientKey(newKey());
    }

    function base() {
        return { warehouse_id: currentWarehouse.id, operator_name: operatorName };
    }

    async function run(fn, okMessage) {
        if (busy) return;
        setBusy(true);
        try {
            await fn();
            toast.success(okMessage);
            reload();
            return true;
        } catch (e) {
            toast.error(e.message);
            return false;
        } finally {
            setBusy(false);
        }
    }

    const start = () =>
        run(() => postJson(route("pos.production.start", { workOrder: wo.name }), base()), `เริ่มผลิต ${wo.name}`);
    const stop = () =>
        run(() => postJson(route("pos.production.stop", { workOrder: wo.name }), base()), `หยุดผลิต ${wo.name}`);

    async function report() {
        const ok = await run(
            () =>
                postJson(route("pos.production.output", { workOrder: wo.name }), {
                    ...base(),
                    good_qty: good || 0,
                    scrap_qty: scrap || 0,
                    scrap_reason: scrapReason,
                    client_key: clientKey,
                }),
            `บันทึกยอดผลิต ${wo.name} แล้ว ของดีเข้า ${currentWarehouse.name}`
        );
        if (ok) {
            setSelected(null);
            setClientKey(newKey());
        }
    }

    const goodNum = Number(good || 0);
    const scrapNum = Number(scrap || 0);
    const canReport =
        wo &&
        wo.product_id &&
        (operatorLocked || operatorName.trim().length >= 2) &&
        (goodNum > 0 || scrapNum > 0) &&
        goodNum <= Number(wo.remaining_qty) &&
        (scrapNum === 0 || scrapReason.trim() !== "");

    return (
        <WarehousePosLayout warehouseName={currentWarehouse?.name} showLock domain="PRODUCTION">
            <Head title="POS ผลิต" />
            <div className="flex min-h-0 flex-1 flex-col gap-3 p-3 sm:p-4">
                <div className="flex flex-wrap items-center gap-2">
                    <div className="relative min-w-[12rem] flex-1">
                        <IconSearch size={18} className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
                        <input
                            value={filter}
                            onChange={(e) => setFilter(e.target.value)}
                            placeholder="ค้นหาใบสั่งผลิต / รหัสสินค้า"
                            className="w-full rounded-xl border-slate-200 pl-10 text-base"
                        />
                    </div>
                    {warehouses.length > 1 && (
                        <select
                            value={currentWarehouse.id}
                            onChange={(e) => router.get(route("pos.show", { domain: "production" }), { warehouse_id: e.target.value })}
                            className="rounded-xl border-slate-200 text-base"
                            aria-label="คลังรับสินค้าสำเร็จรูป"
                        >
                            {warehouses.map((w) => (
                                <option key={w.id} value={w.id}>
                                    {w.name}
                                </option>
                            ))}
                        </select>
                    )}
                    <button
                        type="button"
                        onClick={reload}
                        className="flex h-11 items-center gap-1 rounded-xl bg-white px-3 text-sm font-medium shadow-sm"
                    >
                        <IconRefresh size={18} /> รีเฟรช
                    </button>
                </div>

                {loadError && (
                    <div className="flex items-center gap-2 rounded-xl bg-rose-50 p-3 text-rose-800">
                        <IconAlertTriangle size={20} /> {loadError}
                    </div>
                )}

                <div className="grid min-h-0 flex-1 auto-rows-min gap-3 overflow-y-auto sm:grid-cols-2 xl:grid-cols-3">
                    {shown.length === 0 && !loadError && (
                        <p className="col-span-full py-12 text-center text-slate-500">ไม่มีใบสั่งผลิตที่เปิดอยู่</p>
                    )}
                    {shown.map((w) => {
                        const pct = Number(w.qty) > 0 ? Math.min(100, ((Number(w.qty) - Number(w.remaining_qty)) / Number(w.qty)) * 100) : 0;
                        return (
                            <button
                                key={w.name}
                                type="button"
                                onClick={() => open(w.name)}
                                className="rounded-2xl bg-white p-4 text-left shadow-sm ring-1 ring-slate-200 active:scale-[0.99]"
                            >
                                <div className="mb-1 flex items-start justify-between gap-2">
                                    <span className="text-xs font-semibold text-slate-500">{w.name}</span>
                                    {w.running ? (
                                        <span className="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-800">
                                            กำลังผลิต
                                        </span>
                                    ) : (
                                        <span className="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600">{w.status === "In Process" ? "ทำไปบางส่วน" : "ยังไม่เริ่ม"}</span>
                                    )}
                                </div>
                                <p className="text-lg font-bold leading-snug">{w.item_name}</p>
                                <p className="mb-3 text-xs text-slate-500">{w.production_item}</p>
                                <div className="mb-1 h-2 overflow-hidden rounded-full bg-slate-100">
                                    <div className="h-full bg-emerald-500" style={{ width: `${pct}%` }} />
                                </div>
                                <p className="text-sm">
                                    เหลือ <b className="tabular-nums">{fmt(w.remaining_qty)}</b> / {fmt(w.qty)} {w.stock_uom}
                                </p>
                                {w.running && (
                                    <p className="mt-1 truncate text-xs text-emerald-700">โดย {w.running.operator_name}</p>
                                )}
                                {!w.product_id && (
                                    <p className="mt-2 text-xs font-medium text-amber-700">ยังไม่มีสินค้านี้ใน WMS — บันทึกยอดไม่ได้</p>
                                )}
                            </button>
                        );
                    })}
                </div>

                {recent.length > 0 && (
                    <div className="rounded-2xl bg-white p-3 shadow-sm">
                        <p className="mb-2 text-sm font-semibold text-slate-600">บันทึกล่าสุดที่คลังนี้</p>
                        <ul className="divide-y divide-slate-100 text-sm">
                            {recent.map((r) => (
                                <li key={r.id} className="flex justify-between gap-2 py-1.5">
                                    <span className="truncate">
                                        {r.work_order} · {r.item_code}
                                    </span>
                                    <span className="shrink-0 tabular-nums">
                                        ดี {fmt(r.good_qty)}
                                        {Number(r.scrap_qty) > 0 && <span className="text-rose-600"> · เสีย {fmt(r.scrap_qty)}</span>} {r.uom}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}
            </div>

            {wo && (
                <div className="fixed inset-0 z-40 flex items-end justify-center bg-slate-900/40 sm:items-center" role="dialog" aria-modal="true">
                    <div className="max-h-[100dvh] w-full max-w-md overflow-y-auto rounded-t-3xl bg-white p-4 sm:rounded-3xl">
                        <div className="mb-3 flex items-start justify-between gap-2">
                            <div className="min-w-0">
                                <p className="text-xs font-semibold text-slate-500">{wo.name}</p>
                                <p className="text-xl font-bold leading-snug">{wo.item_name}</p>
                                <p className="text-sm text-slate-500">
                                    เหลือ {fmt(wo.remaining_qty)} / {fmt(wo.qty)} {wo.stock_uom}
                                </p>
                            </div>
                            <button
                                type="button"
                                onClick={() => setSelected(null)}
                                aria-label="ปิด"
                                className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-slate-100"
                            >
                                <IconX size={18} />
                            </button>
                        </div>

                        <label className="mb-3 block text-sm">
                            <span className="mb-1 block font-medium text-slate-600">ผู้ทำรายการ</span>
                            {operatorLocked ? (
                                <p className="rounded-xl bg-slate-100 px-3 py-2 text-base font-semibold text-slate-800">{operatorName}</p>
                            ) : (
                                <input
                                    value={operatorName}
                                    onChange={(e) => setOperatorName(e.target.value)}
                                    placeholder="ชื่อผู้ทำรายการ"
                                    className="w-full rounded-xl border-slate-200 text-base"
                                    autoComplete="name"
                                />
                            )}
                        </label>

                        <div className="mb-4 grid grid-cols-2 gap-2">
                            {wo.running ? (
                                <button
                                    type="button"
                                    disabled={busy}
                                    onClick={stop}
                                    className="col-span-2 flex h-12 items-center justify-center gap-2 rounded-xl bg-slate-800 font-semibold text-white disabled:opacity-50"
                                >
                                    <IconPlayerStop size={20} /> หยุดผลิต
                                </button>
                            ) : (
                                <button
                                    type="button"
                                    disabled={busy}
                                    onClick={start}
                                    className="col-span-2 flex h-12 items-center justify-center gap-2 rounded-xl bg-emerald-600 font-semibold text-white disabled:opacity-50"
                                >
                                    <IconPlayerPlay size={20} /> เริ่มผลิต
                                </button>
                            )}
                        </div>

                        <p className="mb-2 text-sm font-semibold text-slate-600">บันทึกยอดผลิต</p>
                        {!wo.product_id ? (
                            <p className="rounded-xl bg-amber-50 p-3 text-sm text-amber-800">
                                ยังไม่มีสินค้า {wo.production_item} เป็น FG ใน WMS ให้ผู้ดูแลเพิ่มสินค้าก่อน จึงจะบันทึกยอดได้
                            </p>
                        ) : (
                            <>
                                <div className="mb-3 grid grid-cols-2 gap-2">
                                    {[
                                        ["good", "ของดี", good, "text-emerald-700"],
                                        ["scrap", "ของเสีย", scrap, "text-rose-700"],
                                    ].map(([key, label, value, tone]) => (
                                        <button
                                            key={key}
                                            type="button"
                                            onClick={() => setField(key)}
                                            className={`rounded-xl p-3 text-left ring-2 ${field === key ? "ring-emerald-500" : "ring-slate-200"}`}
                                        >
                                            <span className="block text-xs text-slate-500">{label}</span>
                                            <span className={`text-3xl font-bold tabular-nums ${tone}`}>{value || "0"}</span>
                                            <span className="ml-1 text-sm text-slate-500">{wo.stock_uom}</span>
                                        </button>
                                    ))}
                                </div>
                                {goodNum > Number(wo.remaining_qty) && (
                                    <p className="mb-2 text-sm text-rose-700">ของดีมากกว่าที่เหลือต้องผลิต ({fmt(wo.remaining_qty)})</p>
                                )}
                                {scrapNum > 0 && (
                                    <input
                                        value={scrapReason}
                                        onChange={(e) => setScrapReason(e.target.value)}
                                        placeholder="สาเหตุของเสีย"
                                        className="mb-3 w-full rounded-xl border-slate-200 text-base"
                                    />
                                )}
                                <PosKeypad
                                    value={field === "good" ? good : scrap}
                                    onChange={field === "good" ? setGood : setScrap}
                                    disabled={busy}
                                />
                                <button
                                    type="button"
                                    disabled={!canReport || busy}
                                    onClick={report}
                                    className="mt-3 h-14 w-full rounded-2xl bg-emerald-600 text-lg font-bold text-white disabled:opacity-40"
                                >
                                    {busy ? "กำลังบันทึก..." : `บันทึก · ของดีเข้า ${currentWarehouse.name}`}
                                </button>
                            </>
                        )}
                    </div>
                </div>
            )}
        </WarehousePosLayout>
    );
}
