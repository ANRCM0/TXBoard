import type * as Monaco from 'monaco-editor/editor'
import { preflightLocalEditorAssets } from './localEditorAssets'

let loading: Promise<typeof Monaco> | undefined

/** All editor code, languages and workers resolve to this build's local assets. */
export function loadLocalCodeEditor(): Promise<typeof Monaco> {
  if (!loading) {
    loading = (async () => {
      if (import.meta.env.PROD) await preflightLocalEditorAssets(import.meta.env.BASE_URL)
      const [editorWorker, jsonWorker, htmlWorker, cssWorker] = await Promise.all([
        import('monaco-editor/editor/editor.worker?worker'),
        import('monaco-editor/language/json/json.worker?worker'),
        import('monaco-editor/language/html/html.worker?worker'),
        import('monaco-editor/language/css/css.worker?worker'),
      ])
      const environment = globalThis as typeof globalThis & { MonacoEnvironment?: Monaco.Environment }
      environment.MonacoEnvironment = {
        getWorker(_moduleId, label) {
          if (label === 'json') return new jsonWorker.default()
          if (['html', 'handlebars', 'razor'].includes(label)) return new htmlWorker.default()
          if (['css', 'scss', 'less'].includes(label)) return new cssWorker.default()
          return new editorWorker.default()
        },
      }
      const monaco = await import('monaco-editor/editor')
      await Promise.all([
        import('monaco-editor/features/find/register'),
        import('monaco-editor/features/bracketMatching/register'),
        import('monaco-editor/features/wordOperations/register'),
        import('monaco-editor/languages/features/json/register'),
        import('monaco-editor/languages/features/html/register'),
        import('monaco-editor/languages/features/css/register'),
        import('monaco-editor/languages/definitions/yaml/register'),
        import('monaco-editor/languages/definitions/ini/register'),
        import('monaco-editor/languages/definitions/html/register'),
      ])
      const json = await import('monaco-editor/languages/features/json/register')
      json.jsonDefaults.setDiagnosticsOptions({ validate: true, enableSchemaRequest: false })
      return monaco
    })().catch(error => { loading = undefined; throw error })
  }
  return loading
}
