/** Roughly a tooltip's height: a point higher than this gets its tooltip below it instead of above. */
const TOOLTIP_FLIP_Y = 84

/**
 * Where a chart's tooltip goes, as a CSS transform from the hovered point, so
 * it stays inside the card: centred on the point, but beside it near either
 * edge (the outer 30% of the width); above the point, or below it when the
 * point is near the top. Without `y`, only the horizontal part (a tooltip at
 * a fixed height).
 */
export function tooltipTransform(x: number, width: number, y?: number): string {
  const horizontal = x < width * 0.3 ? '8px' : x > width * 0.7 ? 'calc(-100% - 8px)' : '-50%'
  if (y === undefined) return `translateX(${horizontal})`

  const vertical = y < TOOLTIP_FLIP_Y ? '8px' : 'calc(-100% - 4px)'
  return `translate(${horizontal}, ${vertical})`
}
