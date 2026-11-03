import React, { useState } from "react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { Head, useForm, usePage, Link } from "@inertiajs/react";
import Input from "@/Components/Dashboard/Input";
import Textarea from "@/Components/Dashboard/TextArea";
import toast from "react-hot-toast";
import {
    IconCategory,
    IconDeviceFloppy,
    IconArrowLeft,
    IconPhoto,
} from "@tabler/icons-react";

function RetailEdit({ category }) {
    const { errors } = usePage().props;

    const { data, setData, post, processing } = useForm({
        id: category.id,
        name: category.name,
        description: category.description,
        image: "",
        _method: "PUT",
    });

    const [imagePreview, setImagePreview] = useState(category.image || null);

    const handleImageChange = (e) => {
        const file = e.target.files[0];
        if (file) {
            setData("image", file);
            setImagePreview(URL.createObjectURL(file));
        }
    };

    const submit = (e) => {
        e.preventDefault();
        post(route("categories.update", category.id), {
            onSuccess: () => toast.success("Kategori berhasil diperbarui"),
            onError: () => toast.error("Gagal memperbarui kategori"),
        });
    };

    return (
        <>
            <Head title="Edit Kategori" />

            <div className="mb-6">
                <Link
                    href={route("categories.index")}
                    className="inline-flex items-center gap-2 text-sm text-slate-500 hover:text-primary-600 mb-3"
                >
                    <IconArrowLeft size={16} />
                    Kembali ke Kategori
                </Link>
                <h1 className="text-2xl font-bold text-slate-900 dark:text-white flex items-center gap-2">
                    <IconCategory size={28} className="text-primary-500" />
                    Edit Kategori
                </h1>
                <p className="text-sm text-slate-500 mt-1">{category.name}</p>
            </div>

            <form onSubmit={submit}>
                <div className="max-w-2xl">
                    <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <h3 className="text-sm font-semibold text-slate-700 dark:text-slate-300 mb-3 flex items-center gap-2">
                                    <IconPhoto size={16} />
                                    Gambar
                                </h3>
                                <div className="aspect-video rounded-xl bg-slate-100 dark:bg-slate-800 border-2 border-dashed border-slate-300 dark:border-slate-700 flex items-center justify-center overflow-hidden mb-3">
                                    {imagePreview ? (
                                        <img
                                            src={imagePreview}
                                            alt="Preview"
                                            className="w-full h-full object-cover"
                                        />
                                    ) : (
                                        <IconPhoto
                                            size={32}
                                            className="text-slate-400"
                                        />
                                    )}
                                </div>
                                <Input
                                    type="file"
                                    onChange={handleImageChange}
                                    errors={errors.image}
                                    accept="image/*"
                                />
                            </div>

                            <div className="space-y-4">
                                <Input
                                    type="text"
                                    label="Nama Kategori"
                                    placeholder="Masukkan nama"
                                    errors={errors.name}
                                    onChange={(e) =>
                                        setData("name", e.target.value)
                                    }
                                    value={data.name}
                                />
                                <Textarea
                                    label="Deskripsi"
                                    placeholder="Deskripsi kategori"
                                    errors={errors.description}
                                    onChange={(e) =>
                                        setData("description", e.target.value)
                                    }
                                    value={data.description}
                                    rows={4}
                                />
                            </div>
                        </div>

                        <div className="flex justify-end gap-3 mt-6 pt-6 border-t border-slate-100 dark:border-slate-800">
                            <Link
                                href={route("categories.index")}
                                className="px-5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 font-medium transition-colors"
                            >
                                Batal
                            </Link>
                            <button
                                type="submit"
                                disabled={processing}
                                className="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-primary-500 hover:bg-primary-600 text-white font-medium transition-colors disabled:opacity-50"
                            >
                                <IconDeviceFloppy size={18} />
                                {processing
                                    ? "Menyimpan..."
                                    : "Simpan Perubahan"}
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </>
    );
}

function WarehouseEdit({ category }) {
    const { errors } = usePage().props;
    const { data, setData, post, processing } = useForm({
        name: category.name,
        _method: "PUT",
    });

    const submit = (e) => {
        e.preventDefault();
        post(route("categories.update", category.id), {
            onSuccess: () => toast.success("บันทึกชื่อหมวดแล้ว"),
            onError: () => toast.error("ยังบันทึกไม่ได้"),
        });
    };

    return (
        <>
            <Head title="แก้ชื่อหมวด" />
            <div className="mb-6">
                <Link href={route("categories.index")} className="inline-flex items-center gap-2 text-sm text-slate-500 hover:text-primary-600 mb-3">
                    <IconArrowLeft size={16} />
                    กลับไปหมวดวัตถุดิบ
                </Link>
                <h1 className="text-2xl font-bold text-slate-900 dark:text-white">แก้ชื่อหมวด</h1>
            </div>
            <form onSubmit={submit} className="max-w-lg">
                <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
                    <label className="block text-sm font-medium text-slate-700 dark:text-slate-300">
                        ชื่อหมวด
                        <input
                            className="mt-1 block w-full rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-2.5"
                            value={data.name}
                            onChange={(e) => setData("name", e.target.value)}
                        />
                        {errors.name && <span className="text-sm text-danger-500">{errors.name}</span>}
                    </label>
                    <div className="flex justify-end gap-3 mt-6">
                        <Link href={route("categories.index")} className="px-5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 text-slate-600">
                            ยกเลิก
                        </Link>
                        <button type="submit" disabled={processing} className="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-primary-500 text-white disabled:opacity-50">
                            <IconDeviceFloppy size={18} />
                            {processing ? "กำลังบันทึก..." : "บันทึก"}
                        </button>
                    </div>
                </div>
            </form>
        </>
    );
}

export default function Edit(props) {
    const isWarehouseMode = Boolean(usePage().props.isWarehouseMode);
    return isWarehouseMode ? <WarehouseEdit category={props.category} /> : <RetailEdit {...props} />;
}

Edit.layout = (page) => <DashboardLayout children={page} />;
