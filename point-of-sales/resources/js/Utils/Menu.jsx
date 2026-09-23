import { usePage } from "@inertiajs/react";
import { useTranslation } from "react-i18next";
import {
    IconBooks,
    IconBox,
    IconCategory,
    IconChartArrowsVertical,
    IconChartBar,
    IconChartBarPopular,
    IconChartInfographic,
    IconCirclePlus,
    IconClockHour6,
    IconClipboardCheck,
    IconCreditCard,
    IconCrown,
    IconFileCertificate,
    IconFileDescription,
    IconFolder,
    IconGift,
    IconLayout2,
    IconBuildingStore,
    IconSchool,
    IconShoppingCart,
    IconTable,
    IconUserBolt,
    IconUserShield,
    IconUserSquare,
    IconUsers,
    IconUsersPlus,
    IconFileInvoice,
    IconBuildingWarehouse,
    IconBarcode,
IconRulerMeasure,
    IconCurrencyDollar,
    IconWallet,
    IconFileSearch,
    IconTruckDelivery,
    IconTruckReturn,
    IconSpeakerphone,
    IconArrowsLeftRight,
    IconAlertCircle,
    IconListDetails,
    IconBrandWhatsapp,
    IconToolsKitchen2,
} from "@tabler/icons-react";
import hasAnyPermission from "./Permission";
import React from "react";

export default function Menu() {
    const { t } = useTranslation();
    const { url, props } = usePage();
    const isWarehouseMode = Boolean(props.isWarehouseMode);

    if (isWarehouseMode) {
        return warehouseMenu(t, url);
    }

    // define menu navigations
    const menuNavigation = [
        {
            titleKey: "sidebar.sections.overview",
            title: t("sidebar.sections.overview"),
            details: [
                {
                    title: t("sidebar.items.dashboard"),
                    href: route("dashboard"),
                    active: url === "/dashboard" ? true : false,
                    icon: <IconLayout2 size={20} strokeWidth={1.5} />,
                    permissions: hasAnyPermission(["dashboard-access"]),
                },
            ],
        },
        {
            titleKey: "sidebar.sections.masterData",
            title: t("sidebar.sections.masterData"),
            details: [
                {
                    title: t("sidebar.items.categories"),
                    href: route("categories.index"),
                    active: url === "/dashboard/categories" ? true : false,
                    icon: <IconFolder size={20} strokeWidth={1.5} />,
                    permissions: hasAnyPermission(["categories-access"]),
                },
                {
                    title: t("sidebar.items.products"),
                    href: route("products.index"),
                    active: url === "/dashboard/products" ? true : false,
                    icon: <IconBox size={20} strokeWidth={1.5} />,
                    permissions: hasAnyPermission(["products-access"]),
                },
                {
                    title: t("sidebar.items.customers"),
                    href: route("customers.index"),
                    active: url === "/dashboard/customers" ? true : false,
                    icon: <IconUsersPlus size={20} strokeWidth={1.5} />,
                    permissions: hasAnyPermission(["customers-access"]),
                },
                {
                    title: t("sidebar.items.suppliers"),
                    href: route("suppliers.index"),
                    active: url.startsWith("/dashboard/suppliers"),
                    icon: <IconBuildingWarehouse size={20} strokeWidth={1.5} />,
                    permissions: hasAnyPermission(["suppliers-access"]),
                },
            ],
        },
        {
            titleKey: "sidebar.sections.sales",
            title: t("sidebar.sections.sales"),
            details: [
                {
                    title: t("sidebar.items.transactions"),
                    href: route("transactions.index"),
                    active: url === "/dashboard/transactions" ? true : false,
                    icon: <IconShoppingCart size={20} strokeWidth={1.5} />,
                    permissions: hasAnyPermission(["transactions-access"]),
                },
                {
                    title: t("sidebar.items.transactionHistory"),
                    href: route("transactions.history"),
                    active: url === "/dashboard/transactions/history" ? true : false,
                    icon: <IconClockHour6 size={20} strokeWidth={1.5} />,
                    permissions: hasAnyPermission(["transactions-access"]),
                },
                {
                    title: t("sidebar.items.salesReturns"),
                    href: route("sales-returns.index"),
                    active: url.startsWith("/dashboard/sales-returns"),
                    icon: <IconFileCertificate size={20} strokeWidth={1.5} />,
                    permissions: hasAnyPermission(["sales-returns-access"]),
                },
                {
                    title: t("sidebar.items.receivables"),
                    href: route("receivables.index"),
                    active: url.startsWith("/dashboard/receivables"),
                    icon: <IconFileInvoice size={20} strokeWidth={1.5} />,
                    permissions: hasAnyPermission(["receivables-access"]),
                },
                {
                    title: t("sidebar.items.agingReminders"),
                    href: route("aging.index"),
                    active: url.startsWith("/dashboard/aging"),
                    icon: <IconChartBar size={20} strokeWidth={1.5} />,
                    permissions: hasAnyPermission(["receivables-access"]),
                },
            ],
        },
        {
            titleKey: "sidebar.sections.approval",
            title: t("sidebar.sections.approval"),
            details: [
                {
                    title: t("sidebar.items.discountApproval"),
                    href: route("discount-approvals.pending"),
                    active: url.startsWith("/dashboard/discount-approvals"),
                    icon: <IconAlertCircle size={20} strokeWidth={1.5} />,
                    permissions: hasAnyPermission(["discounts-approve"]),
                },
            ],
        },
        {
            title: t("sidebar.sections.inventory"),
            titleKey: "sidebar.sections.inventory",
            details: [
                {
                    title: t("sidebar.items.stockOpname"),
                    href: route("stock-opnames.index"),
                    active: url.startsWith("/dashboard/stock-opnames"),
                    icon: <IconFileDescription size={20} strokeWidth={1.5} />,
                    permissions: hasAnyPermission(["stock-opnames-access"]),
                },
                {
                    title: t("sidebar.items.stockMutations"),
                    href: route("stock-mutations.index"),
                    active: url.startsWith("/dashboard/stock-mutations"),
                    icon: <IconChartArrowsVertical size={20} strokeWidth={1.5} />,
                    permissions: hasAnyPermission(["stock-mutations-access"]),
                },
                {
                    title: t("sidebar.items.stockTransfers"),
                    href: route("stock-transfers.index"),
                    active: url.startsWith("/dashboard/stock-transfers"),
                    icon: <IconArrowsLeftRight size={20} strokeWidth={1.5} />,
                    permissions: hasAnyPermission(["stock-transfers-access"]),
                },
            ],
        },
        {
            title: t("sidebar.sections.procurement"),
            titleKey: "sidebar.sections.procurement",
            details: [
                {
                    title: t("sidebar.items.purchaseOrders"),
                    href: route("purchase-orders.index"),
                    active: url.startsWith("/dashboard/purchase-orders"),
                    icon: <IconClipboardCheck size={20} strokeWidth={1.5} />,
                    permissions: hasAnyPermission(["purchase-orders-access"]),
                },
                {
                    title: t("sidebar.items.goodsReceiving"),
                    href: route("goods-receivings.index"),
                    active: url.startsWith("/dashboard/goods-receivings"),
                    icon: <IconTruckDelivery size={20} strokeWidth={1.5} />,
                    permissions: hasAnyPermission(["goods-receivings-access"]),
                },
                {
                    title: t("sidebar.items.supplierReturns"),
                    href: route("supplier-returns.index"),
                    active: url.startsWith("/dashboard/supplier-returns"),
                    icon: <IconTruckReturn size={20} strokeWidth={1.5} />,
                    permissions: hasAnyPermission(["supplier-returns-access"]),
                },
                {
                    title: t("sidebar.items.supplierPayables"),
                    href: route("payables.index"),
                    active: url.startsWith("/dashboard/payables"),
                    icon: <IconCurrencyDollar size={20} strokeWidth={1.5} />,
                    permissions: hasAnyPermission(["payables-access"]),
                },
            ],
        },
        {
            title: t("sidebar.sections.crmPricing"),
            titleKey: "sidebar.sections.crmPricing",
            details: [
                {
                    title: t("sidebar.items.members"),
                    href: route("members.index"),
                    active: url.startsWith("/dashboard/members"),
                    icon: <IconCrown size={20} strokeWidth={1.5} />,
                    permissions: hasAnyPermission(["customers-access"]),
                },
                {
                    title: t("sidebar.items.pricePromos"),
                    href: route("pricing-rules.index"),
                    active: url.startsWith("/dashboard/pricing-rules"),
                    icon: <IconChartInfographic size={20} strokeWidth={1.5} />,
                    permissions: hasAnyPermission(["pricing-rules-access"]),
                },
                {
                    title: t("sidebar.items.customerVouchers"),
                    href: route("customer-vouchers.index"),
                    active: url.startsWith("/dashboard/customer-vouchers"),
                    icon: <IconCreditCard size={20} strokeWidth={1.5} />,
                    permissions: hasAnyPermission(["customer-vouchers-access"]),
                },
                {
                    title: t("sidebar.items.customerSegments"),
                    href: route("customer-segments.index"),
                    active: url.startsWith("/dashboard/customer-segments"),
                    icon: <IconUsers size={20} strokeWidth={1.5} />,
                    permissions: hasAnyPermission(["customer-segments-access"]),
                },
                {
                    title: t("sidebar.items.crmCampaigns"),
                    href: route("crm-campaigns.index"),
                    active: url.startsWith("/dashboard/crm-campaigns"),
                    icon: <IconSpeakerphone size={20} strokeWidth={1.5} />,
                    permissions: hasAnyPermission(["crm-campaigns-access"]),
                },
                {
                    title: t("sidebar.items.crmReminders"),
                    href: route("crm-reminders.index"),
                    active: url.startsWith("/dashboard/crm-reminders"),
                    icon: <IconClockHour6 size={20} strokeWidth={1.5} />,
                    permissions: hasAnyPermission(["crm-reminders-access"]),
                },
            ],
        },
        {
            title: t("sidebar.sections.dineIn"),
            titleKey: "sidebar.sections.dineIn",
            details: [
                {
                    title: t("sidebar.items.dineAreas"),
                    href: route("dine-areas.index"),
                    active: url.startsWith("/dashboard/dine-areas"),
                    icon: <IconFolder size={20} strokeWidth={1.5} />,
                    permissions: hasAnyPermission(["dine-tables-access"]),
                },
                {
                    title: t("sidebar.items.dineTables"),
                    href: route("dine-tables.index"),
                    active: url.startsWith("/dashboard/dine-tables"),
                    icon: <IconTable size={20} strokeWidth={1.5} />,
                    permissions: hasAnyPermission(["dine-tables-access"]),
                },
                {
                    title: t("sidebar.items.dineOrders"),
                    href: route("dine-orders.index"),
                    active: url.startsWith("/dashboard/dine-orders"),
                    icon: <IconToolsKitchen2 size={20} strokeWidth={1.5} />,
                    permissions: hasAnyPermission(["dine-orders-access"]),
                },
            ],
        },
        {
            title: t("sidebar.sections.reports"),
            titleKey: "sidebar.sections.reports",
            details: [
                {
                    title: t("sidebar.items.salesReport"),
                    href: route("reports.sales.index"),
                    active: url.startsWith("/dashboard/reports/sales"),
                    icon: <IconChartArrowsVertical size={20} strokeWidth={1.5} />,
                    permissions: hasAnyPermission(["reports-access"]),
                },
                {
                    title: t("sidebar.items.profitReport"),
                    href: route("reports.profits.index"),
                    active: url.startsWith("/dashboard/reports/profits"),
                    icon: <IconChartBarPopular size={20} strokeWidth={1.5} />,
                    permissions: hasAnyPermission(["profits-access"]),
                },
                {
                    title: t("sidebar.items.advancedInsights"),
                    href: route("reports.insights.index"),
                    active: url.startsWith("/dashboard/reports/insights"),
                    icon: <IconChartBar size={20} strokeWidth={1.5} />,
                    permissions: hasAnyPermission(["reports-access"]),
                },
            ],
        },
        {
            title: t("sidebar.sections.operations"),
            titleKey: "sidebar.sections.operations",
            details: [
                {
                    title: t("sidebar.items.cashierShifts"),
                    href: route("cashier-shifts.index"),
                    active: url.startsWith("/dashboard/cashier-shifts"),
                    icon: <IconWallet size={20} strokeWidth={1.5} />,
                    permissions: hasAnyPermission(["cashier-shifts-access"]),
                },
                {
                    title: t("sidebar.items.auditLogs"),
                    href: route("audit-logs.index"),
                    active: url.startsWith("/dashboard/audit-logs"),
                    icon: <IconFileSearch size={20} strokeWidth={1.5} />,
                    permissions: hasAnyPermission(["audit-logs-access"]),
                },
            ],
        },
        {
            title: t("sidebar.sections.userManagement"),
            titleKey: "sidebar.sections.userManagement",
            details: [
                {
                    title: t("sidebar.items.permissions"),
                    href: route("permissions.index"),
                    active: url === "/dashboard/permissions" ? true : false,
                    icon: <IconUserBolt size={20} strokeWidth={1.5} />,
                    permissions: hasAnyPermission(["permissions-access"]),
                },
                {
                    title: t("sidebar.items.roles"),
                    href: route("roles.index"),
                    active: url === "/dashboard/roles" ? true : false,
                    icon: <IconUserShield size={20} strokeWidth={1.5} />,
                    permissions: hasAnyPermission(["roles-access"]),
                },
                {
                    title: t("sidebar.items.users"),
                    icon: <IconUsers size={20} strokeWidth={1.5} />,
                    permissions: hasAnyPermission(["users-access"]),
                    subdetails: [
                        {
                            title: t("sidebar.items.usersList"),
                            href: route("users.index"),
                            icon: <IconTable size={20} strokeWidth={1.5} />,
                            active: url === "/dashboard/users" ? true : false,
                            permissions: hasAnyPermission(["users-access"]),
                        },
                        {
                            title: t("sidebar.items.usersCreate"),
                            href: route("users.create"),
                            icon: <IconCirclePlus size={20} strokeWidth={1.5} />,
                            active: url === "/dashboard/users/create" ? true : false,
                            permissions: hasAnyPermission(["users-create"]),
                        },
                    ],
                },
            ],
        },
        {
            title: t("sidebar.sections.settings"),
            titleKey: "sidebar.sections.settings",
            details: [
                {
                    title: t("sidebar.items.paymentGateway"),
                    href: route("settings.payments.edit"),
                    active: url === "/dashboard/settings/payments",
                    icon: <IconCreditCard size={20} strokeWidth={1.5} />,
                    permissions: hasAnyPermission(["payment-settings-access"]),
                },
                {
                    title: t("sidebar.items.storeProfile"),
                    href: route("settings.store"),
                    active: url === "/dashboard/settings/store",
                    icon: <IconBuildingStore size={20} strokeWidth={1.5} />,
                    permissions: hasAnyPermission(["dashboard-access"]),
                },
                {
                    title: t("sidebar.items.bankAccounts"),
                    href: route("settings.bank-accounts.index"),
                    active: url === "/dashboard/settings/bank-accounts",
                    icon: <IconCreditCard size={20} strokeWidth={1.5} />,
                    permissions: hasAnyPermission(["payment-settings-access"]),
                },
                {
                    title: t("sidebar.items.loyalty"),
                    href: route("settings.loyalty"),
                    active: url === "/dashboard/settings/loyalty",
                    icon: <IconGift size={20} strokeWidth={1.5} />,
                    permissions: hasAnyPermission(["dashboard-access"]),
                },
                {
                    title: t("sidebar.items.salesTarget"),
                    href: route("settings.target"),
                    active: url === "/dashboard/settings/target",
                    icon: <IconChartInfographic size={20} strokeWidth={1.5} />,
                    permissions: hasAnyPermission(["dashboard-access"]),
                },
                {
                    title: t("sidebar.items.priceLists"),
                    href: route("price-lists.index"),
                    active: url.startsWith("/dashboard/settings/price-lists"),
                    icon: <IconListDetails size={20} strokeWidth={1.5} />,
                    permissions: hasAnyPermission(["price-lists-access"]),
                },
                {
                    title: t("sidebar.items.warehouses"),
                    href: route("settings.warehouses.index"),
                    active: url === "/dashboard/settings/warehouses",
                    icon: <IconBuildingWarehouse size={20} strokeWidth={1.5} />,
                    permissions: hasAnyPermission(["warehouses-access"]),
                },
                {
                    title: t("sidebar.items.outlets"),
                    href: route("settings.outlets.index"),
                    active: url === "/dashboard/settings/outlets",
                    icon: <IconBuildingStore size={20} strokeWidth={1.5} />,
                    permissions: hasAnyPermission(["outlets-access"]),
                },
                {
                    title: t("sidebar.items.units"),
                    href: route("settings.units.index"),
                    active: url === "/dashboard/settings/units",
                    icon: <IconRulerMeasure size={20} strokeWidth={1.5} />,
                    permissions: hasAnyPermission(["units-access"]),
                },
                {
                    title: t("sidebar.items.whatsApp"),
                    href: route("settings.whatsapp"),
                    active: url === "/dashboard/settings/whatsapp",
                    icon: <IconBrandWhatsapp size={20} strokeWidth={1.5} />,
                    permissions: hasAnyPermission(["whatsapp-settings-access"]),
                },
            ],
        },
    ];

    return menuNavigation;
}

// RM (raw material) warehouse menu for APP_MODE=warehouse, ordered by the floor workflow:
// receive → store → cut/issue → count. Retail sections are not listed at all.
function warehouseMenu(t, url) {
    const w = (key) => t(`warehouse.menu.${key}`);
    const icon = (Icon) => <Icon size={20} strokeWidth={1.5} />;

    return [
        {
            titleKey: "warehouse.menu.sections.overview",
            title: w("sections.overview"),
            details: [
                {
                    title: w("dashboard"),
                    href: route("dashboard"),
                    active: url === "/dashboard",
                    icon: icon(IconLayout2),
                    permissions: hasAnyPermission(["dashboard-access"]),
                },
                {
                    title: w("stockFloor"),
                    href: route("pos.index"),
                    active: url.startsWith("/pos") || url.startsWith("/dashboard/warehouse-floor"),
                    icon: icon(IconBuildingWarehouse),
                    permissions: hasAnyPermission(["stock-mutations-access"]),
                },
                {
                    title: w("labels"),
                    href: route("labels.index"),
                    active: url.startsWith("/dashboard/labels"),
                    icon: icon(IconBarcode),
                    permissions: hasAnyPermission(["stock-mutations-access"]),
                },
            ],
        },
        {
            titleKey: "warehouse.menu.sections.materials",
            title: w("sections.materials"),
            details: [
                {
                    title: w("materials"),
                    href: route("products.index"),
                    active: url.startsWith("/dashboard/products"),
                    icon: icon(IconBox),
                    permissions: hasAnyPermission(["products-access"]),
                },
                {
                    title: w("materialGroups"),
                    href: route("categories.index"),
                    active: url.startsWith("/dashboard/categories"),
                    icon: icon(IconFolder),
                    permissions: hasAnyPermission(["categories-access"]),
                },
                {
                    title: w("units"),
                    href: route("settings.units.index"),
                    active: url === "/dashboard/settings/units",
                    icon: icon(IconRulerMeasure),
                    permissions: hasAnyPermission(["units-access"]),
                },
                {
                    title: w("suppliers"),
                    href: route("suppliers.index"),
                    active: url.startsWith("/dashboard/suppliers"),
                    icon: icon(IconTruckDelivery),
                    permissions: hasAnyPermission(["suppliers-access"]),
                },
            ],
        },
        {
            titleKey: "warehouse.menu.sections.inbound",
            title: w("sections.inbound"),
            details: [
                {
                    title: w("purchaseOrders"),
                    href: route("purchase-orders.index"),
                    active: url.startsWith("/dashboard/purchase-orders"),
                    icon: icon(IconClipboardCheck),
                    permissions: hasAnyPermission(["purchase-orders-access"]),
                },
                {
                    title: w("goodsReceiving"),
                    href: route("goods-receivings.index"),
                    active: url.startsWith("/dashboard/goods-receivings"),
                    icon: icon(IconTruckDelivery),
                    permissions: hasAnyPermission(["goods-receivings-access"]),
                },
                {
                    title: w("supplierReturns"),
                    href: route("supplier-returns.index"),
                    active: url.startsWith("/dashboard/supplier-returns"),
                    icon: icon(IconTruckReturn),
                    permissions: hasAnyPermission(["supplier-returns-access"]),
                },
            ],
        },
        {
            titleKey: "warehouse.menu.sections.stock",
            title: w("sections.stock"),
            details: [
                {
                    // Ledger-based history (stock_mutations only holds the retail modules' log).
                    title: w("stockMovements"),
                    href: route("stock-ledgers.index"),
                    active: url.startsWith("/dashboard/stock-ledgers"),
                    icon: icon(IconChartArrowsVertical),
                    permissions: hasAnyPermission(["stock-mutations-access"]),
                },
                {
                    title: w("stockTransfers"),
                    href: route("stock-transfers.index"),
                    active: url.startsWith("/dashboard/stock-transfers"),
                    icon: icon(IconArrowsLeftRight),
                    permissions: hasAnyPermission(["stock-transfers-access"]),
                },
                {
                    title: w("stockCount"),
                    href: route("stock-opnames.index"),
                    active: url.startsWith("/dashboard/stock-opnames"),
                    icon: icon(IconFileDescription),
                    permissions: hasAnyPermission(["stock-opnames-access"]),
                },
            ],
        },
        {
            titleKey: "warehouse.menu.sections.system",
            title: w("sections.system"),
            details: [
                {
                    title: w("warehouses"),
                    href: route("settings.warehouses.index"),
                    active: url === "/dashboard/settings/warehouses",
                    icon: icon(IconBuildingWarehouse),
                    permissions: hasAnyPermission(["warehouses-access"]),
                },
                {
                    title: w("company"),
                    href: route("settings.store"),
                    active: url === "/dashboard/settings/store",
                    icon: icon(IconBuildingStore),
                    permissions: hasAnyPermission(["dashboard-access"]),
                },
                {
                    title: w("users"),
                    href: route("users.index"),
                    active: url.startsWith("/dashboard/users"),
                    icon: icon(IconUsers),
                    permissions: hasAnyPermission(["users-access"]),
                },
                {
                    title: w("roles"),
                    href: route("roles.index"),
                    active: url === "/dashboard/roles",
                    icon: icon(IconUserShield),
                    permissions: hasAnyPermission(["roles-access"]),
                },
                {
                    title: w("permissions"),
                    href: route("permissions.index"),
                    active: url === "/dashboard/permissions",
                    icon: icon(IconUserBolt),
                    permissions: hasAnyPermission(["permissions-access"]),
                },
                {
                    title: w("auditLogs"),
                    href: route("audit-logs.index"),
                    active: url.startsWith("/dashboard/audit-logs"),
                    icon: icon(IconFileSearch),
                    permissions: hasAnyPermission(["audit-logs-access"]),
                },
            ],
        },
    ];
}
