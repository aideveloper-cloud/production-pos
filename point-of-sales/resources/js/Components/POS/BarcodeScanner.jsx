import React, { useEffect, useRef, useState } from "react";
import { IconCamera, IconBarcode, IconX } from "@tabler/icons-react";

export default function BarcodeScanner({
    onScan,
    onClose,
    title = "เล็งไปที่บาร์โค้ด",
    closeLabel = "ปิด",
    hint = "หรือปิดเพื่อกรอกด้วยมือ",
}) {
    const scannerRef = useRef(null);
    const [scanning, setScanning] = useState(false);
    const [error, setError] = useState("");
    const html5QrCodeRef = useRef(null);

    useEffect(() => {
        let mounted = true;

        const start = async () => {
            try {
                const { Html5Qrcode } = await import("html5-qrcode");
                if (!mounted) return;

                const scanner = new Html5Qrcode("barcode-scanner-element");
                html5QrCodeRef.current = scanner;
                setScanning(true);
                setError("");

                await scanner.start(
                    { facingMode: "environment" },
                    { fps: 10, qrbox: { width: 250, height: 150 } },
                    (decodedText) => {
                        if (mounted) {
                            scanner.stop().catch(() => {});
                            setScanning(false);
                            onScan(decodedText);
                        }
                    },
                    () => {}
                );
            } catch (err) {
                if (mounted) {
                    setError(err?.message || "ไม่สามารถเปิดกล้องได้");
                    setScanning(false);
                }
            }
        };

        start();

        return () => {
            mounted = false;
            if (html5QrCodeRef.current) {
                html5QrCodeRef.current.stop().catch(() => {});
            }
        };
    }, [onScan]);

    return (
        <div className="fixed inset-0 z-[60] flex flex-col bg-black/90">
            <div className="flex items-center justify-between p-4 text-white">
                <span className="text-sm font-medium">
                    {scanning ? title : "กำลังเปิดกล้อง..."}
                </span>
                <button
                    type="button"
                    onClick={onClose}
                    className="rounded-lg p-2 transition-colors hover:bg-white/10"
                >
                    <IconX size={24} />
                </button>
            </div>

            <div className="flex flex-1 items-center justify-center p-8">
                <div
                    id="barcode-scanner-element"
                    className="aspect-square w-full max-w-sm overflow-hidden rounded-2xl"
                />
            </div>

            {error && (
                <div className="p-4 text-center">
                    <p className="mb-3 text-sm text-danger-400">{error}</p>
                    <button
                        type="button"
                        onClick={onClose}
                        className="rounded-xl bg-white/10 px-6 py-2.5 text-sm font-medium text-white hover:bg-white/20"
                    >
                        {closeLabel}
                    </button>
                </div>
            )}

            <div className="p-4 text-center text-xs text-white/50">{hint}</div>
        </div>
    );
}
