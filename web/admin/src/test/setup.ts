/**
 * Node 26 defines a `localStorage` global that shadows the jsdom implementation
 * and stays undefined unless the process is started with --localstorage-file.
 * The SPA code reads `localStorage` directly, so install a deterministic
 * in-memory Storage before any test imports an adapter.
 */
class MemoryStorage implements Storage {
  private store = new Map<string, string>()

  get length() {
    return this.store.size
  }

  clear() {
    this.store.clear()
  }

  getItem(key: string) {
    return this.store.has(key) ? this.store.get(key)! : null
  }

  key(index: number) {
    return Array.from(this.store.keys())[index] ?? null
  }

  removeItem(key: string) {
    this.store.delete(key)
  }

  setItem(key: string, value: string) {
    this.store.set(key, String(value))
  }
}

function installStorage(name: 'localStorage' | 'sessionStorage') {
  if (globalThis[name]) return
  const storage = new MemoryStorage()
  try {
    Object.defineProperty(globalThis, name, { value: storage, configurable: true, writable: true })
  } catch {
    ;(globalThis as unknown as Record<string, Storage>)[name] = storage
  }
}

installStorage('localStorage')
installStorage('sessionStorage')
