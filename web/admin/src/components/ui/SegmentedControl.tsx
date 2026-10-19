export function SegmentedControl<T extends string | number>({ label, value, options, onChange }: {
  label: string
  value: T
  options: ReadonlyArray<{ value: T; label: string }>
  onChange: (value: T) => void
}) {
  return <div className="segmented-control" role="group" aria-label={label}>
    {options.map(option => <button type="button" key={option.value} aria-pressed={value === option.value}
      onClick={() => onChange(option.value)}>{option.label}</button>)}
  </div>
}
