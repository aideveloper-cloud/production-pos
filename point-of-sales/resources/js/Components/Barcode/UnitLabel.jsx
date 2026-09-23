import React, { useEffect, useRef } from "react";
import JsBarcode from "jsbarcode";

/**
 * Warehouse physical-unit label (unit_code barcode + optional Spoolman QR text).
 */
export default function UnitLabel({
    unit,
    size = "70x50",
    showWeight = true,
    showWarehouse = true,
}) {
    const barcodeRef = useRef(null);

    const sizes = {
        "50x30": { width: "50mm", height: "30mm", bw: 1.4, bh: 36, font: 10 },
        "70x50": { width: "70mm", height: "50mm", bw: 1.8, bh: 48, font: 12 },
        "100x50": { width: "100mm", height: "50mm", bw: 2.2, bh: 56, font: 13 },
    };
    const s = sizes[size] || sizes["70x50"];
    const code = unit?.unit_code || "";

    useEffect(() => {
        if (!barcodeRef.current || !code) return;
        try {
            JsBarcode(barcodeRef.current, code, {
                format: "CODE128",
                width: s.bw,
                height: s.bh,
                displayValue: true,
                fontSize: 11,
                margin: 4,
                background: "#ffffff",
            });
        } catch (e) {
            console.error("Unit barcode error:", e);
        }
    }, [code, s.bw, s.bh]);

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
            <svg ref={barcodeRef} className="max-w-full" data-code={code} />
            {showWeight && (
                <p className="mt-0.5 font-bold tabular-nums text-slate-900" style={{ fontSize: `${s.font}px` }}>
                    {unit.actual_weight ?? "—"} {unit.weight_uom || "KG"}
                </p>
            )}
            {showWarehouse && unit.warehouse_name && (
                <p className="text-[9px] text-slate-500">{unit.warehouse_name}</p>
            )}
            {unit.spoolman_spool_id ? (
                <p className="text-[8px] text-slate-400">WEB+SPOOLMAN:S-{unit.spoolman_spool_id}</p>
            ) : null}
        </div>
    );
}

export function UnitLabelGrid({
    units = [],
    size = "70x50",
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
                    showWeight={showWeight}
                    showWarehouse={showWarehouse}
                />
            ))}
        </div>
    );
}
