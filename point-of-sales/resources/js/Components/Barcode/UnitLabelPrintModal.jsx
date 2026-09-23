import React, { useRef, useState } from "react";
import { UnitLabelGrid } from "./UnitLabel";
import { IconBarcode, IconPrinter, IconX } from "@tabler/icons-react";

export default function UnitLabelPrintModal({
    isOpen,
    onClose,
    units = [],
    title = "พิมพ์ป้ายหน่วย",
}) {
    const [size, setSize] = useState("70x50");
    const [copies, setCopies] = useState(1);
    const [showWeight, setShowWeight] = useState(true);
    const [showWarehouse, setShowWarehouse] = useState(true);
    const printRef = useRef(null);

    if (!isOpen) return null;

    const handlePrint = () => {
        const printContent = printRef.current;
        if (!printContent) return;

        const printWindow = window.open("", "_blank");
        printWindow.document.write(`
            <!DOCTYPE html>
            <html>
            <head>
                <title>Print Unit Labels</title>
                <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"><\/script>
                <style>
                    @page { size: A4; margin: 5mm; }
                    body { font-family: Arial, sans-serif; margin: 0; }
                    .unit-label-grid { display: grid; gap: 2mm; }
                    .unit-label {
                        border: 1px solid #ccc; padding: 2mm;
                        display: flex; flex-direction: column;
                        align-items: center; justify-content: center;
                        page-break-inside: avoid; background: white;
                    }
                </style>
            </head>
            <body>
                ${printContent.innerHTML}
                <script>
                    document.querySelectorAll('.unit-label svg').forEach(svg => {
                        const code = svg.getAttribute('data-code');
                        if (code) {
                            JsBarcode(svg, code, {
                                format: "CODE128",
                                width: 1.8,
                                height: 48,
                                displayValue: true,
                                fontSize: 11,
                                margin: 4,
                            });
                        }
                    });
                    setTimeout(() => { window.print(); window.close(); }, 400);
                <\/script>
            </body>
            </html>
        `);
        printWindow.document.close();
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div className="absolute inset-0 bg-black/50" onClick={onClose} />
            <div className="relative max-h-[90vh] w-full max-w-4xl overflow-hidden rounded-2xl bg-white shadow-2xl">
                <div className="flex items-center justify-between border-b border-slate-200 p-4">
                    <div className="flex items-center gap-2">
                        <IconBarcode size={22} className="text-primary-600" />
                        <h2 className="text-lg font-bold text-slate-800">{title}</h2>
                        <span className="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium">
                            {units.length} หน่วย
                        </span>
                    </div>
                    <button type="button" onClick={onClose} className="rounded-lg p-2 hover:bg-slate-100">
                        <IconX size={18} />
                    </button>
                </div>

                <div className="flex flex-wrap items-center gap-4 border-b border-slate-100 px-4 py-3 text-sm">
                    <label className="flex items-center gap-2">
                        ขนาด
                        <select
                            value={size}
                            onChange={(e) => setSize(e.target.value)}
                            className="rounded-lg border-slate-200 text-sm"
                        >
                            <option value="50x30">50×30 mm</option>
                            <option value="70x50">70×50 mm</option>
                            <option value="100x50">100×50 mm</option>
                        </select>
                    </label>
                    <label className="flex items-center gap-2">
                        จำนวนชุด
                        <input
                            type="number"
                            min={1}
                            max={20}
                            value={copies}
                            onChange={(e) => setCopies(Number(e.target.value) || 1)}
                            className="w-16 rounded-lg border-slate-200 text-sm"
                        />
                    </label>
                    <label className="flex items-center gap-2">
                        <input
                            type="checkbox"
                            checked={showWeight}
                            onChange={(e) => setShowWeight(e.target.checked)}
                        />
                        แสดงน้ำหนัก
                    </label>
                    <label className="flex items-center gap-2">
                        <input
                            type="checkbox"
                            checked={showWarehouse}
                            onChange={(e) => setShowWarehouse(e.target.checked)}
                        />
                        แสดงคลัง
                    </label>
                    <button
                        type="button"
                        onClick={handlePrint}
                        className="ml-auto inline-flex items-center gap-2 rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white"
                    >
                        <IconPrinter size={16} /> พิมพ์
                    </button>
                </div>

                <div className="max-h-[60vh] overflow-auto bg-slate-50 p-4">
                    <div ref={printRef}>
                        <UnitLabelGrid
                            units={units.map((u) => ({
                                ...u,
                                // for print window regeneration
                            }))}
                            size={size}
                            copies={copies}
                            showWeight={showWeight}
                            showWarehouse={showWarehouse}
                        />
                    </div>
                </div>
            </div>
        </div>
    );
}
