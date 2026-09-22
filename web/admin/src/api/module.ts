import { apiClient } from './client'
import { unwrap } from '../lib/api'

export type ModuleType = 'core' | 'plugin' | 'theme' | 'integration' | 'provider' | 'agent'
export type ModuleSource = 'system' | 'bundled' | 'user' | 'external'
export type ModuleHealth =
  | 'healthy'
  | 'degraded'
  | 'disabled'
  | 'failed'
  | 'incompatible'
  | 'missing_dependency'

export type ModuleAdminNavigation = {
  id: string
  title: string
  path: string
  icon?: string
  order?: number
}

export type ModuleDescriptor = {
  id: string
  name: string
  version: string
  description?: string
  author?: string
  type: ModuleType
  source: ModuleSource
  installed: boolean
  enabled: boolean
  active: boolean | null
  health: ModuleHealth
  health_details?: {
    checks: Record<string, boolean | null>
    observed_at: number
  }
  capabilities: string[]
  compatibility: {
    txboard: string
  }
  admin?: {
    navigation: ModuleAdminNavigation[]
  }
}

export type ModuleDiscoveryError = {
  adapter: string
  module_id?: string | null
  message: string
}

export type ModuleRegistrySummary = {
  total: number
  health: Record<ModuleHealth, number>
  discovery_errors: number
}

export type ModuleRegistrySnapshot = {
  modules: ModuleDescriptor[]
  errors: ModuleDiscoveryError[]
  summary: ModuleRegistrySummary
}

export async function getModuleRegistry() {
  const { data } = await apiClient.get('/module')
  return unwrap<ModuleRegistrySnapshot>(data)
}
