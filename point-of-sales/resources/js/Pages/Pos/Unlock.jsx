import PosKeypad from "@/Components/POS/PosKeypad";
import { Head, router } from "@inertiajs/react";
import { useState } from "react";
import { useTranslation } from "react-i18next";
import { IconScale } from "@tabler/icons-react";

export default function PosUnlock({ warehouses = [] }) {
    const { t } = useTranslation();
    const [pin, setPin] = useState("");
    const [error, setError] = useState("");
    const [processing, setProcessing] = useState(false);

    function submitPin(nextPin) {
        setProcessing(true);
        setError("");
        router.post(
            route("pos.unlock"),
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
        if (next.length === 4) {
            submitPin(next);
        }
    }

    return (
        <div className="flex min-h-[100dvh] flex-col bg-slate-900 text-white">
            <Head title={t("warehouse.pos.brand")} />

            <div className="mx-auto flex w-full max-w-md flex-1 flex-col justify-center px-5 py-8">
                <div className="mb-8 text-center">
                    <div className="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-white text-slate-900">
                        <IconScale size={28} />
                    </div>
                    <h1 className="text-2xl font-bold">{t("warehouse.pos.brand")}</h1>
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
                        {[0, 1, 2, 3].map((i) => (
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
                    <PosKeypad value={pin} onChange={onChange} disabled={processing} />
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
