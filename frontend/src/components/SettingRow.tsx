import type { ReactNode } from 'react'
import { Label } from '@/components/ui/label'

/** One setting: what it is on the left, its control on the right (under it on phones). */
export default function SettingRow({ label, htmlFor, children }: { label: string; htmlFor?: string; children: ReactNode }) {
  return (
    <div className="flex items-center justify-between gap-4 py-3 first:pt-0 sm:gap-6">
      <Label htmlFor={htmlFor} className="text-sm font-medium">
        {label}
      </Label>
      {children}
    </div>
  )
}
