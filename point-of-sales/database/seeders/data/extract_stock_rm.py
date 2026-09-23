import json
import math
import re
import sys
from collections import Counter
from pathlib import Path

import pandas as pd

# Usage: python extract_stock_rm.py "<path to Stock RM.xlsx>" — the JSON output is company data and stays out of git.
SOURCE = Path(sys.argv[1]) if len(sys.argv) > 1 else Path("Stock RM.xlsx")
DEST = Path(__file__).with_name("stock-rm.json")

SHEET_WH = {
    "พระนอน": "WH-PHRANON",
    "ตะแกรง เดชา": "WH-DECHA-MESH",
    "โชคดี": "WH-CHOKDEE",
    "งานเสา+ประตู เดชา": "WH-DECHA-POST",
}


def num(v):
    if v is None or (isinstance(v, float) and math.isnan(v)):
        return None
    if isinstance(v, str):
        s = v.strip().replace(",", "")
        if s in ("", "-", "—", "–"):
            return None
        try:
            return float(s)
        except ValueError:
            return None
    if isinstance(v, (int, float)):
        return float(v)
    return None


def text(v):
    if v is None or (isinstance(v, float) and math.isnan(v)):
        return None
    s = str(v).strip()
    return s or None


def main() -> None:
    xl = pd.ExcelFile(SOURCE)
    rows_out = []

    for sheet in xl.sheet_names:
        df = pd.read_excel(xl, sheet_name=sheet, header=None)
        header_idx = None
        stock_col = None
        sku_col = None
        for i, row in df.iterrows():
            vals = [text(x) for x in row.tolist()]
            for j, v in enumerate(vals):
                if v and ("Stock ปัจจุบัน" in v or v == "จำนวนคงเหลือ"):
                    header_idx = i
                    stock_col = j
                    break
            if header_idx is not None:
                headers = [text(x) for x in df.iloc[header_idx].tolist()]
                for j, v in enumerate(headers):
                    if v and "รหัส" in v:
                        sku_col = j
                        break
                break
        if header_idx is None:
            raise SystemExit(f"No stock header in {sheet}")

        headers = [text(x) for x in df.iloc[header_idx].tolist()]
        for i in range(header_idx + 1, len(df)):
            row = df.iloc[i].tolist()
            sku = text(row[sku_col] if sku_col is not None else None)
            if not sku or not re.match(r"^RM-", sku):
                continue
            mapped = {headers[j]: text(row[j]) for j in range(len(headers)) if headers[j]}
            rows_out.append(
                {
                    "warehouse_code": SHEET_WH[sheet],
                    "sheet": sheet,
                    "sku": sku,
                    "stock": num(row[stock_col]) if stock_col is not None else 0 or 0,
                    "type": mapped.get("ประเภทวัตถุดิบ") or mapped.get("ประเภทลวด"),
                    "name": mapped.get("รายการ")
                    or mapped.get("รายการ / Spec")
                    or mapped.get("ลักษณะ")
                    or mapped.get("ลักษณะลวด"),
                    "size": mapped.get("ขนาด")
                    or mapped.get("ขนาดลวด (มม.)")
                    or mapped.get("ขนาด (มม.)"),
                    "thickness": mapped.get("ความหนา (มม.)"),
                    "characteristic": mapped.get("ลักษณะ") or mapped.get("ลักษณะลวด"),
                    "uom": mapped.get("หน่วย"),
                    "weight_per_roll": num(mapped.get("น้ำหนัก/ม้วน (กก.)")),
                    "note": mapped.get("หมายเหตุ"),
                }
            )
            if rows_out[-1]["stock"] is None:
                rows_out[-1]["stock"] = 0

    DEST.write_text(json.dumps(rows_out, ensure_ascii=False, indent=2), encoding="utf-8")
    print(f"wrote {len(rows_out)} rows -> {DEST}")
    print(dict(Counter(r["warehouse_code"] for r in rows_out)))


if __name__ == "__main__":
    main()
