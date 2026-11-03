import { defineConfig, devices } from "@playwright/test";
import path from "node:path";
import { appRoot, baseURL, phpArgs, port, serverEnv } from "./env.js";

// Run with `npm run test:e2e` after `npm run build` (the app serves public/build).
export default defineConfig({
    testDir: "./tests",
    outputDir: "./test-results",
    // One shared database: tests run in order and assert on before/after values.
    fullyParallel: false,
    workers: 1,
    retries: process.env.CI ? 1 : 0,
    timeout: 60_000,
    expect: { timeout: 10_000 },
    reporter: [["list"], ["html", { outputFolder: "./playwright-report", open: "never" }]],
    globalSetup: "./global-setup.js",
    use: {
        baseURL,
        locale: "en-US", // the warehouse UI must stay Thai on an English device
        trace: "retain-on-failure",
        screenshot: "only-on-failure",
    },
    projects: [{ name: "chromium", use: { ...devices["Desktop Chrome"] } }],
    webServer: {
        command: [
            "php",
            ...phpArgs,
            "-S",
            `127.0.0.1:${port}`,
            "-t",
            ".",
            path.join(appRoot, "vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php"),
        ].join(" "),
        cwd: path.join(appRoot, "public"),
        env: serverEnv,
        // A static file: the server may come up before global-setup has created the database.
        url: `${baseURL}/robots.txt`,
        reuseExistingServer: false,
        timeout: 120_000,
    },
});
