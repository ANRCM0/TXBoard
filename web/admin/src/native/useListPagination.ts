import { useState } from 'react'

export const pageSizes = [10, 20, 50, 100] as const

export function readPageSize(value: string | null, maximum = 100): number {
  const size = Number(value)
  return size <= maximum && pageSizes.some(option => option === size) ? size : 20
}

export function useListPagination(maximum = 100) {
  const [page, setPage] = useState(1)
  const [perPage, setSize] = useState(20)
  function setPerPage(size: number) {
    setSize(readPageSize(String(size), maximum))
    setPage(1)
  }
  return { page, setPage, perPage, setPerPage }
}
