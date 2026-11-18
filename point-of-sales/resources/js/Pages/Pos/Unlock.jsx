import PosKeypad from "@/Components/POS/PosKeypad";
import { Head, router } from "@inertiajs/react";
import { useEffect, useState } from "react";
import { useTranslation } from "react-i18next";
import { IconScale } from "@tabler/icons-react";

// Warehouse PINs are exactly 4 digits; the 4th digit unlocks.
const PIN_LENGTH = 4;

const DOMAINS = {
    RM: { label: "RM วัตถุดิบ", tone: "bg-amber-500 text-slate-950" },
    FG: { label: "FG สินค้าสำเร็จรูป", tone: "bg-sky-500 text-white" },
};

export default function PosUnlock({ warehouses = [], domain = "RM", centralAccess = false }) {
    if (centralAccess) {
        return <PersonUnlock warehouses={warehouses} domain={domain} />;
    }
    return <WarehousePinUnlock warehouses={warehouses} domain={domain} />;
}

// K Garden central access: the person signs in with their employee code + PIN (set in ERPNext) at the
// warehouse this tablet serves; ERPNext decides whether they may use the WMS there.
const PERSON_PIN_MAX = 6;
const DEVICE_WAREHOUSE_KEY = "kg.pos.warehouse";

function PersonUnlock({ warehouses, domain }) {
    const info = DOMAINS[domain] || DOMAINS.RM;
    const [warehouseId, setWarehouseId] = useState(() => {
        try {
            const saved = Number(localStorage.getItem(DEVICE_WAREHOUSE_KEY));
            return warehouses.some((w) => w.id === saved) ? saved : warehouses[0]?.id ?? null;
        } catch {
            return warehouses[0]?.id ?? null;
        }
    });
    const [code, setCode] = useState("");
    const [pin, setPin] = useState("");
    const [error, setError] = useState("");
    const [processing, setProcessing] = useState(false);

    function submit() {
        if (processing || !code.trim() || pin.length < 4 || !warehouseId) return;
        setProcessing(true);
        setError("");
        try {
            localStorage.setItem(DEVICE_WAREHOUSE_KEY, String(warehouseId));
        } catch {}
        router.post(
            route("pos.unlock", { domain: String(domain).toLowerCase() }),
            { employee_code: code.trim(), pin, warehouse_id: warehouseId },
            {
                preserveScroll: true,
                onError: (errs) => {
                    setError(errs.pin || errs.employee_code || errs.warehouse_id || "เข้าสู่ระบบไม่สำเร็จ");
                    setPin("");
                    setProcessing(false);
                },
                onFinish: () => setProcessing(false),
            }
        );
    }

    return (
        <div className="flex min-h-[100dvh] flex-col bg-slate-900 text-white">
            <Head title={`POS ${info.label}`} />
            <div className="mx-auto flex w-full max-w-md flex-1 flex-col justify-center px-5 py-8">
                <div className="mb-6 text-center">
                    <div className={`mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-2xl ${info.tone}`}>
                        <IconScale size={28} />
                    </div>
                    <h1 className="text-2xl font-bold">POS {info.label}</h1>
                    <p className="mt-2 text-sm text-slate-300">เข้าสู่ระบบด้วยรหัสพนักงานและ PIN ของคุณ</p>
                </div>

                <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">โกดังของเครื่องนี้</p>
                <div className="mb-4 grid grid-cols-2 gap-2" role="radiogroup" aria-label="โกดัง">
                    {warehouses.map((wh) => (
                        <button
                            key={wh.id}
                            type="button"
                            role="radio"
                            aria-checked={warehouseId === wh.id}
                            onClick={() => setWarehouseId(wh.id)}
                            className={`rounded-xl px-3 py-2 text-left text-sm ${
                                warehouseId === wh.id ? "bg-emerald-500 font-semibold text-slate-950" : "bg-slate-800 text-slate-200"
                            }`}
                        >
                            {wh.name.replace(/^โกดัง\s*/, "")}
                        </button>
                    ))}
                </div>

                <label className="mb-3 block">
                    <span className="mb-1 block text-sm text-slate-300">รหัสพนักงาน</span>
                    <input
                        value={code}
                        onChange={(e) => setCode(e.target.value.toUpperCase())}
                        autoComplete="off"
                        autoCapitalize="characters"
                        className="h-12 w-full rounded-xl border-0 bg-white px-4 text-lg font-semibold text-slate-900"
                        data-testid="employee-code"
                    />
                </label>

                <div className="mb-3 flex justify-center gap-2" aria-label="PIN">
                    {Array.from({ length: PERSON_PIN_MAX }, (_, i) => i).map((i) => (
                        <span key={i} className={`h-3 w-3 rounded-full ${pin.length > i ? "bg-emerald-400" : "bg-slate-600"}`} />
                    ))}
                </div>

                {error && (
                    <p className="mb-3 text-center text-sm text-rose-300" role="alert">
                        {error}
                    </p>
                )}

                <div className="rounded-3xl bg-white p-4 text-slate-900">
                    <PosKeypad
                        value={pin}
                        onChange={(next) => {
                            setPin(next);
                            setError("");
                        }}
                        disabled={processing}
                        pinLength={PERSON_PIN_MAX}
                        onSubmit={submit}
                        submitDisabled={!code.trim() || pin.length < 4 || !warehouseId}
                        submitLabel={processing ? "กำลังตรวจสอบ…" : "เข้าสู่ระบบ"}
                    />
                </div>
                <p className="mt-4 text-center text-xs text-slate-400">สิทธิ์ใช้งานจัดการโดยผู้ดูแลระบบใน ERPNext</p>
            </div>
        </div>
    );
}

function WarehousePinUnlock({ warehouses, domain }) {
    const { t } = useTranslation();
    const info = DOMAINS[domain] || DOMAINS.RM;
    const [pin, setPin] = useState("");
    const [error, setError] = useState("");
    const [processing, setProcessing] = useState(false);

    function submitPin(nextPin) {
        if (processing || nextPin.length !== PIN_LENGTH) return;
        setProcessing(true);
        setError("");
        router.post(
            route("pos.unlock", { domain: String(domain).toLowerCase() }),
            { pin: nextPin },
            {
                preserveScroll: true,
                onError: (errs) => {
                    setError(errs.pin || t("warehouse.pos.invalidPin", { defaultValue: "รหัสไม่ถูกต้อง" }));
                    setPin("");
                    setProcessing(false);
                },
                onFinish: () => setProcessing(false),
            }
        );
    }

    function onChange(next) {
        if (processing) return;
        setPin(next);
        setError("");
        if (next.length === PIN_LENGTH) {
            submitPin(next);
        }
    }

    // Physical keyboard on PC/USB keypads: digits, Backspace, Enter.
    useEffect(() => {
        function onKey(e) {
            if (/^[0-9]$/.test(e.key)) {
                if (pin.length < PIN_LENGTH) onChange(`${pin}${e.key}`);
            } else if (e.key === "Backspace") {
                onChange(pin.slice(0, -1));
            } else if (e.key === "Enter") {
                submitPin(pin);
            }
        }
        window.addEventListener("keydown", onKey);
        return () => window.removeEventListener("keydown", onKey);
    });

    return (
        <div className="flex min-h-[100dvh] flex-col bg-slate-900 text-white">
            <Head title={t("warehouse.pos.brand")} />

            <div className="mx-auto flex w-full max-w-md flex-1 flex-col justify-center px-5 py-8">
                <div className="mb-8 text-center">
                    <div className={`mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-2xl ${info.tone}`}>
                        <IconScale size={28} />
                    </div>
                    <h1 className="text-2xl font-bold">POS {info.label}</h1>
                    <p className="mt-2 text-sm text-slate-300">
                        {t("warehouse.pos.unlockHint", {
                            defaultValue: "ใส่รหัสตัวเลขของโกดังเพื่อเข้าเครื่อง POS",
                        })}
                    </p>
                </div>

                <div className="mb-4 rounded-2xl bg-slate-800/80 p-4">
                    <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">
                        {t("warehouse.pos.warehouses", { defaultValue: "โกดัง" })}
                    </p>
                    <ul className="space-y-1.5 text-sm text-slate-200">
                        {warehouses.map((wh) => (
                            <li key={wh.id}>{wh.name.replace(/^โกดัง\s*/, "")}</li>
                        ))}
                    </ul>
                </div>

                <div className="mb-4 flex justify-center">
                    <div className="flex gap-2">
                        {Array.from({ length: PIN_LENGTH }, (_, i) => i).map((i) => (
                            <span
                                key={i}
                                className={`h-3 w-3 rounded-full ${
                                    pin.length > i ? "bg-emerald-400" : "bg-slate-600"
                                }`}
                            />
                        ))}
                    </div>
                </div>

                {error && <p className="mb-3 text-center text-sm text-rose-300">{error}</p>}

                <div className="rounded-3xl bg-white p-4 text-slate-900">
                    <PosKeypad
                        value={pin}
                        onChange={onChange}
                        disabled={processing}
                        pinLength={PIN_LENGTH}
                        onSubmit={() => submitPin(pin)}
                        submitDisabled={pin.length !== PIN_LENGTH}
                        submitLabel={t("warehouse.pos.unlockSubmit", { defaultValue: "ตกลง" })}
                    />
                </div>

                <p className="mt-4 text-center text-xs text-slate-400">
                    {t("warehouse.pos.adminHint", {
                        defaultValue: "แอดมินเข้าดูภาพรวมได้ที่ /login (รหัส 9999)",
                    })}
                </p>
            </div>
        </div>
    );
}
