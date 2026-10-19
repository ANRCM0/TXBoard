import { createCoreAdminSession, type CoreAdminSessionOptions } from '@txboard/core-sdk'

export { CoreAdminApiError, StaleAdminContext } from '@txboard/core-sdk'
export type AdminApiOptions = CoreAdminSessionOptions

/** Application entry point for the accepted administrator transport and session lifecycle. */
export const createAdminApi = (options: AdminApiOptions = {}) => createCoreAdminSession(options)
export type AdminApi = ReturnType<typeof createAdminApi>
