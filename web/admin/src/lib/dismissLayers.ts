type Layer = { element: HTMLElement; dismiss: () => void }
const layers: Layer[] = []

function escape(event: KeyboardEvent) {
  if (event.key !== 'Escape' || event.isComposing) return
  const top = [...layers].reverse().find(layer => layer.element.isConnected && !layer.element.closest('[inert], [hidden]'))
  if (!top) return
  event.preventDefault()
  event.stopImmediatePropagation()
  top.dismiss()
}

/** Dialogs and their nonmodal children share Escape ownership without moving DOM branches. */
export function registerDismissLayer(element: HTMLElement, dismiss: () => void) {
  const layer = { element, dismiss }
  if (!layers.length) document.addEventListener('keydown', escape, true)
  const child = layers.findIndex(candidate => element.contains(candidate.element))
  if (child < 0) layers.push(layer)
  else layers.splice(child, 0, layer)
  return () => {
    const index = layers.indexOf(layer)
    if (index >= 0) layers.splice(index, 1)
    if (!layers.length) document.removeEventListener('keydown', escape, true)
  }
}

export function dismissChildLayers(parent: HTMLElement) {
  for (const layer of [...layers].reverse()) if (layer.element !== parent && parent.contains(layer.element)) layer.dismiss()
}
