import React, { useState } from "react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { Head, useForm, usePage, Link } from "@inertiajs/react";
import Button from "@/Components/Dashboard/Button";
import Input from "@/Components/Dashboard/Input";
import Textarea from "@/Components/Dashboard/TextArea";
import InputSelect from "@/Components/Dashboard/InputSelect";
import toast from "react-hot-toast";
import {
    IconPackage,
    IconDeviceFloppy,
    IconArrowLeft,
    IconPhoto,
    IconBarcode,
    IconCurrencyDollar,
    IconTrash,
    IconPackages,
    IconRulerMeasure,
} from "@tabler/icons-react";

function RetailCreate({ categories, products, units = [] }) {
    const { errors } = usePage().props;

    const { data, setData, post, processing } = useForm({
        image: "",
        barcode: "",
        sku: "",
        title: "",
        category_id: "",
        domain: "FG",
        base_uom: "PCS",
        description: "",
        buy_price: "",
        sell_price: "",
        stock: "",
        min_stock: "",
        max_stock: "",
        tax_type: "exclusive",
        tax_rate: "11",
        is_composite: false,
        components: [],
        units: [],
    });

    const [selectedCategory, setSelectedCategory] = useState(null);
    const [imagePreview, setImagePreview] = useState(null);

    const setSelectedCategoryHandler = (value) => {
        setSelectedCategory(value);
        setData("category_id", value?.id || "");
    };

    const toggleComposite = (checked) => {
        setData("is_composite", checked);
        if (checked) setData("units", []);
    };

    const addUnitRow = () => {
        const firstUnused = units.find(
            (u) => !data.units.some((row) => row.unit_id === u.id)
        );
        if (!firstUnused) return;
        setData("units", [
            ...data.units,
            {
                unit_id: firstUnused.id,
                is_base: data.units.length === 0,
                conversion_factor: data.units.length === 0 ? 1 : "",
                sell_price: "",
                barcode: "",
            },
        ]);
    };

    const updateUnitRow = (index, field, value) => {
        const rows = data.units.map((r, i) =>
            i === index ? { ...r, [field]: value } : r
        );
        if (field === "is_base" && value) {
            rows.forEach((r, i) => {
                if (i !== index) r.is_base = false;
            });
        }
        setData("units", rows);
    };

    const removeUnitRow = (index) => {
        setData("units", data.units.filter((_, i) => i !== index));
    };

    const availableUnits = (selectedIndex) =>
        units.filter(
            (u) =>
                !data.units.some(
                    (r, i) => i !== selectedIndex && r.unit_id === u.id
                )
        );

    const addComponent = () => {
        setData("components", [
            ...data.components,
            { component_product_id: "", qty: 1 },
        ]);
    };

    const updateComponent = (index, field, value) => {
        const components = data.components.map((c, i) =>
            i === index ? { ...c, [field]: value } : c
        );
        setData("components", components);
    };

    const removeComponent = (index) => {
        setData(
            "components",
            data.components.filter((_, i) => i !== index)
        );
    };

    const availableProducts = (selectedIndex) =>
        products.filter(
            (p) =>
                !p.is_composite &&
                !data.components.some(
                    (c, i) =>
                        i !== selectedIndex &&
                        c.component_product_id === p.id
                )
        );

    const estimatedSellPrice = data.is_composite
        ? data.components.reduce((total, c) => {
              const product = products.find(
                  (p) => p.id === Number(c.component_product_id)
              );
              return product
                  ? total + product.sell_price * (Number(c.qty) || 0)
                  : total;
          }, 0)
        : data.sell_price;

    const handleImageChange = (e) => {
        const file = e.target.files[0];
        if (file) {
            setData("image", file);
            setImagePreview(URL.createObjectURL(file));
        }
    };

    // ponytail: zero-out stock/sell_price for composite; collapse if Inertia ever accepts pre-send transforms
    const submitCompositeAware = (e) => {
        e.preventDefault();
        if (data.is_composite) {
            setData("sell_price", 0);
            setData("stock", 0);
        }
        post(route("products.store"), {
            onSuccess: () => toast.success("Produk berhasil ditambahkan"),
            onError: () => toast.error("Gagal menyimpan produk"),
        });
    };

    return (
        <>
            <Head title="Tambah Produk" />

            {/* Header */}
            <div className="mb-6">
                <Link
                    href={route("products.index")}
                    className="inline-flex items-center gap-2 text-sm text-slate-500 hover:text-primary-600 mb-3"
                >
                    <IconArrowLeft size={16} />
                    Kembali ke Produk
                </Link>
                <h1 className="text-2xl font-bold text-slate-900 dark:text-white flex items-center gap-2">
                    <IconPackage size={28} className="text-primary-500" />
                    Tambah Produk Baru
                </h1>
            </div>

            <form onSubmit={submitCompositeAware}>
                <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    {/* Left Column - Image */}
                    <div className="lg:col-span-1">
                        <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5">
                            <h3 className="text-sm font-semibold text-slate-700 dark:text-slate-300 mb-4 flex items-center gap-2">
                                <IconPhoto size={18} />
                                Gambar Produk
                            </h3>
                            <div className="aspect-square rounded-xl bg-slate-100 dark:bg-slate-800 border-2 border-dashed border-slate-300 dark:border-slate-700 flex items-center justify-center overflow-hidden mb-4">
                                {imagePreview ? (
                                    <img
                                        src={imagePreview}
                                        alt="Preview"
                                        className="w-full h-full object-cover"
                                    />
                                ) : (
                                    <div className="text-center p-6">
                                        <IconPhoto
                                            size={48}
                                            className="mx-auto text-slate-400 mb-2"
                                        />
                                        <p className="text-sm text-slate-500">
                                            Belum ada gambar
                                        </p>
                                    </div>
                                )}
                            </div>
                            <Input
                                type="file"
                                label="Upload Gambar"
                                onChange={handleImageChange}
                                errors={errors.image}
                                accept="image/*"
                            />
                        </div>
                    </div>

                    {/* Right Column - Form */}
                    <div className="lg:col-span-2 space-y-6">
                        {/* Basic Info */}
                        <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5">
                            <h3 className="text-sm font-semibold text-slate-700 dark:text-slate-300 mb-4 flex items-center gap-2">
                                <IconBarcode size={18} />
                                Informasi Dasar
                            </h3>
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <label className="text-sm text-slate-700 dark:text-slate-300">
                                    ประเภทสินค้า
                                    <select className="block w-full rounded-lg mt-2 dark:bg-slate-800" value={data.domain} onChange={(e) => setData("domain", e.target.value)}>
                                        <option value="RM">RM — วัตถุดิบ</option>
                                        <option value="FG">FG — สินค้าสำเร็จรูป</option>
                                    </select>
                                    {errors.domain && <span className="text-danger-500">{errors.domain}</span>}
                                </label>
                                <label className="text-sm text-slate-700 dark:text-slate-300">
                                    หน่วยสต็อกหลัก
                                    <select className="block w-full rounded-lg mt-2 dark:bg-slate-800" value={data.base_uom} onChange={(e) => setData("base_uom", e.target.value)}>
                                        {["PCS", "KG", "M", "ROLL", "BOX", "SET"].map((uom) => <option key={uom} value={uom}>{uom}</option>)}
                                    </select>
                                    {errors.base_uom && <span className="text-danger-500">{errors.base_uom}</span>}
                                </label>
                                <div className="md:col-span-2">
                                    <InputSelect
                                        label="Kategori"
                                        data={categories}
                                        selected={selectedCategory}
                                        setSelected={setSelectedCategoryHandler}
                                        placeholder="Pilih kategori"
                                        errors={errors.category_id}
                                        searchable={true}
                                        displayKey="name"
                                    />
                                </div>
                                <Input
                                    type="text"
                                    label="Barcode"
                                    value={data.barcode}
                                    onChange={(e) =>
                                        setData("barcode", e.target.value)
                                    }
                                    errors={errors.barcode}
                                    placeholder="Masukkan kode produk"
                                />
                                <Input
                                    type="text"
                                    label="SKU"
                                    value={data.sku}
                                    onChange={(e) => setData("sku", e.target.value)}
                                    errors={errors.sku}
                                    placeholder="Masukkan SKU unik"
                                />
                                <Input
                                    type="text"
                                    label="Nama Produk"
                                    value={data.title}
                                    onChange={(e) =>
                                        setData("title", e.target.value)
                                    }
                                    errors={errors.title}
                                    placeholder="Masukkan nama produk"
                                />
                                <div className="md:col-span-2">
                                    <Textarea
                                        label="Deskripsi"
                                        placeholder="Deskripsi produk (opsional)"
                                        errors={errors.description}
                                        onChange={(e) =>
                                            setData(
                                                "description",
                                                e.target.value
                                            )
                                        }
                                        value={data.description}
                                        rows={3}
                                    />
                                </div>
                            </div>
                        </div>

                        {/* Pricing & Stock */}
                        <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5">
                            <h3 className="text-sm font-semibold text-slate-700 dark:text-slate-300 mb-4 flex items-center gap-2">
                                <IconCurrencyDollar size={18} />
                                Harga & Stok
                            </h3>
                            <label className="flex items-center gap-2 mb-4 cursor-pointer">
                                <input
                                    type="checkbox"
                                    checked={data.is_composite}
                                    onChange={(e) =>
                                        toggleComposite(e.target.checked)
                                    }
                                    className="rounded border-slate-300 text-primary-600 focus:ring-primary-500"
                                />
                                <span className="text-sm font-medium text-slate-700 dark:text-slate-300 flex items-center gap-1">
                                    <IconPackages size={16} />
                                    Produk Komposit (bundling /
                                    paket)
                                </span>
                            </label>
                            <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <Input
                                    type="number"
                                    label="Harga Beli"
                                    value={data.buy_price}
                                    onChange={(e) =>
                                        setData("buy_price", e.target.value)
                                    }
                                    errors={errors.buy_price}
                                    placeholder="0"
                                />
                                <Input
                                    type="number"
                                    label="Harga Jual"
                                    value={data.is_composite ? "" : data.sell_price}
                                    disabled={data.is_composite}
                                    onChange={(e) =>
                                        setData("sell_price", e.target.value)
                                    }
                                    errors={errors.sell_price}
                                    placeholder={
                                        data.is_composite
                                            ? "Otomatis dari komponen"
                                            : "0"
                                    }
                                />
                                <Input
                                    type="number"
                                    label="Stok"
                                    value={data.is_composite ? "" : data.stock}
                                    disabled={data.is_composite}
                                    onChange={(e) =>
                                        setData("stock", e.target.value)
                                    }
                                    errors={errors.stock}
                                    placeholder={
                                        data.is_composite
                                            ? "Dari stok komponen"
                                            : "0"
                                    }
                                />
                            </div>

                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-4">
                                <Input
                                    type="number"
                                    label="Stok Minimum"
                                    value={data.min_stock}
                                    onChange={(e) =>
                                        setData("min_stock", e.target.value)
                                    }
                                    errors={errors.min_stock}
                                    placeholder="0"
                                />
                                <Input
                                    type="number"
                                    label="Stok Maksimum"
                                    value={data.max_stock}
                                    onChange={(e) =>
                                        setData("max_stock", e.target.value)
                                    }
                                    errors={errors.max_stock}
                                    placeholder="0"
                                />
                                <p className="sm:col-span-2 text-xs text-slate-500 dark:text-slate-400">
                                    Dipakai untuk reorder point: saat stok
                                    menyentuh minimum, draft purchase order
                                    otomatis dibuat oleh sistem (reorder:generate
                                    harian).
                                </p>
                            </div>

                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-4">
                                <div>
                                    <label className="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">
                                        Tipe Pajak
                                    </label>
                                    <div className="flex gap-4">
                                        <label className="flex items-center gap-2 text-sm">
                                            <input
                                                type="radio"
                                                name="tax_type"
                                                value="exclusive"
                                                checked={
                                                    data.tax_type ===
                                                    "exclusive"
                                                }
                                                onChange={(e) =>
                                                    setData(
                                                        "tax_type",
                                                        e.target.value
                                                    )
                                                }
                                            />
                                            Exclusive
                                        </label>
                                        <label className="flex items-center gap-2 text-sm">
                                            <input
                                                type="radio"
                                                name="tax_type"
                                                value="inclusive"
                                                checked={
                                                    data.tax_type ===
                                                    "inclusive"
                                                }
                                                onChange={(e) =>
                                                    setData(
                                                        "tax_type",
                                                        e.target.value
                                                    )
                                                }
                                            />
                                            Inclusive
                                        </label>
                                    </div>
                                    {errors.tax_type && (
                                        <p className="text-xs text-danger-500 mt-1">
                                            {errors.tax_type}
                                        </p>
                                    )}
                                </div>
                                <Input
                                    type="number"
                                    label="Persentase Pajak (%)"
                                    value={data.tax_rate}
                                    onChange={(e) =>
                                        setData("tax_rate", e.target.value)
                                    }
                                    errors={errors.tax_rate}
                                    placeholder="11"
                                />
                            </div>

                            {/* Profit Estimation */}
                            {data.buy_price > 0 && data.sell_price > 0 && (
                                <div className="mt-4 p-4 rounded-xl bg-success-50 dark:bg-success-950/30 border border-success-200 dark:border-success-900">
                                    <div className="flex items-center justify-between">
                                        <div>
                                            <p className="text-sm text-success-700 dark:text-success-400 font-medium">
                                                Estimasi Profit per Item
                                            </p>
                                            <p className="text-2xl font-bold text-success-600 dark:text-success-500 mt-1">
                                                + Rp{" "}
                                                {(
                                                    data.sell_price -
                                                    data.buy_price
                                                ).toLocaleString("id-ID")}
                                            </p>
                                        </div>
                                        <div className="text-right">
                                            <p className="text-sm text-success-700 dark:text-success-400 font-medium">
                                                Margin
                                            </p>
                                            <p className="text-xl font-bold text-success-600 dark:text-success-500 mt-1">
                                                {(
                                                    ((data.sell_price -
                                                        data.buy_price) /
                                                        data.buy_price) *
                                                    100
                                                ).toFixed(1)}
                                                %
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            )}
                        </div>

                        {/* Units */}
                        {!data.is_composite && (
                            <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5">
                                <div className="flex items-center justify-between mb-4">
                                    <h3 className="text-sm font-semibold text-slate-700 dark:text-slate-300 flex items-center gap-2">
                                        <IconRulerMeasure size={18} />
                                        Satuan
                                    </h3>
                                    <button
                                        type="button"
                                        onClick={addUnitRow}
                                        disabled={
                                            data.units.length >= units.length
                                        }
                                        className="text-sm text-primary-600 hover:text-primary-700 font-medium disabled:opacity-40"
                                    >
                                        + Tambah Satuan
                                    </button>
                                </div>
                                {errors.units && (
                                    <p className="text-sm text-danger-600 mb-2">
                                        {errors.units}
                                    </p>
                                )}
                                {data.units.length === 0 && (
                                    <p className="text-sm text-slate-500">
                                        Satuan dasar otomatis dibuat jika tidak
                                        ditambahkan. Tambahkan satuan (box, kg,
                                        dll) untuk penjualan multi-satuan.
                                    </p>
                                )}
                                <div className="space-y-3">
                                    {data.units.map((row, index) => (
                                        <div
                                            key={index}
                                            className="flex items-end gap-3"
                                        >
                                            <div className="flex-1">
                                                <InputSelect
                                                    data={availableUnits(index)}
                                                    selected={
                                                        units.find(
                                                            (u) =>
                                                                u.id ===
                                                                row.unit_id
                                                        ) || null
                                                    }
                                                    setSelected={(value) =>
                                                        updateUnitRow(
                                                            index,
                                                            "unit_id",
                                                            value?.id ?? ""
                                                        )
                                                    }
                                                    placeholder="Pilih satuan"
                                                    errors={
                                                        errors[
                                                            `units.${index}.unit_id`
                                                        ]
                                                    }
                                                    searchable={true}
                                                    displayKey="code"
                                                />
                                            </div>
                                            <div className="w-36">
                                                <Input
                                                    type="number"
                                                    min="0.0001"
                                                    step="0.0001"
                                                    value={
                                                        row.conversion_factor
                                                    }
                                                    onChange={(e) =>
                                                        updateUnitRow(
                                                            index,
                                                            "conversion_factor",
                                                            e.target.value
                                                        )
                                                    }
                                                    errors={
                                                        errors[
                                                            `units.${index}.conversion_factor`
                                                        ]
                                                    }
                                                    placeholder="Konversi"
                                                    disabled={row.is_base}
                                                />
                                            </div>
                                            <div className="w-40">
                                                <Input
                                                    type="number"
                                                    min="0"
                                                    value={row.sell_price}
                                                    onChange={(e) =>
                                                        updateUnitRow(
                                                            index,
                                                            "sell_price",
                                                            e.target.value
                                                        )
                                                    }
                                                    errors={
                                                        errors[
                                                            `units.${index}.sell_price`
                                                        ]
                                                    }
                                                    placeholder="Harga jual"
                                                />
                                            </div>
                                            <div className="w-44">
                                                <Input
                                                    type="text"
                                                    value={row.barcode}
                                                    onChange={(e) =>
                                                        updateUnitRow(
                                                            index,
                                                            "barcode",
                                                            e.target.value
                                                        )
                                                    }
                                                    errors={
                                                        errors[
                                                            `units.${index}.barcode`
                                                        ]
                                                    }
                                                    placeholder="Barcode (opsional)"
                                                />
                                            </div>
                                            <label className="flex items-center gap-1 pb-3 text-xs text-slate-600 dark:text-slate-300 whitespace-nowrap">
                                                <input
                                                    type="checkbox"
                                                    checked={row.is_base}
                                                    onChange={(e) =>
                                                        updateUnitRow(
                                                            index,
                                                            "is_base",
                                                            e.target.checked
                                                        )
                                                    }
                                                    className="rounded border-slate-300 text-primary-600 focus:ring-primary-500"
                                                />
                                                Dasar
                                            </label>
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    removeUnitRow(index)
                                                }
                                                className="p-2.5 rounded-xl text-danger-600 hover:bg-danger-50 dark:hover:bg-danger-950/30 transition-colors"
                                            >
                                                <IconTrash size={18} />
                                            </button>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        )}

                        {/* Composite Components */}
                        {data.is_composite && (
                            <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5">
                                <div className="flex items-center justify-between mb-4">
                                    <h3 className="text-sm font-semibold text-slate-700 dark:text-slate-300 flex items-center gap-2">
                                        <IconPackages size={18} />
                                        Komponen Paket
                                    </h3>
                                    <button
                                        type="button"
                                        onClick={addComponent}
                                        className="text-sm text-primary-600 hover:text-primary-700 font-medium"
                                    >
                                        + Tambah Komponen
                                    </button>
                                </div>
                                {errors.components && (
                                    <p className="text-sm text-danger-600 mb-2">
                                        {errors.components}
                                    </p>
                                )}
                                {data.components.length === 0 && (
                                    <p className="text-sm text-slate-500">
                                        Belum ada komponen. Stok dan harga jual
                                        dihitung otomatis dari komponen.
                                    </p>
                                )}
                                <div className="space-y-3">
                                    {data.components.map((component, index) => (
                                        <div
                                            key={index}
                                            className="flex items-end gap-3"
                                        >
                                            <div className="flex-1">
                                                <InputSelect
                                                    data={availableProducts(
                                                        index
                                                    )}
                                                    selected={
                                                        products.find(
                                                            (p) =>
                                                                p.id ===
                                                                Number(
                                                                    component.component_product_id
                                                                )
                                                        ) || null
                                                    }
                                                    setSelected={(
                                                        value
                                                    ) =>
                                                        updateComponent(
                                                            index,
                                                            "component_product_id",
                                                            value?.id ?? ""
                                                        )
                                                    }
                                                    placeholder="Pilih produk komponen"
                                                    errors={
                                                        errors[
                                                            `components.${index}.component_product_id`
                                                        ]
                                                    }
                                                    searchable={true}
                                                    displayKey="title"
                                                />
                                            </div>
                                            <div className="w-28">
                                                <Input
                                                    type="number"
                                                    min="1"
                                                    value={component.qty}
                                                    onChange={(e) =>
                                                        updateComponent(
                                                            index,
                                                            "qty",
                                                            e.target.value
                                                        )
                                                    }
                                                    errors={
                                                        errors[
                                                            `components.${index}.qty`
                                                        ]
                                                    }
                                                    placeholder="Qty"
                                                />
                                            </div>
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    removeComponent(index)
                                                }
                                                className="p-2.5 rounded-xl text-danger-600 hover:bg-danger-50 dark:hover:bg-danger-950/30 transition-colors"
                                            >
                                                <IconTrash size={18} />
                                            </button>
                                        </div>
                                    ))}
                                </div>
                                {data.is_composite && estimatedSellPrice > 0 && (
                                    <p className="mt-4 text-sm text-slate-500 dark:text-slate-400">
                                        Estimasi harga jual dari komponen:{" "}
                                        <span className="font-semibold text-slate-700 dark:text-slate-200">
                                            Rp{" "}
                                            {estimatedSellPrice.toLocaleString(
                                                "id-ID"
                                            )}
                                        </span>
                                    </p>
                                )}
                            </div>
                        )}

                        {/* Submit */}
                        <div className="flex justify-end gap-3">
                            <Link
                                href={route("products.index")}
                                className="px-6 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 font-medium transition-colors"
                            >
                                Batal
                            </Link>
                            <button
                                type="submit"
                                disabled={processing}
                                className="inline-flex items-center gap-2 px-6 py-2.5 rounded-xl bg-primary-500 hover:bg-primary-600 text-white font-medium transition-colors disabled:opacity-50"
                            >
                                <IconDeviceFloppy size={18} />
                                {processing ? "Menyimpan..." : "Simpan Produk"}
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </>
    );
}

const COUNT_UNITS = [
    { value: "เส้น", hint: "ท่อ เหล็กกลม แป๊บ" },
    { value: "ชิ้น", hint: "ฝา อุปกรณ์" },
    { value: "แผ่น", hint: "เพลท แผ่นเรียบ" },
    { value: "ม้วน", hint: "ลวด ตะแกรง" },
];

function WarehouseMaterialForm({ categories = [], warehouses = [] }) {
    const { errors } = usePage().props;
    const { data, setData, post, processing } = useForm({
        sku: "",
        title: "",
        category_id: "",
        category_name: "",
        count_unit: "เส้น",
        domain: "RM",
        stock: "",
        warehouse_id: warehouses.length === 1 ? String(warehouses[0].id) : "",
        weight_per_roll: "",
    });

    const submit = (e) => {
        e.preventDefault();
        post(route("products.store"), {
            onSuccess: () => toast.success("เพิ่มรายการแล้ว"),
            onError: () => toast.error("ยังบันทึกไม่ได้ ตรวจช่องที่มีข้อความสีแดง"),
        });
    };

    const field = "mt-1 block w-full rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-2.5 text-slate-900 dark:text-white";

    return (
        <>
            <Head title="เพิ่มรายการ" />
            <div className="mb-6">
                <Link
                    href={route("products.index")}
                    className="inline-flex items-center gap-2 text-sm text-slate-500 hover:text-primary-600 mb-3"
                >
                    <IconArrowLeft size={16} />
                    กลับไปรายการสินค้า
                </Link>
                <h1 className="text-2xl font-bold text-slate-900 dark:text-white">
                    เพิ่มรายการเข้าโกดัง
                </h1>
                <p className="mt-1 text-sm text-slate-500">
                    ใส่จำนวนที่มีอยู่ตอนนี้ รายการจะพร้อมตัดบนแท็บเล็ตทันที รหัสสินค้าใช้สแกนได้
                </p>
            </div>

            <form onSubmit={submit} className="max-w-2xl space-y-5">
                <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 space-y-4">
                    <div>
                        <p className="text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">ประเภท</p>
                        <div className="grid grid-cols-2 gap-2">
                            {[
                                { value: "RM", label: "RM", hint: "วัตถุดิบ" },
                                { value: "FG", label: "FG", hint: "สินค้าสำเร็จรูป" },
                            ].map((item) => (
                                <button
                                    key={item.value}
                                    type="button"
                                    onClick={() => setData("domain", item.value)}
                                    className={`rounded-xl border px-3 py-3 text-left ${data.domain === item.value ? "border-primary-500 bg-primary-50 dark:bg-primary-950/40" : "border-slate-200 dark:border-slate-700"}`}
                                >
                                    <span className="block text-base font-semibold text-slate-900 dark:text-white">{item.label}</span>
                                    <span className="block text-xs text-slate-500">{item.hint}</span>
                                </button>
                            ))}
                        </div>
                        {errors.domain && <span className="text-sm text-danger-500">{errors.domain}</span>}
                    </div>
                    <label className="block text-sm font-medium text-slate-700 dark:text-slate-300">
                        ชื่อรายการ
                        <input
                            className={field}
                            value={data.title}
                            onChange={(e) => setData("title", e.target.value)}
                            placeholder='เช่น ท่อกลม Ø2" หนา 2.0 มม.'
                        />
                        {errors.title && <span className="text-sm text-danger-500">{errors.title}</span>}
                    </label>

                    <label className="block text-sm font-medium text-slate-700 dark:text-slate-300">
                        รหัสสินค้า
                        <input
                            className={field}
                            value={data.sku}
                            onChange={(e) => setData("sku", e.target.value.trim())}
                            placeholder="RM-ROUND-2-2.0"
                        />
                        {errors.sku && <span className="text-sm text-danger-500">{errors.sku}</span>}
                    </label>

                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <label className="block text-sm font-medium text-slate-700 dark:text-slate-300">
                            หมวด
                            <select
                                className={field}
                                value={data.category_id}
                                onChange={(e) => setData({ ...data, category_id: e.target.value, category_name: "" })}
                            >
                                <option value="">เลือกหมวดที่มีอยู่</option>
                                {categories.map((category) => (
                                    <option key={category.id} value={category.id}>{category.name}</option>
                                ))}
                            </select>
                        </label>
                        <label className="block text-sm font-medium text-slate-700 dark:text-slate-300">
                            หรือหมวดใหม่
                            <input
                                className={field}
                                value={data.category_name}
                                onChange={(e) => setData({ ...data, category_name: e.target.value, category_id: "" })}
                                placeholder="เช่น เพลทนูน"
                            />
                        </label>
                    </div>
                    {(errors.category_id || errors.category_name) && (
                        <span className="text-sm text-danger-500">{errors.category_id || errors.category_name}</span>
                    )}

                    <div>
                        <p className="text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">หน่วยนับ</p>
                        <div className="grid grid-cols-2 sm:grid-cols-4 gap-2">
                            {COUNT_UNITS.map((unit) => (
                                <button
                                    key={unit.value}
                                    type="button"
                                    onClick={() => setData("count_unit", unit.value)}
                                    className={`rounded-xl border px-3 py-3 text-left ${
                                        data.count_unit === unit.value
                                            ? "border-primary-500 bg-primary-50 dark:bg-primary-950/40"
                                            : "border-slate-200 dark:border-slate-700"
                                    }`}
                                >
                                    <span className="block text-base font-semibold text-slate-900 dark:text-white">{unit.value}</span>
                                    <span className="block text-xs text-slate-500">{unit.hint}</span>
                                </button>
                            ))}
                        </div>
                        {errors.count_unit && <span className="text-sm text-danger-500">{errors.count_unit}</span>}
                    </div>

                    {data.count_unit === "ม้วน" && (
                        <label className="block text-sm font-medium text-slate-700 dark:text-slate-300">
                            น้ำหนักต่อม้วน (กก.) ไม่ใส่ก็ได้
                            <input
                                type="number"
                                min="0"
                                step="0.01"
                                className={field}
                                value={data.weight_per_roll}
                                onChange={(e) => setData("weight_per_roll", e.target.value)}
                                placeholder="เช่น 25"
                            />
                            <span className="mt-1 block text-xs text-slate-500">ถ้าใส่ ตอนตัดจะคิดน้ำหนักจากจำนวนม้วน</span>
                            {errors.weight_per_roll && <span className="text-sm text-danger-500">{errors.weight_per_roll}</span>}
                        </label>
                    )}
                </div>

                <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 space-y-4">
                    <label className="block text-sm font-medium text-slate-700 dark:text-slate-300">
                        โกดัง
                        <select
                            className={field}
                            value={data.warehouse_id}
                            onChange={(e) => setData("warehouse_id", e.target.value)}
                        >
                            <option value="">เลือกโกดัง</option>
                            {warehouses.map((warehouse) => (
                                <option key={warehouse.id} value={warehouse.id}>{warehouse.name}</option>
                            ))}
                        </select>
                        {errors.warehouse_id && <span className="text-sm text-danger-500">{errors.warehouse_id}</span>}
                    </label>
                    <label className="block text-sm font-medium text-slate-700 dark:text-slate-300">
                        จำนวนที่มีอยู่ตอนนี้
                        <input
                            type="number"
                            min="0"
                            step="1"
                            className={field}
                            value={data.stock}
                            onChange={(e) => setData("stock", e.target.value)}
                            placeholder="0"
                        />
                        <span className="mt-1 block text-xs text-slate-500">มากกว่า 0 จะขึ้นในรายการตัดของโกดังนี้ทันที</span>
                        {errors.stock && <span className="text-sm text-danger-500">{errors.stock}</span>}
                    </label>
                </div>

                <div className="flex justify-end gap-3">
                    <Link
                        href={route("products.index")}
                        className="px-6 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-400 font-medium"
                    >
                        ยกเลิก
                    </Link>
                    <button
                        type="submit"
                        disabled={processing}
                        className="inline-flex items-center gap-2 px-6 py-2.5 rounded-xl bg-primary-500 hover:bg-primary-600 text-white font-medium disabled:opacity-50"
                    >
                        <IconDeviceFloppy size={18} />
                        {processing ? "กำลังบันทึก..." : "บันทึกรายการ"}
                    </button>
                </div>
            </form>
        </>
    );
}

export default function Create(props) {
    const isWarehouseMode = Boolean(usePage().props.isWarehouseMode);
    if (isWarehouseMode) {
        return <WarehouseMaterialForm categories={props.categories} warehouses={props.warehouses || []} />;
    }

    return <RetailCreate {...props} />;
}

Create.layout = (page) => <DashboardLayout children={page} />;
