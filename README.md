# Production POS — Warehouse Stock App (RM) + Spoolman

ระบบคลังวัตถุดิบ (RM) ของ K Garden: รับเข้า / ตัด / โอนย้าย / ตรวจนับ แยก 4 คลัง
ใช้งานจริงที่ **https://pos.kgarden.co.th**

| โฟลเดอร์ | คืออะไร |
|---|---|
| `point-of-sales/` | แอป WMS (Laravel 13 + React/Inertia) — fork จาก [aryadwiputra/point-of-sales](https://github.com/aryadwiputra/point-of-sales) |
| `deploy/` | docker compose สำหรับ production (app, scheduler, MySQL, Spoolman, Cloudflare Tunnel) |
| `docker-compose.yml`, `Caddyfile`, `certs/` | สแตกสำหรับรันใน LAN / เครื่องพัฒนา (HTTPS ด้วยใบรับรองของเราเอง) |
| `docs/` | สเปก WMS + Spoolman integration |

Spoolman ใช้ image `ghcr.io/donkie/spoolman` ตรงๆ (fork: [aideveloper-cloud/Spoolman](https://github.com/aideveloper-cloud/Spoolman)) — ไม่ได้อยู่ใน repo นี้

## Mode

`APP_MODE=warehouse` เปลี่ยนแอปเป็น WMS แบบ slim:

- หน้างานคลัง `/pos` (แท็บเล็ต): รับเข้า (+) / ตัด (−) ปลดล็อกด้วย PIN คลัง
- เมนูคลัง RM: วัตถุดิบ, รับเข้า/จัดซื้อ, สต็อกคลัง, ระบบ
- เมนูขายปลีก (ขาย, CRM, ทานที่ร้าน, กะแคชเชียร์ ฯลฯ) ถูกซ่อนและ route ถูกบล็อก (404)
- Spoolman เป็นแค่ engine น้ำหนัก/spool — ห้ามให้พนักงานเข้า UI ของ Spoolman (ไม่มี auth)

## กติกาสต็อก (สำคัญสำหรับการพัฒนาต่อ)

`stock_ledgers` คือ source of truth ส่วน `product_warehouse.stock` / `products.stock` เป็น cache
ทุกการเคลื่อนไหวต้องผ่าน `InventoryPostingService` — ห้าม `increment('stock')` ตรงๆ
รายละเอียดดูใน [point-of-sales/AGENTS.md](point-of-sales/AGENTS.md) หัวข้อ *Inventory Model*

ตรวจว่าข้อมูลตรงกันได้ตลอด: `php artisan inventory:ledger-check`

## รันใน LAN / เครื่องพัฒนา

```bash
docker compose up -d            # MySQL, Spoolman, HTTPS proxy

cd point-of-sales
cp .env.example .env            # APP_MODE=warehouse, DB_*, SPOOLMAN_*, WMS_ADMIN_PASSWORD, WMS_POS_PINS
composer install
npm install && npm run build
php artisan key:generate
php artisan migrate
php artisan db:seed --class=CompanyUserSeeder
php artisan serve --host=0.0.0.0 --port=8000
```

| Service | URL |
|---------|-----|
| WMS HTTPS (แท็บเล็ต / PC) | `https://<server-lan-ip>` |
| โหลดใบรับรองครั้งแรก | `http://<server-lan-ip>/wms.crt` |
| WMS HTTP ภายในเครื่อง | `http://127.0.0.1:8000` |
| Spoolman API (WMS only) | `http://127.0.0.1:17912` |

แท็บเล็ตต้องเปิด **HTTPS** (กล้องสแกนใช้ได้เฉพาะ secure context) — ติดตั้ง/เชื่อถือ `wms.crt` ก่อน
ใบรับรองสร้างด้วย `certs/make-wms-cert.php` (`wms.key` ไม่อยู่ใน repo)

### บัญชีและ PIN

ไม่มีรหัสผ่านหรือ PIN ในโค้ด — ตั้งใน `.env` ก่อนรัน `CompanyUserSeeder`:

```dotenv
WMS_ADMIN_PASSWORD=...                                  # admin@wms.local
WMS_POS_PINS=WH-PHRANON:xxxxxx,WH-DECHA-MESH:xxxxxx,WH-DECHA-POST:xxxxxx,WH-CHOKDEE:xxxxxx
```

ถ้าไม่ได้ตั้ง seeder จะคงค่าเดิมไว้ และสุ่มค่าใหม่ให้เฉพาะที่ยังไม่มี (พิมพ์ออกหน้าจอครั้งเดียว)

## Production

ดู [deploy/README.md](deploy/README.md)

## หน้าหลัก

- `/pos` หรือ `/tablet` — เครื่อง POS แท็บเล็ต (ตัด / รับ เต็มจอ); ต้องใส่ **ชื่อผู้ทำรายการ** ทุกครั้ง
- `/login` — เข้าสู่ระบบแอดมิน
- `/dashboard` — ภาพรวมคลัง
- `/dashboard/stock-ledgers` — ความเคลื่อนไหวสต็อก (จาก ledger)
- `/dashboard/labels` — พิมพ์ป้ายหน่วย/ม้วน (บาร์โค้ด `unit_code` สแกนกลับเข้า POS ได้)
- `/api/integrations/spoolman/*` — Spoolman webhook/sync (Bearer token)
