import React, { useEffect, useState } from "react";
import { Link, useForm, usePage } from "@inertiajs/react";
import { Toaster } from "react-hot-toast";
import {
    IconArrowsMaximize,
    IconArrowsMinimize,
    IconLock,
    IconScale,
} from "@tabler/icons-react";
import { useTranslation } from "react-i18next";

export default function WarehousePosLayout({ children, warehouseName, showLock = false }) {
    const { t } = useTranslation();
    const { auth } = usePage().props;
    const [isFullscreen, setIsFullscreen] = useState(false);
    const lockForm = useForm({});

    useEffect(() => {
        const handler = () => setIsFullscreen(!!document.fullscreenElement);
        document.addEventListener("fullscreenchange", handler);
        return () => document.removeEventListener("fullscreenchange", handler);
    }, []);

    const toggleFullscreen = () => {
        if (!document.fullscreenElement) {
            document.documentElement.requestFullscreen?.().then(() => setIsFullscreen(true)).catch(() => {});
        } else {
            document.exitFullscreen?.().then(() => setIsFullscreen(false)).catch(() => {});
        }
    };

    return (
        <div className="flex h-[100dvh] flex-col bg-slate-100 text-slate-900">
            <header className="flex h-14 shrink-0 items-center justify-between gap-3 border-b border-slate-200 bg-white px-3 sm:px-4">
                <div className="flex min-w-0 items-center gap-2">
                    <div className="flex h-9 w-9 items-center justify-center rounded-xl bg-slate-900 text-white">
                        <IconScale size={18} />
                    </div>
                    <div className="min-w-0">
                        <p className="truncate text-sm font-bold leading-tight">
                            {t("warehouse.pos.brand")}
                        </p>
                        {warehouseName && (
                            <p className="truncate text-xs text-slate-500">{warehouseName}</p>
                        )}
                    </div>
                </div>

                <div className="flex items-center gap-1.5 sm:gap-2">
                    <span className="hidden max-w-[10rem] truncate text-xs text-slate-500 sm:inline">
                        {auth?.user?.name}
                    </span>
                    <button
                        type="button"
                        onClick={toggleFullscreen}
                        className="flex h-10 w-10 items-center justify-center rounded-xl bg-slate-100 text-slate-600"
                        title="Fullscreen"
                    >
                        {isFullscreen ? <IconArrowsMinimize size={18} /> : <IconArrowsMaximize size={18} />}
                    </button>
                    {showLock ? (
                        <button
                            type="button"
                            onClick={() => lockForm.post(route("pos.lock"))}
                            disabled={lockForm.processing}
                            className="flex h-10 items-center gap-1 rounded-xl bg-rose-50 px-3 text-sm font-medium text-rose-700"
                        >
                            <IconLock size={16} />
                            <span className="hidden sm:inline">
                                {t("warehouse.pos.lock", { defaultValue: "ล็อก" })}
                            </span>
                        </button>
                    ) : (
                        <Link
                            href={route("logout")}
                            method="post"
                            as="button"
                            className="flex h-10 items-center gap-1 rounded-xl bg-rose-50 px-3 text-sm font-medium text-rose-700"
                        >
                            <IconLock size={16} />
                        </Link>
                    )}
                </div>
            </header>

            <main className="min-h-0 flex-1 overflow-hidden">
                <Toaster position="top-center" toastOptions={{ duration: 2500 }} />
                {children}
            </main>
        </div>
    );
}
