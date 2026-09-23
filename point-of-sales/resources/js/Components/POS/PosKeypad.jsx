import { IconBackspace } from "@tabler/icons-react";

const KEYS = ["1", "2", "3", "4", "5", "6", "7", "8", "9", ".", "0", "back"];

export default function PosKeypad({ value = "", onChange, disabled = false }) {
    function press(key) {
        if (disabled) return;
        if (key === "back") {
            onChange(value.slice(0, -1));
            return;
        }
        if (key === ".") {
            if (value.includes(".")) return;
            onChange(value === "" ? "0." : `${value}.`);
            return;
        }
        const next = value === "0" ? key : `${value}${key}`;
        const [whole, frac] = next.split(".");
        if (whole.length > 5) return;
        if (frac !== undefined && frac.length > 3) return;
        onChange(next);
    }

    return (
        <div className="grid grid-cols-3 gap-2" role="group" aria-label="Keypad">
            {KEYS.map((key) => (
                <button
                    key={key}
                    type="button"
                    disabled={disabled}
                    onClick={() => press(key)}
                    className="flex min-h-[3.5rem] items-center justify-center rounded-2xl border border-slate-200 bg-white text-2xl font-semibold tabular-nums active:bg-slate-100 disabled:opacity-40"
                >
                    {key === "back" ? <IconBackspace size={24} /> : key}
                </button>
            ))}
        </div>
    );
}
