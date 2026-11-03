import React, { useEffect, useRef, useState } from "react";
import JsBarcode from "jsbarcode";
import QRCode from "qrcode";

/**
 * Warehouse physical-unit label. QR payload is the unit code so the tablet can scan and cut.
 */
export default function UnitLabel({
    unit,
    size = "70x50",
    symbol = "qr",
    showWeight = true,
    showWarehouse = true,
}) {
    const barcodeRef = useRef(null);
    const [qrUrl, setQrUrl] = useState("");

    const sizes = {
        "50x30": { width: "50mm", height: "30mm", bw: 1.4, bh: 36, font: 10 },
        "70x50": { width: "70mm", height: "50mm", bw: 1.8, bh: 48, font: 12 },
        "100x50": { width: "100mm", height: "50mm", bw: 2.2, bh: 56, font: 13 },
    };
    const s = sizes[size] || sizes["70x50"];
    const code = unit?.unit_code || "";
    const showBarcode = symbol === "barcode" || symbol === "both";
    const showQr = symbol === "qr" || symbol === "both";

    useEffect(() => {
        if (!showBarcode || !barcodeRef.current || !code) return;
        try {
            JsBarcode(barcodeRef.current, code, {
                format: "CODE128",
                width: symbol === "both" ? 1.2 : s.bw,
                height: symbol === "both" ? 32 : s.bh,
                displayValue: true,
                fontSize: 11,
                margin: 2,
                background: "#ffffff",
            });
        } catch (e) {
            console.error("Unit barcode error:", e);
        }
    }, [code, s.bw, s.bh, showBarcode, symbol]);

    useEffect(() => {
        if (!showQr || !code) {
            setQrUrl("");
            return;
        }
        let cancelled = false;
        QRCode.toDataURL(code, {
            margin: 1,
            width: 220,
            errorCorrectionLevel: "M",
        })
            .then((url) => {
                if (!cancelled) setQrUrl(url);
            })
            .catch((e) => console.error("Unit QR error:", e));
        return () => {
            cancelled = true;
        };
    }, [code, showQr]);

    if (!unit) return null;

    return (
        <div
            className="unit-label flex flex-col items-center justify-center border border-slate-300 bg-white p-2"
            style={{
                width: s.width,
                height: s.height,
                pageBreakInside: "avoid",
            }}
        >
            <p
                className="mb-0.5 line-clamp-2 text-center font-semibold leading-tight text-slate-800"
                style={{ fontSize: `${s.font}px` }}
            >
                {unit.product_title || unit.sku}
            </p>
            <p className="text-[10px] font-medium text-slate-500">{unit.sku}</p>
            <div className={`flex w-full items-center justify-center gap-1 ${showBarcode && showQr ? "flex-row" : "flex-col"}`}>
                {showBarcode && (
                    <svg ref={barcodeRef} className="max-w-full" data-code={code} />
                )}
                {showQr && qrUrl && (
                    <img
                        src={qrUrl}
                        alt={code}
                        className="unit-qr shrink-0"
                        style={{ width: symbol === "both" ? "22mm" : "28mm", height: symbol === "both" ? "22mm" : "28mm" }}
                    />
                )}
            </div>
            {showWeight && (
                <p className="mt-0.5 font-bold tabular-nums text-slate-900" style={{ fontSize: `${s.font}px` }}>
                    {unit.actual_weight ?? "—"} {unit.weight_uom || "KG"}
                </p>
            )}
            {showWarehouse && unit.warehouse_name && (
                <p className="text-[9px] text-slate-500">{unit.warehouse_name}</p>
            )}
            <p className="text-[9px] font-semibold tracking-wide text-slate-700">{code}</p>
        </div>
    );
}

export function UnitLabelGrid({
    units = [],
    size = "70x50",
    symbol = "qr",
    copies = 1,
    showWeight = true,
    showWarehouse = true,
}) {
    const labels = units.flatMap((unit) => Array.from({ length: copies }, () => unit));
    const cols = {
        "50x30": "repeat(4, 50mm)",
        "70x50": "repeat(3, 70mm)",
        "100x50": "repeat(2, 100mm)",
    };

    return (
        <div
            className="unit-label-grid"
            style={{
                display: "grid",
                gridTemplateColumns: cols[size] || cols["70x50"],
                gap: "2mm",
            }}
        >
            {labels.map((unit, index) => (
                <UnitLabel
                    key={`${unit.id}-${index}`}
                    unit={unit}
                    size={size}
                    symbol={symbol}
                    showWeight={showWeight}
                    showWarehouse={showWarehouse}
                />
            ))}
        </div>
    );
}
