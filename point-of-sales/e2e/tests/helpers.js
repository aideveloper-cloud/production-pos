import { expect } from "@playwright/test";
import { credentials } from "../env.js";

export const OPERATOR = "เทสต์ อีทูอี";

export async function loginAsAdmin(page) {
    await page.goto("/login");
    await page.locator('input[name="email"]').fill(credentials.adminEmail);
    await page.locator('input[name="password"]').fill(credentials.adminPassword);
    // The login bot guard rejects forms submitted faster than a person could type.
    await page.waitForTimeout(2200);
    await page.locator('button[type="submit"]').click();
    await page.waitForURL(/\/dashboard/);
}

/** A unit row in a floor list (admin page or tablet), matched by its exact unit code. */
export function unitRow(page, unitCode) {
    return page.locator(`ul li button[data-unit-code="${unitCode}"]`);
}

/** The remaining qty a unit row shows, as a number (null when the row is not listed). */
export async function unitQty(page, unitCode) {
    const row = unitRow(page, unitCode);
    if ((await row.count()) === 0) return null;
    return Number((await row.first().locator("p.tabular-nums").first().innerText()).replace(/,/g, ""));
}

export async function expectUnitQty(page, unitCode, qty) {
    await expect.poll(() => unitQty(page, unitCode), { message: `${unitCode} should show ${qty}` }).toBe(qty);
}

/** Value of the <option> whose text contains `text` inside `select`. */
export async function optionValue(select, text) {
    const option = select.locator("option").filter({ hasText: text }).first();
    await expect(option).toHaveCount(1);
    return option.getAttribute("value");
}

/** Errors are rendered as rose text; none may be on screen after a successful post. */
export async function expectNoErrors(page) {
    await expect(page.locator(".text-rose-600, .text-rose-700")).toHaveCount(0);
}
