import { expect, test } from "@playwright/test";
import { credentials } from "../env.js";
import { expectUnitQty, OPERATOR, unitRow } from "./helpers.js";

// Tablet POS (/pos/rm, /pos/fg): PIN unlock, one POS per device, receive + cut on the floor.
test.describe.configure({ mode: "serial" });

/** Tap digits on the on-screen keypad (not the quick-amount buttons next to it). */
async function pressKeys(scope, digits) {
    const keypad = scope.getByRole("group", { name: "Keypad" });
    for (const digit of digits) {
        await keypad.getByRole("button", { name: digit, exact: true }).click();
    }
}

test("/pos opens POS RM in Thai even on an English device", async ({ page }) => {
    await page.goto("/pos");
    await expect(page).toHaveURL(/\/pos\/rm$/);
    await expect(page.getByRole("heading", { name: "POS RM วัตถุดิบ" })).toBeVisible();
    await expect(page.getByRole("button", { name: "ตกลง" })).toBeDisabled();
});

test("a wrong PIN is refused and cleared", async ({ page }) => {
    await page.goto("/pos/rm");
    await pressKeys(page, "0000");
    await expect(page.getByText("รหัสโกดังไม่ถูกต้อง")).toBeVisible();
    await expect(page.getByRole("heading", { name: "POS RM วัตถุดิบ" })).toBeVisible();
});

test("the 4th PIN digit unlocks by itself; the device then stays on its POS", async ({ page }) => {
    await page.goto("/pos/rm");
    await pressKeys(page, credentials.pins["WH-PHRANON"]);

    await expect(page.locator("header")).toContainText("RM วัตถุดิบ");
    await expect(page.locator("header")).toContainText("โกดังพระนอน");

    await page.goto("/pos/fg");
    await expect(page).toHaveURL(/\/pos\/rm$/);
});

test("a PIN typed on a USB keypad / keyboard unlocks too", async ({ page }) => {
    await page.goto("/pos/fg");
    await expect(page.getByRole("heading", { name: "POS FG สินค้าสำเร็จรูป" })).toBeVisible();
    await page.keyboard.type(credentials.pins["WH-DECHA-MESH"]);
    await expect(page.locator("header")).toContainText("FG สินค้าสำเร็จรูป");
    await expect(page.locator("header")).toContainText("เดชา / ผลิตตะแกรง");
});

test("receive on the tablet shows up in the cut list, then cut lowers it", async ({ page }) => {
    await page.goto("/pos/rm");
    await pressKeys(page, credentials.pins["WH-PHRANON"]);
    await expect(page.locator("header")).toContainText("โกดังพระนอน");

    // Receive 169 rolls of a wire that has no stock yet.
    await page.getByRole("button", { name: "รับเข้า", exact: true }).click();
    await page.locator('ul li button[data-sku="RM-W25-S"]').click();
    const sheet = page.locator("div.fixed.inset-0").last();
    await sheet.getByPlaceholder("เช่น สมชาย").fill(OPERATOR);
    await pressKeys(sheet, "169");
    await sheet.getByRole("button", { name: "ยืนยันรับเข้า" }).click();
    await expect(page.getByText("บันทึกรับเข้าแล้ว: 169 ROLL")).toBeVisible();
    await page.keyboard.press("Escape");
    await page.mouse.click(5, 5); // close the label-print sheet that opens after a receive
    await expect(page.locator("div.fixed.inset-0")).toHaveCount(0);

    await page.getByRole("button", { name: "ตัดออก", exact: true }).click();
    await expectUnitQty(page, "RM-W25-S", 169);

    await unitRow(page, "RM-W25-S").click();
    const cut = page.locator("div.fixed.inset-0").last();
    await pressKeys(cut, "19");
    await cut.getByRole("button", { name: "ยืนยันตัดออก" }).click();
    await expect(page.getByText("บันทึกตัดออกแล้ว: 19 ROLL")).toBeVisible();
    await expectUnitQty(page, "RM-W25-S", 150);

    // More than the roll holds: refused in the sheet, nothing moves.
    await unitRow(page, "RM-W25-S").click();
    const tooMuch = page.locator("div.fixed.inset-0").last();
    await pressKeys(tooMuch, "151");
    await tooMuch.getByRole("button", { name: "ยืนยันตัดออก" }).click();
    await expect(tooMuch.getByText("ตัดไม่ได้ 151 ROLL คงเหลือ 150")).toBeVisible();
    await tooMuch.getByRole("button").first().click(); // close the sheet
    await page.reload();
    await expectUnitQty(page, "RM-W25-S", 150);
});
