import { expect, test } from "@playwright/test";
import { loginAsAdmin } from "./helpers.js";

// Every page in the warehouse admin menu (plus the create forms staff use) must open without a
// server error, an error page or a JavaScript crash.
const PAGES = [
    "/dashboard",
    "/dashboard/warehouse-floor",
    "/dashboard/labels",
    "/dashboard/products",
    "/dashboard/products/create",
    "/dashboard/categories",
    "/dashboard/settings/units",
    "/dashboard/suppliers",
    "/dashboard/purchase-orders",
    "/dashboard/purchase-orders/create",
    "/dashboard/goods-receivings",
    "/dashboard/goods-receivings/create",
    "/dashboard/supplier-returns",
    "/dashboard/supplier-returns/create",
    "/dashboard/stock-ledgers",
    "/dashboard/stock-ledgers?domain=FG",
    "/dashboard/stock-transfers",
    "/dashboard/stock-transfers/create",
    "/dashboard/stock-opnames",
    "/dashboard/settings/warehouses",
    "/dashboard/settings/store",
    "/dashboard/users",
    "/dashboard/roles",
    "/dashboard/permissions",
    "/dashboard/audit-logs",
];

test("every admin page opens cleanly", async ({ page }) => {
    const crashes = [];
    page.on("pageerror", (error) => crashes.push(`${page.url()}: ${error.message}`));

    await loginAsAdmin(page);

    for (const url of PAGES) {
        await test.step(url, async () => {
            const response = await page.goto(url);
            expect(response?.status(), `${url} HTTP status`).toBeLessThan(400);
            await expect(page.locator("main, #app").first()).toBeVisible();
            await expect(page.getByText(/Server Error|เกิดข้อผิดพลาด|Whoops/)).toHaveCount(0);
        });
    }

    expect(crashes, crashes.join("\n")).toEqual([]);
});
