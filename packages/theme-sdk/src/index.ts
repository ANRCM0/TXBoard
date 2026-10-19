import { createCoreUserClient, type CoreUserClient, type CoreUserClientOptions } from '@txboard/core-sdk'

/** Theme packages declare this contract and the exact Core operations they consume. */
export const THEME_BROWSER_CONTRACT = 'core-browser/v1' as const

/** Uses the same validated browser transport and recovery rules as the first-party user app. */
export function createThemeClient(options: CoreUserClientOptions = {}): CoreUserClient {
  return createCoreUserClient(options)
}

export { CoreUserApiError } from '@txboard/core-sdk'
export type { CoreInput, CoreOutput, CoreMeta, CoreOperation, CoreUserOperation, CoreResult, CallOptions,
  CoreUserClient as ThemeClient, CoreUserClientOptions as ThemeClientOptions, CoreUserCapabilities } from '@txboard/core-sdk'
