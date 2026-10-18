import { CORE_ADMIN_OPERATIONS } from './core-admin.generated.js'
export { CORE_ADMIN_OPERATIONS, CORE_ADMIN_READ_OPERATIONS, CORE_ADMIN_WRITE_OPERATIONS,
  type CoreAdminOperation, type CoreAdminReadOperation, type CoreAdminWriteOperation } from './core-admin.generated.js'
export const CORE_ADMIN_PREFIX = '/gateway/v1/core/admin'
import type { CoreAdminOperation } from './core-admin.generated.js'
const admitted = new Set<string>(CORE_ADMIN_OPERATIONS)
export const isCoreAdminOperation = (id: string): id is CoreAdminOperation => admitted.has(id)
