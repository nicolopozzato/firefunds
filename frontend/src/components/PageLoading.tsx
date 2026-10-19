/** While a page's code loads (pages are loaded when first opened, App.tsx). */
export default function PageLoading() {
  return (
    <div className="flex justify-center py-24" role="status" aria-live="polite">
      <div className="h-8 w-8 animate-spin rounded-full border-b-2 border-foreground" />
    </div>
  )
}
