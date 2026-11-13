const tones = {
    positive: 'border-emerald-200 bg-emerald-50 text-emerald-800',
    critical: 'border-rose-200 bg-rose-50 text-rose-800',
    attention: 'border-amber-200 bg-amber-50 text-amber-900',
    info: 'border-sky-200 bg-sky-50 text-sky-800',
    neutral: 'border-slate-200 bg-slate-100 text-slate-700',
};

export function checkInStatusClass(tone) {
    return tones[tone] ?? tones.neutral;
}
