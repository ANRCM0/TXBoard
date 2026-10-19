// Compatibility exports for dormant migrated pages. Active UI uses the SDK session.
export const getAccessToken = (): string | null => null
export const getAuthorizationHeader = (): string => ''
export const normalizeAuthorization = (_value?: string | null): string => ''
export function setAccessToken(_token: string): never {
  throw new Error('Use the management SDK session')
}
export function removeAccessToken(): void {}
