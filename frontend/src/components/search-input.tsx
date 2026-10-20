import { useEffect, useState } from 'react'
import { Search } from 'lucide-react'
import { Input } from '@/components/ui/input'
import { useDebouncedValue } from '@/hooks/use-debounced-value'

/** Debounced search box bound to the list's URL state. */
export function SearchInput({ value, onChange, label }: { value: string; onChange: (value: string) => void; label: string }) {
  const [draft, setDraft] = useState(value)
  const [synced, setSynced] = useState(value)
  const debounced = useDebouncedValue(draft)

  // External change (e.g. "Clear filters"): adopt it during render rather than in an effect.
  if (value !== synced) {
    setSynced(value)
    setDraft(value)
  }

  useEffect(() => {
    if (debounced !== value) {
      onChange(debounced)
    }
    // Only react to the debounced draft; external `value` changes are adopted above.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debounced])

  return (
    <div className="relative w-full sm:max-w-xs">
      <Search
        className="text-muted-foreground pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2"
        aria-hidden
      />
      <Input
        type="search"
        value={draft}
        onChange={(event) => setDraft(event.target.value)}
        placeholder={label}
        aria-label={label}
        className="pl-8"
      />
    </div>
  )
}
