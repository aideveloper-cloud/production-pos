import { expect, test } from "@playwright/test";
import { expectNoErrors, expectUnitQty, loginAsAdmin, OPERATOR, optionValue, unitQty, unitRow } from "./helpers.js";

// Admin "หน้างานคลัง" (/dashboard/warehouse-floor). Each test reproduces a bug that reached
// production: receives that silently never posted, stock landing on the wrong unit, items that
// looked missing because they sat in another warehouse.
test.describe.configure({ mode: "serial" });

test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto("/dashboard/warehouse-floor");
    await expect(page.getByRole("heading", { name: "พื้นสต็อก" })).toBeVisible();
});

async function receive(page, { sku, qty, unitCode }) {
    await page.getByRole("button", { name: "รับเข้า", exact: true }).click();
    const product = page.getByLabel("สินค้า");
    await product.selectOption(await optionValue(product, `${sku} —`));
    if (unitCode) await page.getByLabel("รหัสหน่วย (ไม่บังคับ)").fill(unitCode);
    await page.getByLabel("จำนวน / น้ำหนัก").fill(String(qty));
    await page.getByLabel("ชื่อผู้ตัด / ผู้รับ").fill(OPERATOR);
    await page.getByRole("button", { name: "ยืนยันรับเข้า" }).click();
}

test("opens on every warehouse and labels each unit with its warehouse", async ({ page }) => {
    await expect(page.getByRole("button", { name: "ทุกคลัง" })).toHaveClass(/bg-slate-900/);
    await expect(unitRow(page, "RM-W18-H")).toContainText("พระนอน");
    await expect(unitRow(page, "RM-W40-W")).toContainText("เดชา / ผลิตตะแกรง");
    await expect(unitRow(page, "RM-ROUND-2-2.8")).toContainText("เดชา / ผลิตเสา+ประตู");

    await page.getByRole("button", { name: "พระนอน", exact: true }).click();
    await expect(unitRow(page, "RM-W18-H")).toBeVisible();
    await expect(unitRow(page, "RM-W40-W")).toHaveCount(0);
});

test("receiving a ROLL item with no stock creates a unit named after the SKU", async ({ page }) => {
    expect(await unitQty(page, "RM-B40")).toBeNull(); // opening stock is 0

    await page.getByRole("button", { name: "รับเข้า", exact: true }).click();
    const product = page.getByLabel("สินค้า");
    await product.selectOption(await optionValue(product, "RM-B40 —"));
    // Warehouse and unit come from the product — the admin used to post KG for ROLL and be refused silently.
    await expect(page.getByLabel("รับเข้าคลัง").locator("option:checked")).toHaveText(/ผลิตตะแกรง/);
    await expect(page.locator("form").getByText("ROLL", { exact: true })).toBeVisible();
    await page.getByLabel("จำนวน / น้ำหนัก").fill("4");
    await page.getByLabel("ชื่อผู้ตัด / ผู้รับ").fill(OPERATOR);
    await page.getByRole("button", { name: "ยืนยันรับเข้า" }).click();

    await expect(page.getByText("บันทึกรับเข้าแล้ว: 4 ROLL")).toBeVisible();
    await expectNoErrors(page);
    await page.getByRole("button", { name: "ตัดออก", exact: true }).click();
    await expectUnitQty(page, "RM-B40", 4);
    await expect(unitRow(page, "RM-B40")).toContainText("เดชา / ผลิตตะแกรง");
});

test("receiving tops up the existing unit instead of adding a new PU code", async ({ page }) => {
    await expectUnitQty(page, "RM-W40-W", 90);
    await receive(page, { sku: "RM-W40-W", qty: 50 });
    await expect(page.getByText("บันทึกรับเข้าแล้ว: 50 ROLL")).toBeVisible();
    await expectNoErrors(page);

    await page.getByRole("button", { name: "ตัดออก", exact: true }).click();
    await expectUnitQty(page, "RM-W40-W", 140);
    await expect(page.locator('ul li button[data-unit-code^="PU-"]')).toHaveCount(0);
});

test("cutting a unit lowers it", async ({ page }) => {
    await unitRow(page, "RM-W40-W").click();
    await page.getByLabel("จำนวน", { exact: true }).fill("10");
    await page.getByLabel("ชื่อผู้ตัด / ผู้รับ").fill(OPERATOR);
    await page.getByRole("button", { name: "ยืนยันตัดออก" }).click();

    await expect(page.getByText("บันทึกตัดออกแล้ว: 10 ROLL")).toBeVisible();
    await expectUnitQty(page, "RM-W40-W", 130);
});

async function cut(page, unitCode, qty, { double = false } = {}) {
    await unitRow(page, unitCode).click();
    await page.getByLabel("จำนวน", { exact: true }).fill(String(qty));
    await page.getByLabel("ชื่อผู้ตัด / ผู้รับ").fill(OPERATOR);
    const confirm = page.getByRole("button", { name: "ยืนยันตัดออก" });
    if (double) await confirm.dblclick();
    else await confirm.click();
}

test("cutting 100 of 344 leaves exactly 244 — never the whole unit", async ({ page }) => {
    await expectUnitQty(page, "RM-W20-S", 344);
    await cut(page, "RM-W20-S", 100);
    await expect(page.getByText("บันทึกตัดออกแล้ว: 100 ROLL")).toBeVisible();
    await expectUnitQty(page, "RM-W20-S", 244);
});

test("cutting more than remains is refused and nothing moves", async ({ page }) => {
    await cut(page, "RM-W20-S", 245);
    await expect(page.getByText("ตัดไม่ได้ 245 ROLL คงเหลือ 244")).toBeVisible();
    await page.reload();
    await expectUnitQty(page, "RM-W20-S", 244);
});

test("a double click on confirm cuts once", async ({ page }) => {
    await cut(page, "RM-W20-S", 4, { double: true });
    await expect(page.getByText("บันทึกตัดออกแล้ว: 4 ROLL")).toBeVisible();
    await page.reload();
    await expectUnitQty(page, "RM-W20-S", 240);
});

test("cutting a unit down to zero empties it without going negative", async ({ page }) => {
    await expectUnitQty(page, "RM-SC075", 1);
    await cut(page, "RM-SC075", 1);
    await expect(page.getByText("บันทึกตัดออกแล้ว: 1 ROLL")).toBeVisible();
    await expect(unitRow(page, "RM-SC075")).toHaveCount(0); // depleted units leave the cut list

    // Receiving again brings the same unit back.
    await receive(page, { sku: "RM-SC075", qty: 2 });
    await expect(page.getByText("บันทึกรับเข้าแล้ว: 2 ROLL")).toBeVisible();
    await page.getByRole("button", { name: "ตัดออก", exact: true }).click();
    await expectUnitQty(page, "RM-SC075", 2);
});

test("a unit code that belongs to another product is refused with a visible error", async ({ page }) => {
    await receive(page, { sku: "RM-W25-S", qty: 25, unitCode: "RM-W18-H" });

    await expect(page.getByText(/รหัสหน่วย RM-W18-H เป็นของ RM-W18-H/)).toBeVisible();
    await page.getByRole("button", { name: "ตัดออก", exact: true }).click();
    await expectUnitQty(page, "RM-W18-H", 45); // untouched
    expect(await unitQty(page, "RM-W25-S")).toBeNull();
});

test("posted movements show on the dashboard and in the movement history", async ({ page }) => {
    await page.goto("/dashboard");
    const recent = page.locator("table").filter({ hasText: "RM-B40" });
    await expect(recent.locator("tr").filter({ hasText: "RM-B40" }).first()).toContainText(OPERATOR);
    await expect(recent.locator("tr").filter({ hasText: "RM-W40-W" }).first()).toBeVisible();

    await page.goto("/dashboard/stock-ledgers?domain=RM&search=RM-B40");
    await expect(page.locator("tbody tr").filter({ hasText: "RM-B40" }).first()).toContainText("+4");
});
