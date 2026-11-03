// Environment for the throw-away e2e app: its own SQLite file, its own port, no Spoolman, and
// test-only credentials. Real environment variables win over .env in Laravel, so the developer's
// .env (MySQL, https) is never used by these runs.
import path from "node:path";
import { fileURLToPath } from "node:url";

export const appRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
export const port = Number(process.env.E2E_PORT || 8010);
export const baseURL = `http://127.0.0.1:${port}`;

export const credentials = {
    // super-admin: the admin pages include users/roles/permissions, which warehouse-admins lack.
    adminEmail: "superadmin@wms.local",
    adminPassword: "e2e-admin-password",
    // PINs are exactly 4 digits; the 4th digit unlocks.
    pins: { "WH-PHRANON": "4321", "WH-DECHA-MESH": "4829" },
};

export const serverEnv = {
    ...process.env,
    APP_ENV: "e2e",
    APP_DEBUG: "true",
    APP_URL: baseURL,
    APP_MODE: "warehouse",
    DB_CONNECTION: "sqlite",
    DB_DATABASE: path.join(appRoot, "database", "e2e.sqlite"),
    SESSION_DRIVER: "file",
    SESSION_SECURE_COOKIE: "false",
    CACHE_STORE: "file",
    QUEUE_CONNECTION: "sync",
    MAIL_MAILER: "log",
    SPOOLMAN_ENABLED: "false",
    WMS_SUPERADMIN_PASSWORD: credentials.adminPassword,
    WMS_POS_PINS: Object.entries(credentials.pins).map(([code, pin]) => `${code}:${pin}`).join(","),
};

// Windows PHP builds often ship pdo_sqlite unloaded; E2E_PHP_ARGS lets CI pass nothing.
export const phpArgs = (process.env.E2E_PHP_ARGS ?? (process.platform === "win32"
    ? "-d extension=pdo_sqlite -d extension=sqlite3"
    : "")).split(" ").filter(Boolean);
