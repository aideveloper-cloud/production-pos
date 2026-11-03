import { execFileSync } from "node:child_process";
import fs from "node:fs";
import path from "node:path";
import { appRoot, phpArgs, serverEnv } from "./env.js";

// Fresh database for every run: migrations + the production company seeders (E2eSeeder).
export default function globalSetup() {
    const db = serverEnv.DB_DATABASE;
    fs.rmSync(db, { force: true });
    fs.writeFileSync(db, "");

    execFileSync(
        "php",
        [...phpArgs, path.join(appRoot, "artisan"), "migrate:fresh", "--force", "--seed", "--seeder=E2eSeeder"],
        { cwd: appRoot, env: serverEnv, stdio: "inherit" }
    );
}
