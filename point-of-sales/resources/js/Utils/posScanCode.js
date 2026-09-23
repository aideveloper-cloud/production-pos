/**
 * Parse POS scan payloads into a lookup key.
 * Supports unit_code, SP-######, WEB+SPOOLMAN:S-<id>, /spool/show/<id>, bare spool id.
 */
export function parsePosScanCode(raw) {
    const code = String(raw || "").trim();
    if (!code) {
        return null;
    }

    const spoolman =
        code.match(/WEB\+SPOOLMAN:S-(\d+)/i) ||
        code.match(/\/spool\/show\/(\d+)/i) ||
        code.match(/^S-(\d+)$/i);
    if (spoolman) {
        return { type: "spoolman", spoolmanId: Number(spoolman[1]), raw: code };
    }

    const filament =
        code.match(/WEB\+SPOOLMAN:F-(\d+)/i) ||
        code.match(/\/filament\/show\/(\d+)/i);
    if (filament) {
        return { type: "filament", filamentId: Number(filament[1]), raw: code };
    }

    if (/^SP-\d+$/i.test(code)) {
        return { type: "unit_code", unitCode: code.toUpperCase(), raw: code };
    }

    if (/^\d{1,9}$/.test(code)) {
        return { type: "spoolman", spoolmanId: Number(code), raw: code };
    }

    return { type: "unit_code", unitCode: code.toUpperCase(), raw: code };
}

export function findUnitInList(units, parsed) {
    if (!parsed || !Array.isArray(units)) {
        return null;
    }

    if (parsed.type === "spoolman") {
        return (
            units.find((u) => Number(u.spoolman_spool_id) === parsed.spoolmanId) ||
            units.find(
                (u) =>
                    String(u.unit_code || "").toUpperCase() ===
                    `SP-${String(parsed.spoolmanId).padStart(6, "0")}`
            ) ||
            null
        );
    }

    if (parsed.type === "unit_code") {
        const needle = parsed.unitCode.toUpperCase();
        return (
            units.find((u) => String(u.unit_code || "").toUpperCase() === needle) ||
            null
        );
    }

    return null;
}

export function findProductInList(products, parsed, raw) {
    if (!Array.isArray(products)) {
        return null;
    }
    const needle = String(raw || parsed?.unitCode || "")
        .trim()
        .toUpperCase();
    if (!needle) {
        return null;
    }

    return (
        products.find((p) => String(p.sku || "").toUpperCase() === needle) ||
        products.find((p) => String(p.barcode || "").toUpperCase() === needle) ||
        null
    );
}
